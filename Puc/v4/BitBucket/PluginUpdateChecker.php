<?php
if ( !class_exists('Puc_v4_BitBucket_PluginUpdateChecker') ):

	class Puc_v4_BitBucket_PluginUpdateChecker extends Puc_v4_Plugin_UpdateChecker {
		/**
		 * @var string
		 */
		protected $repositoryUrl;

		/**
		 * @var string
		 */
		protected $branch;

		/**
		 * @var Puc_v4_BitBucket_Api
		 */
		protected $api;

		public function requestInfo($queryArgs = array()) {
			//TODO: BitBucket support
			$api = $this->api = new Puc_v4_BitBucket_Api(
				$this->repositoryUrl,
				array()
			);

			$info = new Puc_v4_Plugin_Info();
			$info->filename = $this->pluginFile;
			$info->slug = $this->slug;

			$this->setInfoFromHeader($this->getPluginHeader(), $info);

			//Figure out which reference (tag or branch) we'll use to get the latest version of the plugin.
			$ref = $this->branch;
			$foundVersion = false;

			//Check if there's a "Stable tag: v1.2.3" header that points to a valid tag.
			$remoteReadme = $api->getRemoteReadme($this->branch);
			if ( !empty($remoteReadme['stable_tag']) ) {
				$tag = $api->getTag($remoteReadme['stable_tag']);
				if ( ($tag !== null) && isset($tag->name) ) {
					$ref = $tag->name;
					$info->version = ltrim($tag->name, 'v');
					$info->last_updated = $tag->target->date;
					//TODO: Download url
					$foundVersion = true;
				}
			}

			//Look for version-like tags.
			if ( ($this->branch === 'master') && !$foundVersion ) {
				$tag = $api->getLatestTag();
				if ( ($tag !== null) && isset($tag->name) ) {
					$ref = $tag->name;
					$info->version = ltrim($tag->name, 'v');
					$info->last_updated = $tag->target->date;
					//TODO: Download url
					$foundVersion = true;
				}
			}

			//If all else fails, use the specified branch itself.
			if ( !$foundVersion ) {
				$ref = $this->branch;
				//TODO: Download url for this branch.
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
	}

endif;