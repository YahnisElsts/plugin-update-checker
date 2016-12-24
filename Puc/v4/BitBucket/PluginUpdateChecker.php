<?php
if ( !class_exists('Puc_v4_BitBucket_PluginUpdateChecker') ):

	class Puc_v4_BitBucket_PluginUpdateChecker extends Puc_v4_Plugin_UpdateChecker {
		/**
		 * @var string
		 */
		protected $branch = 'master';

		/**
		 * @var Puc_v4_BitBucket_Api
		 */
		protected $api;

		protected $credentials = array();

		public function requestInfo($queryArgs = array()) {
			//We have to make several remote API requests to gather all the necessary info
			//which can take a while on slow networks.
			set_time_limit(60);

			$api = $this->api = new Puc_v4_BitBucket_Api($this->metadataUrl, $this->credentials);

			$info = new Puc_v4_Plugin_Info();
			$info->filename = $this->pluginFile;
			$info->slug = $this->slug;

			$this->setInfoFromHeader($this->getPluginHeader(), $info);

			//Pick a branch or tag.
			$updateSource = $this->chooseReference();
			if ( $updateSource ) {
				$ref = $updateSource->name;
				$info->version = $updateSource->version;
				$info->last_updated = $updateSource->updated;
				$info->download_url = $updateSource->downloadUrl;
			} else {
				//There's probably a network problem or an authentication error.
				return null;
			}

			//Get headers from the main plugin file in this branch/tag. Its "Version" header and other metadata
			//are what the WordPress install will actually see after upgrading, so they take precedence over releases/tags.
			$mainPluginFile = basename($this->pluginFile);
			$remotePlugin = $api->getRemoteFile($mainPluginFile, $ref);
			if ( !empty($remotePlugin) ) {
				$remoteHeader = $this->getFileHeader($remotePlugin);
				$this->setInfoFromHeader($remoteHeader, $info);
			}

			//Try parsing readme.txt. If it's formatted according to WordPress.org standards, it will contain
			//a lot of useful information like the required/tested WP version, changelog, and so on.
			if ( $this->readmeTxtExistsLocally() ) {
				$this->setInfoFromRemoteReadme($ref, $info);
			}

			//The changelog might be in a separate file.
			if ( empty($info->sections['changelog']) ) {
				$info->sections['changelog'] = $api->getRemoteChangelog($ref, dirname($this->getAbsolutePath()));
				if ( empty($info->sections['changelog']) ) {
					$info->sections['changelog'] = __('There is no changelog available.', 'plugin-update-checker');
				}
			}

			if ( empty($info->last_updated) ) {
				//Fetch the latest commit that changed the tag or branch and use it as the "last_updated" date.
				$latestCommitTime = $api->getLatestCommitTime($ref);
				if ( $latestCommitTime !== null ) {
					$info->last_updated = $latestCommitTime;
				}
			}

			$info = apply_filters($this->getUniqueName('request_info_result'), $info, null);
			return $info;
		}

		/**
		 * Figure out which reference (tag or branch) we'll use to get the latest version of the plugin.
		 *
		 * @return Puc_v4_VcsReference|null
		 */
		protected function chooseReference() {
			$api = $this->api;
			$updateSource = null;

			//Check if there's a "Stable tag: v1.2.3" header that points to a valid tag.
			$remoteReadme = $api->getRemoteReadme($this->branch);
			if ( !empty($remoteReadme['stable_tag']) ) {
				$tag = $remoteReadme['stable_tag'];

				//You can explicitly opt out of using tags by setting "Stable tag" to
				//"trunk" or the name of the current branch.
				if ( ($tag === $this->branch) || ($tag === 'trunk') ) {
					return $api->getBranch($this->branch);
				}

				$updateSource = $api->getTag($tag);
			}
			//Look for version-like tags.
			if ( !$updateSource && ($this->branch === 'master') ) {
				$updateSource = $api->getLatestTag();
			}
			//If all else fails, use the specified branch itself.
			if ( !$updateSource ) {
				$updateSource = $api->getBranch($this->branch);
			}

			return $updateSource;
		}

		public function setAuthentication($credentials) {
			$this->credentials = array_merge(
				array(
					'consumer_key' => '',
					'consumer_secret' => '',
				),
				$credentials
			);
			return $this;
		}

		public function setBranch($branchName = 'master') {
			$this->branch = $branchName;
			return $this;
		}

		/**
		 * Check if the currently installed version has a readme.txt file.
		 *
		 * @return bool
		 */
		protected function readmeTxtExistsLocally() {
			$pluginDirectory = dirname($this->pluginAbsolutePath);
			if ( empty($this->pluginAbsolutePath) || !is_dir($pluginDirectory) || ($pluginDirectory === '.') ) {
				return false;
			}
			return is_file($pluginDirectory . '/readme.txt');
		}

		/**
		 * Copy plugin metadata from a file header to a Plugin Info object.
		 *
		 * @param array $fileHeader
		 * @param Puc_v4_Plugin_Info $pluginInfo
		 */
		protected function setInfoFromHeader($fileHeader, $pluginInfo) {
			$headerToPropertyMap = array(
				'Version' => 'version',
				'Name' => 'name',
				'PluginURI' => 'homepage',
				'Author' => 'author',
				'AuthorName' => 'author',
				'AuthorURI' => 'author_homepage',

				'Requires WP' => 'requires',
				'Tested WP' => 'tested',
				'Requires at least' => 'requires',
				'Tested up to' => 'tested',
			);
			foreach ($headerToPropertyMap as $headerName => $property) {
				if ( isset($fileHeader[$headerName]) && !empty($fileHeader[$headerName]) ) {
					$pluginInfo->$property = $fileHeader[$headerName];
				}
			}

			if ( !empty($fileHeader['Description']) ) {
				$pluginInfo->sections['description'] = $fileHeader['Description'];
			}
		}

		/**
		 * Copy plugin metadata from the remote readme.txt file.
		 *
		 * @param string $ref GitHub tag or branch where to look for the readme.
		 * @param Puc_v4_Plugin_Info $pluginInfo
		 */
		protected function setInfoFromRemoteReadme($ref, $pluginInfo) {
			$readme = $this->api->getRemoteReadme($ref);
			if ( empty($readme) ) {
				return;
			}

			if ( isset($readme['sections']) ) {
				$pluginInfo->sections = array_merge($pluginInfo->sections, $readme['sections']);
			}
			if ( !empty($readme['tested_up_to']) ) {
				$pluginInfo->tested = $readme['tested_up_to'];
			}
			if ( !empty($readme['requires_at_least']) ) {
				$pluginInfo->requires = $readme['requires_at_least'];
			}

			if ( isset($readme['upgrade_notice'], $readme['upgrade_notice'][$pluginInfo->version]) ) {
				$pluginInfo->upgrade_notice = $readme['upgrade_notice'][$pluginInfo->version];
			}
		}

		public function getUpdate() {
			$update = parent::getUpdate();

			//Add authentication data to download URLs. Since OAuth signatures incorporate
			//timestamps, we have to do this immediately before inserting the update. Otherwise
			//authentication could fail due to a stale timestamp.
			if ( isset($update, $update->download_url) && !empty($update->download_url) && !empty($this->credentials) ) {
				if ( !empty($this->credentials['consumer_key']) ) {
					$oauth = new Puc_v4_OAuthSignature(
						$this->credentials['consumer_key'],
						$this->credentials['consumer_secret']
					);
					$update->download_url = $oauth->sign($update->download_url);
				}
			}

			return $update;
		}

	}

endif;