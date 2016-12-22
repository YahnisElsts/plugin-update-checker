<?php
if ( !class_exists('Puc_v4_BitBucket_Api', false) ):

	class Puc_v4_BitBucket_Api {
		/**
		 * @var Puc_v4_OAuthSignature
		 */
		private $oauth = null;

		/**
		 * @var string
		 */
		private $username;

		/**
		 * @var string
		 */
		private $repository;

		public function __construct($repositoryUrl, $credentials = array()) {
			$path = @parse_url($repositoryUrl, PHP_URL_PATH);
			if ( preg_match('@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches) ) {
				$this->username = $matches['username'];
				$this->repository = $matches['repository'];
			} else {
				throw new InvalidArgumentException('Invalid BitBucket repository URL: "' . $repositoryUrl . '"');
			}

			if ( !empty($credentials) && !empty($credentials['consumer_key']) ) {
				$this->oauth = new Puc_v4_OAuthSignature(
					$credentials['consumer_key'],
					$credentials['consumer_secret']
				);
			}
		}

		/**
		 * @param string $ref
		 * @return array
		 */
		public function getRemoteReadme($ref = 'master') {
			$fileContents = $this->getRemoteFile('readme.txt', $ref);
			if ( empty($fileContents) ) {
				return array();
			}

			$parser = new PucReadmeParser();
			return $parser->parse_readme_contents($fileContents);
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return stdClass|null
		 */
		public function getTag($tagName) {
			$tag = $this->api('/refs/tags/' . $tagName);
			if ( is_wp_error($tag) || empty($tag) ) {
				return null;
			}
			return $tag;
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return stdClass|null
		 */
		public function getLatestTag() {
			$tags = $this->api('/refs/tags');
			if ( !isset($tags, $tags->values) || !is_array($tags->values) ) {
				return null;
			}

			//Keep only those tags that look like version numbers.
			$versionTags = array_filter($tags->values, array($this, 'isVersionTag'));
			//Sort them in descending order.
			usort($versionTags, array($this, 'compareTagNames'));

			//Return the first result.
			if ( !empty($versionTags) ) {
				return $versionTags[0];
			}
			return null;
		}

		protected function isVersionTag($tag) {
			return isset($tag->name) && $this->looksLikeVersion($tag->name);
		}

		/**
		 * Check if a tag name string looks like a version number.
		 *
		 * @param string $name
		 * @return bool
		 */
		protected function looksLikeVersion($name) {
			//Tag names may be prefixed with "v", e.g. "v1.2.3".
			$name = ltrim($name, 'v');

			//The version string must start with a number.
			if ( !is_numeric($name) ) {
				return false;
			}

			//The goal is to accept any SemVer-compatible or "PHP-standardized" version number.
			return (preg_match('@^(\d{1,5}?)(\.\d{1,10}?){0,4}?($|[abrdp+_\-]|\s)@i', $name) === 1);
		}

		/**
		 * Compare two BitBucket tags as if they were version number.
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
			return -version_compare(ltrim($tag1->name, 'v'), ltrim($tag2->name, 'v'));
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function getRemoteFile($path, $ref = 'master') {
			$response = $this->api('src/' . $ref . '/' . ltrim($path), '1.0');
			if ( is_wp_error($response) || !isset($response, $response->data) ) {
				return null;
			}
			return $response->data;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function getLatestCommitTime($ref) {
			$response = $this->api('commits/' . $ref);
			if ( isset($response->values, $response->values[0], $response->values[0]->date) ) {
				return $response->values[0]->date;
			}
			return null;
		}

		public function getRemoteChangelog($ref, $localDirectory) {
			$filename = $this->findChangelogName($localDirectory);
			if ( empty($filename) ) {
				return null;
			}

			$changelog = $this->getRemoteFile($filename, $ref);
			if ( $changelog === null ) {
				return null;
			}

			/** @noinspection PhpUndefinedClassInspection */
			$instance = Parsedown::instance();
			return $instance->text($changelog);
		}

		protected function findChangelogName($directory) {
			if ( empty($directory) || !is_dir($directory) || ($directory === '.') ) {
				return null;
			}

			$possibleNames = array('CHANGES.md', 'CHANGELOG.md', 'changes.md', 'changelog.md');
			$files = scandir($directory);
			$foundNames = array_intersect($possibleNames, $files);

			if ( !empty($foundNames) ) {
				return reset($foundNames);
			}
			return null;
		}

		/**
		 * Perform a BitBucket API 2.0 request.
		 *
		 * @param string $url
		 * @param string $version
		 * @return mixed|WP_Error
		 */
		public function api($url, $version = '2.0') {
			//printf('Requesting %s<br>' . "\n", $url);

			$url = implode('/', array(
				'https://api.bitbucket.org',
				$version,
				'repositories',
				$this->username,
				$this->repository,
				ltrim($url, '/')
			));

			if ( $this->oauth ) {
				$url = $this->oauth->sign($url,'GET');
			}

			$response = wp_remote_get($url, array('timeout' => 10));
			//var_dump($response);
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
				'puc-bitbucket-http-error',
				'BitBucket API error. HTTP status: ' . $code
			);
		}
	}

endif;