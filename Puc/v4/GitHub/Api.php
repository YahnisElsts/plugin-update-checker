<?php

if ( !class_exists('Puc_v4_GitHub_Api', false) ):

	class Puc_v4_GitHub_Api {
		/**
		 * @var string GitHub username.
		 */
		protected $userName;
		/**
		 * @var string GitHub repository name.
		 */
		protected $repositoryName;

		/**
		 * @var string Either a fully qualified repository URL, or just "user/repo-name".
		 */
		protected $repositoryUrl;

		/**
		 * @var string GitHub authentication token. Optional.
		 */
		protected $accessToken;

		public function __construct($repositoryUrl, $accessToken = null) {
			$this->repositoryUrl = $repositoryUrl;
			$this->accessToken = $accessToken;

			$path = @parse_url($repositoryUrl, PHP_URL_PATH);
			if ( preg_match('@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches) ) {
				$this->userName = $matches['username'];
				$this->repositoryName = $matches['repository'];
			} else {
				throw new InvalidArgumentException('Invalid GitHub repository URL: "' . $repositoryUrl . '"');
			}
		}

		/**
		 * Get the latest release from GitHub.
		 *
		 * @return StdClass|null
		 */
		public function getLatestRelease() {
			$release = $this->api('/repos/:user/:repo/releases/latest');
			if ( is_wp_error($release) || !is_object($release) || !isset($release->tag_name) ) {
				return null;
			}
			return $release;
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return StdClass|null
		 */
		public function getLatestTag() {
			$tags = $this->api('/repos/:user/:repo/tags');

			if ( is_wp_error($tags) || empty($tags) || !is_array($tags) ) {
				return null;
			}

			usort($tags, array($this, 'compareTagNames')); //Sort from highest to lowest.
			return $tags[0];
		}

		/**
		 * Compare two GitHub tags as if they were version number.
		 *
		 * @param string $tag1
		 * @param string $tag2
		 * @return int
		 */
		protected function compareTagNames($tag1, $tag2) {
			if ( !isset($tag1->name) ) {
				return 1;
			}
			if ( !isset($tag2->name) ) {
				return -1;
			}
			return -version_compare($tag1->name, $tag2->name);
		}

		/**
		 * Get the latest commit that changed the specified file.
		 *
		 * @param string $filename
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return StdClass|null
		 */
		public function getLatestCommit($filename, $ref = 'master') {
			$commits = $this->api(
				'/repos/:user/:repo/commits',
				array(
					'path' => $filename,
					'sha' => $ref,
				)
			);
			if ( !is_wp_error($commits) && is_array($commits) && isset($commits[0]) ) {
				return $commits[0];
			}
			return null;
		}

		/**
		 * Perform a GitHub API request.
		 *
		 * @param string $url
		 * @param array $queryParams
		 * @return mixed|WP_Error
		 */
		protected function api($url, $queryParams = array()) {
			$variables = array(
				'user' => $this->userName,
				'repo' => $this->repositoryName,
			);
			foreach ($variables as $name => $value) {
				$url = str_replace('/:' . $name, '/' . urlencode($value), $url);
			}
			$url = 'https://api.github.com' . $url;

			if ( !empty($this->accessToken) ) {
				$queryParams['access_token'] = $this->accessToken;
			}
			if ( !empty($queryParams) ) {
				$url = add_query_arg($queryParams, $url);
			}

			$response = wp_remote_get($url, array('timeout' => 10));
			if ( is_wp_error($response) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			if ( $code === 200 ) {
				$document = json_decode($body);
				return $document;
			}

			return new WP_Error(
				'puc-github-http-error',
				'GitHub API error. HTTP status: ' . $code
			);
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function getRemoteFile($path, $ref = 'master') {
			$apiUrl = '/repos/:user/:repo/contents/' . $path;
			$response = $this->api($apiUrl, array('ref' => $ref));

			if ( is_wp_error($response) || !isset($response->content) || ($response->encoding !== 'base64') ) {
				return null;
			}
			return base64_decode($response->content);
		}

		/**
		 * Generate a URL to download a ZIP archive of the specified branch/tag/etc.
		 *
		 * @param string $ref
		 * @return string
		 */
		public function buildArchiveDownloadUrl($ref = 'master') {
			$url = sprintf(
				'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s',
				urlencode($this->userName),
				urlencode($this->repositoryName),
				urlencode($ref)
			);
			if ( !empty($this->accessToken) ) {
				$url = add_query_arg('access_token', $this->accessToken, $url);
			}
			return $url;
		}
	}

endif;