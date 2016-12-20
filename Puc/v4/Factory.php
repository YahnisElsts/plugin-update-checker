<?php
if ( !class_exists('Puc_v4_Factory', false) ):

	/**
	 * A factory that builds instances of other classes from this library.
	 *
	 * When multiple versions of the same class have been loaded (e.g. PluginUpdateChecker 4.0
	 * and 4.1), this factory will always use the latest available minor version. Register class
	 * versions by calling {@link PucFactory::addVersion()}.
	 *
	 * At the moment it can only build instances of the PluginUpdateChecker class. Other classes
	 * are intended mainly for internal use and refer directly to specific implementations. If you
	 * want to instantiate one of them anyway, you can use {@link PucFactory::getLatestClassVersion()}
	 * to get the class name and then create it with <code>new $class(...)</code>.
	 */
	class Puc_v4_Factory {
		protected static $classVersions = array();
		protected static $sorted = false;

		protected static $myMajorVersion = '';
		protected static $greatestCompatVersion = '';

		/**
		 * Create a new instance of the update checker.
		 *
		 * @see PluginUpdateChecker::__construct()
		 *
		 * @param string $metadataUrl The URL of the metadata file, or a GitHub repository, etc.
		 * @param string $fullPath Full path to the main plugin file or to the theme directory.
		 * @param string $slug Custom slug. Defaults to the name of the main plugin file or the theme directory.
		 * @param int $checkPeriod How often to check for updates (in hours).
		 * @param string $optionName Where to store book-keeping info about update checks.
		 * @param string $muPluginFile The plugin filename relative to the mu-plugins directory.
		 * @return Puc_v4_UpdateChecker
		 */
		public static function buildUpdateChecker($metadataUrl, $fullPath, $slug = '', $checkPeriod = 12, $optionName = '', $muPluginFile = '') {
			$fullPath = wp_normalize_path($fullPath);
			$service = null;
			$id = null;

			//Plugin or theme?
			if ( self::isPluginFile($fullPath) ) {
				$type = 'Plugin';
				$id = $fullPath;
			} else {
				$type = 'Theme';

				//Get the name of the theme's directory. E.g. "wp-content/themes/foo/whatever.php" => "foo".
				$themeRoot = wp_normalize_path(get_theme_root());
				$pathComponents = explode('/', substr($fullPath, strlen($themeRoot) + 1));
				$id = $pathComponents[0];
			}

			//Which hosting service does the URL point to?
			$host = @parse_url($metadataUrl, PHP_URL_HOST);
			$path = @parse_url($metadataUrl, PHP_URL_PATH);
			//Check if the path looks like "/user-name/repository".
			$usernameRepoRegex = '@^/?([^/]+?)/([^/#?&]+?)/?$@';
			if ( preg_match($usernameRepoRegex, $path) ) {
				switch($host) {
					case 'github.com':
						$service = 'GitHub';
						break;
					case 'bitbucket.org':
						$service = 'BitBucket';
						break;
					case 'gitlab.com':
						$service = 'GitLab';
						break;
				}
			}

			$class = null;
			if ( empty($service) ) {
				//The default is to get update information from a remote JSON file.
				$class = $type . '_UpdateChecker';
			} else {
				$class = $service . '_' . $type . 'UpdateChecker';
			}

			if ( !isset(self::$classVersions[$class][self::$greatestCompatVersion]) ) {
				trigger_error(
					sprintf(
						'PUC %s does not support updates for %ss hosted on %s',
						htmlentities(self::$greatestCompatVersion),
						strtolower($type),
						$service
					),
					E_USER_ERROR
				);
				return null;
			}

			$class = self::$classVersions[$class][self::$greatestCompatVersion];
			return new $class($metadataUrl, $id, $slug, $checkPeriod, $optionName, $muPluginFile);
		}

		protected static function isPluginFile($absolutePath) {
			$pluginDir = wp_normalize_path(WP_PLUGIN_DIR);
			$muPluginDir = wp_normalize_path(WPMU_PLUGIN_DIR);
			$absolutePath = wp_normalize_path($absolutePath);

			return (strpos($absolutePath, $pluginDir) === 0) || (strpos($absolutePath, $muPluginDir) === 0);
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
				if ( empty(self::$greatestCompatVersion) || version_compare($version, self::$greatestCompatVersion, '>') ) {
					self::$greatestCompatVersion = $version;
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