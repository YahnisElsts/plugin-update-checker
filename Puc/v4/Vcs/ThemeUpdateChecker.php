<?php

if ( !class_exists('Puc_v4_Vcs_ThemeUpdateChecker', false) ):

	class Puc_v4_Vcs_ThemeUpdateChecker extends Puc_v4_Theme_UpdateChecker implements Puc_v4_Vcs_BaseChecker {
		/**
		 * @var string The branch where to look for updates. Defaults to "master".
		 */
		protected $branch = 'master';

		/**
		 * @var Puc_v4_Vcs_Api Repository API client.
		 */
		protected $api = null;

		/**
		 * Puc_v4_Vcs_ThemeUpdateChecker constructor.
		 *
		 * @param Puc_v4_Vcs_Api $api
		 * @param null $stylesheet
		 * @param null $customSlug
		 * @param int $checkPeriod
		 * @param string $optionName
		 */
		public function __construct($api, $stylesheet = null, $customSlug = null, $checkPeriod = 12, $optionName = '') {
			$this->api = $api;
			parent::__construct($api->getRepositoryUrl(), $stylesheet, $customSlug, $checkPeriod, $optionName);
		}

		public function requestUpdate() {
			$api = $this->api;

			$update = new Puc_v4_Theme_Update();
			$update->slug = $this->slug;

			//Figure out which reference (tag or branch) we'll use to get the latest version of the theme.
			$updateSource = $api->chooseReference($this->branch, false);
			if ( $updateSource ) {
				$ref = $updateSource->name;
				$update->version = $updateSource->version;
				$update->download_url = $updateSource->downloadUrl;
			} else {
				$ref = $this->branch;
			}

			//Get headers from the main stylesheet in this branch/tag. Its "Version" header and other metadata
			//are what the WordPress install will actually see after upgrading, so they take precedence over releases/tags.
			$remoteStylesheet = $api->getRemoteFile('style.css', $ref);
			if ( !empty($remoteStylesheet) ) {
				$remoteHeader = $this->getFileHeader($remoteStylesheet);
				if ( !empty($remoteHeader['Version']) ) {
					$update->version = $remoteHeader['Version'];
				}
				if ( !empty($remoteHeader['ThemeURI']) ) {
					$update->details_url = $remoteHeader['ThemeURI'];
				}
			}

			//The details URL defaults to the Theme URI header or the repository URL.
			if ( empty($update->details_url) ) {
				$update->details_url = $this->theme->get('ThemeURI');
			}
			if ( empty($update->details_url) ) {
				$update->details_url = $this->metadataUrl;
			}

			if ( empty($update->version) ) {
				//It looks like we didn't find a valid update after all.
				$update = null;
			}

			$update = $this->filterUpdateResult($update);
			return $update;
		}

		public function setBranch($branch) {
			$this->branch = $branch;
			return $this;
		}

		public function setAuthentication($credentials) {
			$this->api->setAuthentication($credentials);
			return $this;
		}

		public function getUpdate() {
			$update = parent::getUpdate();

			if ( isset($update) && !empty($update->download_url) ) {
				$update->download_url = $this->api->signDownloadUrl($update->download_url);
			}

			return $update;
		}


	}

endif;