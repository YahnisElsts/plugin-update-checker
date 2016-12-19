<?php
if ( !class_exists('Puc_v4_GitHub_ThemeUpdateChecker', false) ):

	class Puc_v4_GitHub_ThemeUpdateChecker extends Puc_v4_Theme_UpdateChecker {
		protected $repositoryUrl;
		protected $branch = 'master';
		protected $accessToken;

		public function __construct($repositoryUrl, $stylesheet = null, $customSlug = null, $checkPeriod = 12, $optionName = '') {
			$this->repositoryUrl = $repositoryUrl;
			parent::__construct($repositoryUrl, $stylesheet, $customSlug, $checkPeriod, $optionName);
		}

		public function requestUpdate() {
			$api = new Puc_v4_GitHub_Api($this->repositoryUrl, $this->accessToken);

			$update = new Puc_v4_Theme_Update();
			$update->slug = $this->slug;

			//Figure out which reference (tag or branch) we'll use to get the latest version of the theme.
			$ref = $this->branch;
			if ( $this->branch === 'master' ) {
				//Use the latest release.
				$release = $api->getLatestRelease();
				if ( $release !== null ) {
					$ref = $release->tag_name;
					$update->version = ltrim($release->tag_name, 'v'); //Remove the "v" prefix from "v1.2.3".
					$update->download_url = $release->zipball_url;
				} else {
					//Failing that, use the tag with the highest version number.
					$tag = $api->getLatestTag();
					if ( $tag !== null ) {
						$ref = $tag->name;
						$update->version = $tag->name;
						$update->download_url = $tag->zipball_url;
					}
				}
			}

			if ( empty($update->download_url) ) {
				$update->download_url = $api->buildArchiveDownloadUrl($ref);
			} else if ( !empty($this->accessToken) ) {
				$update->download_url = add_query_arg('access_token', $this->accessToken, $update->download_url);
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
				$update->details_url = $this->repositoryUrl;
			}

			if ( empty($update->version) ) {
				//It looks like we didn't find a valid update after all.
				$update = null;
			}

			$update = $this->filterUpdateResult($update);
			return $update;
		}

		/**
		 * Set the GitHub branch to use for updates. Defaults to 'master'.
		 *
		 * @param string $branch
		 * @return $this
		 */
		public function setBranch($branch) {
			$this->branch = empty($branch) ? 'master' : $branch;
			return $this;
		}

		/**
		 * Set the access token that will be used to make authenticated GitHub API requests.
		 *
		 * @param string $accessToken
		 * @return $this
		 */
		public function setAccessToken($accessToken) {
			$this->accessToken = $accessToken;
			return $this;
		}
	}

endif;