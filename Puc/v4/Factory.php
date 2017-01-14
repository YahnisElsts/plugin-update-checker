<?php
if ( !class_exists('Puc_v4_Factory', false) ):

	/**
	 * A factory that builds update checker instances.
	 *
	 * When multiple versions of the same class have been loaded (e.g. PluginUpdateChecker 4.0
	 * and 4.1), this factory will always use the latest available minor version. Register class
	 * versions by calling {@link PucFactory::addVersion()}.
	 *
	 * At the moment it can only build instances of the UpdateChecker class. Other classes are
	 * intended mainly for internal use and refer directly to specific implementations.
	 */
	class Puc_v4_Factory {
		protected static $classVersions = array();
		protected static $sorted = false;

		protected static $myMajorVersion = '';
		protected static $latestCompatibleVersion = '';

		/**
		 * Create a new instance of the update checker.
		 *
		 * This method automatically detects if you're using it for a plugin or a theme and chooses
		 * the appropriate implementation for your update source (JSON file, GitHub, BitBucket, etc).
		 *
		 * @see Puc_v4_UpdateChecker::__construct
		 *
		 * @param string $metadataUrl The URL of the metadata file, a GitHub repository, or another supported update source.
		 * @param string $fullPath Full path to the main plugin file or to the theme directory.
		 * @param string $slug Custom slug. Defaults to the name of the main plugin file or the theme directory.
		 * @param int $checkPeriod How often to check for updates (in hours).
		 * @param string $optionName Where to store book-keeping info about update checks.
		 * @param string $muPluginFile The plugin filename relative to the mu-plugins directory.
		 * @return Puc_v4_Plugin_UpdateChecker|Puc_v4_Theme_UpdateChecker|Puc_v4_Vcs_BaseChecker
		 */
		public static function buildUpdateChecker($metadataUrl, $fullPath, $slug = '', $checkPeriod = 12, $optionName = '', $muPluginFile = '') {
			$fullPath = wp_normalize_path($fullPath);
			$id = null;

			//Plugin or theme?
			$themeDirectory = self::getThemeDirectoryName($fullPath);
			if ( $themeDirectory !== null ) {
				$type = 'Theme';
				$id = $themeDirectory;
			} else if ( self::isPluginFile($fullPath) ) {
				$type = 'Plugin';
				$id = $fullPath;
			} else {
				throw new RuntimeException(sprintf(
					'The update checker cannot determine if "%s" is a plugin or a theme. ' .
					'This is a bug. Please contact the PUC developer.',
					htmlentities($fullPath)
				));
			}

			//Which hosting service does the URL point to?
			$service = self::getVcsService($metadataUrl);

			$apiClass = null;
			if ( empty($service) ) {
				//The default is to get update information from a remote JSON file.
				$checkerClass = $type . '_UpdateChecker';
			} else {
				//You can also use a VCS repository like GitHub.
				$checkerClass = 'Vcs_' . $type . 'UpdateChecker';
				$apiClass = $service . 'Api';
			}

			$checkerClass = self::getCompatibleClassVersion($checkerClass);
			if ( $checkerClass === null ) {
				trigger_error(
					sprintf(
						'PUC %s does not support updates for %ss %s',
						htmlentities(self::$latestCompatibleVersion),
						strtolower($type),
						$service ? ('hosted on ' . htmlentities($service)) : 'using JSON metadata'
					),
					E_USER_ERROR
				);
				return null;
			}

			if ( !isset($apiClass) ) {
				//Plain old update checker.
				return new $checkerClass($metadataUrl, $id, $slug, $checkPeriod, $optionName, $muPluginFile);
			} else {
				//VCS checker + an API client.
				$apiClass = self::getCompatibleClassVersion($apiClass);
				if ( $apiClass === null ) {
					trigger_error(sprintf(
						'PUC %s does not support %s',
						htmlentities(self::$latestCompatibleVersion),
						htmlentities($service)
					), E_USER_ERROR);
					return null;
				}

				return new $checkerClass(
					new $apiClass($metadataUrl),
					$id,
					$slug,
					$checkPeriod,
					$optionName,
					$muPluginFile
				);
			}
		}

		/**
		 * Check if the path points to a plugin file.
		 *
		 * @param string $absolutePath Normalized path.
		 * @return bool
		 */
		protected static function isPluginFile($absolutePath) {
			//Is the file inside the "plugins" or "mu-plugins" directory?
			$pluginDir = wp_normalize_path(WP_PLUGIN_DIR);
			$muPluginDir = wp_normalize_path(WPMU_PLUGIN_DIR);
			if ( (strpos($absolutePath, $pluginDir) === 0) || (strpos($absolutePath, $muPluginDir) === 0) ) {
				return true;
			}

			//Does it have a valid plugin header?
			//This is a last-ditch check for plugins symlinked from outside the WP root.
			if ( function_exists('get_file_data') ) {
				$headers = get_file_data($absolutePath, array('Name' => 'Plugin Name'), 'plugin');
				return !empty($headers['Name']);
			}

			return false;
		}

		/**
		 * Get the name of the theme's directory from a full path to any theme file.
		 * E.g. "/abc/public_html/wp-content/themes/foo/whatever.php" => "foo".
		 *
		 * @param string $absolutePath Normalized path.
		 * @return string|null Directory name, or NULL if the path doesn't point to a theme.
		 */
		protected static function getThemeDirectoryName($absolutePath) {
			$themeRoot = wp_normalize_path(get_theme_root());
			if ( strpos($absolutePath, $themeRoot) !== 0 ) {
				return null;
			}

			$pathComponents = explode('/', substr($absolutePath, strlen($themeRoot) + 1));
			if ( !is_array($pathComponents) || !isset($pathComponents[0]) ) {
				return null;
			}
			return $pathComponents[0];
		}

		/**
		 * Get the name of the hosting service that the URL points to.
		 *
		 * @param string $metadataUrl
		 * @return string|null
		 */
		private static function getVcsService($metadataUrl) {
			$service = null;

			//Which hosting service does the URL point to?
			$host = @parse_url($metadataUrl, PHP_URL_HOST);
			$path = @parse_url($metadataUrl, PHP_URL_PATH);
			//Check if the path looks like "/user-name/repository".
			$usernameRepoRegex = '@^/?([^/]+?)/([^/#?&]+?)/?$@';
			if ( preg_match($usernameRepoRegex, $path) ) {
				$knownServices = array(
					'github.com' => 'GitHub',
					'bitbucket.org' => 'BitBucket',
					'gitlab.com' => 'GitLab',
				);
				if ( isset($knownServices[$host]) ) {
					$service = $knownServices[$host];
				}
			}

			return $service;
		}

		/**
		 * Get the latest version of the specified class that has the same major version number
		 * as this factory class.
		 *
		 * @param string $class Partial class name.
		 * @return string|null Full class name.
		 */
		protected static function getCompatibleClassVersion($class) {
			if ( isset(self::$classVersions[$class][self::$latestCompatibleVersion]) ) {
				return self::$classVersions[$class][self::$latestCompatibleVersion];
			}
			return null;
		}

		/**
		 * Get the specific class name for the latest available version of a class.
		 *
		 * @param string $class
		 * @return null|string
		 */
		public static function getLatestClassVersion($class) {
			if ( !self::$sorted ) {
				self::sortVersions();
			}

			if ( isset(self::$classVersions[$class]) ) {
				return reset(self::$classVersions[$class]);
			} else {
				return null;
			}
		}

		/**
		 * Sort available class versions in descending order (i.e. newest first).
		 */
		protected static function sortVersions() {
			foreach ( self::$classVersions as $class => $versions ) {
				uksort($versions, array(__CLASS__, 'compareVersions'));
				self::$classVersions[$class] = $versions;
			}
			self::$sorted = true;
		}

		protected static function compareVersions($a, $b) {
			return -version_compare($a, $b);
		}

		/**
		 * Register a version of a class.
		 *
		 * @access private This method is only for internal use by the library.
		 *
		 * @param string $generalClass Class name without version numbers, e.g. 'PluginUpdateChecker'.
		 * @param string $versionedClass Actual class name, e.g. 'PluginUpdateChecker_1_2'.
		 * @param string $version Version number, e.g. '1.2'.
		 */
		public static function addVersion($generalClass, $versionedClass, $version) {
			if ( empty(self::$myMajorVersion) ) {
				$nameParts = explode('_', __CLASS__, 3);
				self::$myMajorVersion = substr(ltrim($nameParts[1], 'v'), 0, 1);
			}

			//Store the greatest version number that matches our major version.
			$components = explode('.', $version);
			if ( $components[0] === self::$myMajorVersion ) {

				if (
					empty(self::$latestCompatibleVersion)
					|| version_compare($version, self::$latestCompatibleVersion, '>')
				) {
					self::$latestCompatibleVersion = $version;
				}

			}

			if ( !isset(self::$classVersions[$generalClass]) ) {
				self::$classVersions[$generalClass] = array();
			}
			self::$classVersions[$generalClass][$version] = $versionedClass;
			self::$sorted = false;
		}
	}

endif;