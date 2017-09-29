<?php

if ( ! class_exists( 'Puc_v4p2_Vcs_GitLabApi', false ) ) :

	class Puc_v4p2_Vcs_GitLabApi extends Puc_v4p2_Vcs_Api {
		/**
		 * @var string GitLab username.
		 */
		protected $userName;

		/**
		 * @var string GitLab server host.
		 */
		private $repositoryHost;

		/**
		 * @var string GitLab repository name.
		 */
		protected $repositoryName;

		/**
		 * @var string Either a fully qualified repository URL, or just "user/repo-name".
		 */
		protected $repositoryUrl;

		/**
		 * @var string GitLab authentication token. Optional.
		 */
		protected $accessToken;

		public function __construct( $repositoryUrl, $accessToken = null ) {
			// parse the repository host to support custom hosts
			$this->repositoryHost = @parse_url( $repositoryUrl, PHP_URL_HOST );

			// find the repository information
			$path = @parse_url( $repositoryUrl, PHP_URL_PATH );
			if ( preg_match( '@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches ) ) {
				$this->userName = $matches['username'];
				$this->repositoryName = $matches['repository'];
			} else {
				throw new InvalidArgumentException( 'Invalid GitLab repository URL: "' . $repositoryUrl . '"' );
			}

			parent::__construct( $repositoryUrl, $accessToken );
		}

		/**
		 * Get the latest release from GitLab.
		 *
		 * @return Puc_v4p2_Vcs_Reference|null
		 */
		public function getLatestRelease() {
			// GitLab doesn't use releases like GitHub, we should instead look through the tags for the latest one
			// tagged as a release.
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Puc_v4p2_Vcs_Reference|null
		 */
		public function getLatestTag() {
			$tags = $this->api( '/:user/:repo/repository/tags' );
			if ( is_wp_error( $tags ) || empty( $tags ) || ! is_array( $tags ) ) {
				return null;
			}

			$versionTags = $this->sortTagsByVersion( $tags );
			if ( empty( $versionTags ) ) {
				return null;
			}

			$tag = $versionTags[0];
			return new Puc_v4p2_Vcs_Reference( array (
				'name' => $tag->name,
				'version' => ltrim( $tag->name, 'v' ),
				'downloadUrl' => '',
				'apiResponse' => $tag
			) );
		}

		public function getBranch( $branchName ) {
			// todo
		}

		public function getLatestCommit( $filename, $ref = 'master' ) {
			// todo
		}

		public function getLatestCommitTime( $ref ) {
			// todo
		}

		/**
		 * Perform a GitLab API request.
		 *
		 * @param string $url
		 * @param array $queryParams
		 * @return mixed|WP_Error
		 */
		protected function api( $url, $queryParams = array() ) {
			$url = $this->buildApiUrl( $url, $queryParams );

			$options = array( 'timeout' => 10 );
			if ( ! empty( $this->httpFilterName ) ) {
				$options = apply_filters( $this->httpFilterName, $options );
			}
			
			$response = wp_remote_get( $url, $options );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			if ( $code === 200 ) {
				return json_decode( $body );
			}

			return new WP_Error(
				'puc-gitlab-http-error',
				'GitLab API Error. HTTP status: ' . $code
			);
		}

		/**
		 * Build a fully qualified URL for an API request.
		 *
		 * @param string $url
		 * @param array $queryParams
		 * @return string
		 */
		protected function buildApiUrl( $url, $queryParams ) {
			$variables = array(
				'user' => $this->userName,
				'repo' => $this->repositoryName
			);

			foreach ( $variables as $name => $value ) {
				$url = str_replace( "/:{$name}", urlencode( '/' . $value ), $url );
			}

			$url = substr( $url, 3 );
			$url = sprintf( 'https://%s/api/v4/projects/%s', $this->repositoryHost, $url );

			if ( $this->accessToken ) {
				$queryParams['private_token'] = $this->accessToken;
			}

			if ( ! empty( $queryParams ) ) {
				$url = add_query_arg( $queryParams, $url );
			}

			return $url;
		}

		public function getRemoteFile( $path, $ref = 'master' ) {
			$response = $this->api( '/:user/:repo/repository/files/' . $path, array( 'ref' => $ref ) );
			if ( is_wp_error( $response ) || ! isset( $response->content ) || $response->encoding !== 'base64' ) {
				return null;
			}

			return base64_decode( $response->content );
		}

		public function getTag( $tagName ) {
			// todo
		}

		public function chooseReference( $configBranch ) {
			$updateSource = null;

			if ( $configBranch === 'master' ) {
				// $updateSource = $this->getLatestRelease();
				// if ( $updateSource === null ) {
				$updateSource = $this->getLatestTag();
				// }
			}

			if ( empty( $updateSource ) ) {
				$updateSource = $this->getBranch( $configBranch );
			}

			return $updateSource;
		}

		public function setAuthentication( $credentials ) {
			parent::setAuthentication( $credentials );
			$this->accessToken = is_string( $credentials ) ? $credentials : null;
		}

		private function getDownloadUrl( $file ) {
			// todo
		}
	}

endif;