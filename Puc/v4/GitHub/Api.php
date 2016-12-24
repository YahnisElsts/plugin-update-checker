<?php

if ( !class_exists('Puc_v4_GitHub_Api', false) ):

	class Puc_v4_GitHub_Api extends Puc_v4_VcsApi {
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
		 * @return Puc_v4_VcsReference|null
		 */
		public function getLatestRelease() {
			$release = $this->api('/repos/:user/:repo/releases/latest');
			if ( is_wp_error($release) || !is_object($release) || !isset($release->tag_name) ) {
				return null;
			}

			$reference = new Puc_v4_VcsReference(array(
				'name' => $release->tag_name,
				'version' => ltrim($release->tag_name, 'v'), //Remove the "v" prefix from "v1.2.3".
				'downloadUrl' => $release->zipball_url,
				'updated' => $release->created_at,
			));

			if ( !empty($release->body) ) {
				/** @noinspection PhpUndefinedClassInspection */
				$reference->changelog = Parsedown::instance()->text($release->body);
			}
			if ( isset($release->assets[0]) ) {
				$reference->downloadCount = $release->assets[0]->download_count;
			}

			return $reference;
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Puc_v4_VcsReference|null
		 */
		public function getLatestTag() {
			$tags = $this->api('/repos/:user/:repo/tags');

			if ( is_wp_error($tags) || empty($tags) || !is_array($tags) ) {
				return null;
			}

			usort($tags, array($this, 'compareTagNames')); //Sort from highest to lowest.

			$tag = $tags[0];
			return new Puc_v4_VcsReference(array(
				'name' => $tag->name,
				'version' => ltrim($tag->name, 'v'),
				'downloadUrl' => $tag->zipball_url,
			));
		}

		/**
		 * Get a branch by name.
		 *
		 * @param string $branchName
		 * @return null|Puc_v4_VcsReference
		 */
		public function getBranch($branchName) {
			$branch = $this->api('/repos/:user/:repo/branches/' . $branchName);
			if ( is_wp_error($branch) || empty($branch) ) {
				return null;
			}

			$reference = new Puc_v4_VcsReference(array(
				'name' => $branch->name,
				'downloadUrl' => $this->buildArchiveDownloadUrl($branch->name),
			));

			if ( isset($branch->commit, $branch->commit->commit, $branch->commit->commit->author->date) ) {
				$reference->updated = $branch->commit->commit->author->date;
			}

			return $reference;
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
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function getLatestCommitTime($ref) {
			$commits = $this->api('/repos/:user/:repo/commits', array('sha' => $ref));
			if ( !is_wp_error($commits) && is_array($commits) && isset($commits[0]) ) {
				return $commits[0]->commit->author->date;
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

		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return Puc_v4_VcsReference|null
		 */
		public function getTag($tagName) {
			//The current GitHub update checker doesn't use getTag, so didn't bother to implement it.
			throw new LogicException('The ' . __METHOD__ . ' method is not implemented and should not be used.');
		}
	}

endif;