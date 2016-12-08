<?php
/**
 * Plugin Update Checker Library 4.0
 * http://w-shadow.com/
 * 
 * Copyright 2016 Janis Elsts
 * Released under the MIT license. See license.txt for details.
 */

require dirname(__FILE__) . '/Puc/v4/Autoloader.php';
new Puc_v4_Autoloader();


if ( !class_exists('PucFactory', false) ):

/**
 * A factory that builds instances of other classes from this library.
 *
 * When multiple versions of the same class have been loaded (e.g. PluginUpdateChecker 1.2
 * and 1.3), this factory will always use the latest available version. Register class
 * versions by calling {@link PucFactory::addVersion()}.
 *
 * At the moment it can only build instances of the PluginUpdateChecker class. Other classes
 * are intended mainly for internal use and refer directly to specific implementations. If you
 * want to instantiate one of them anyway, you can use {@link PucFactory::getLatestClassVersion()}
 * to get the class name and then create it with <code>new $class(...)</code>.
 */
class PucFactory {
	protected static $classVersions = array();
	protected static $sorted = false;

	/**
	 * Create a new instance of PluginUpdateChecker.
	 *
	 * @see PluginUpdateChecker::__construct()
	 *
	 * @param $metadataUrl
	 * @param $pluginFile
	 * @param string $slug
	 * @param int $checkPeriod
	 * @param string $optionName
	 * @param string $muPluginFile
	 * @return Puc_v4_Plugin_UpdateChecker
	 */
	public static function buildUpdateChecker($metadataUrl, $pluginFile, $slug = '', $checkPeriod = 12, $optionName = '', $muPluginFile = '') {
		$class = self::getLatestClassVersion('PluginUpdateChecker');
		return new $class($metadataUrl, $pluginFile, $slug, $checkPeriod, $optionName, $muPluginFile);
	}

	/**
	 * Get the specific class name for the latest available version of a class.
	 *
	 * @param string $class
	 * @return string|null
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
		if ( !isset(self::$classVersions[$generalClass]) ) {
			self::$classVersions[$generalClass] = array();
		}
		self::$classVersions[$generalClass][$version] = $versionedClass;
		self::$sorted = false;
	}
}

endif;

//Register classes defined in this file with the factory.
PucFactory::addVersion('PluginUpdateChecker', 'Puc_v4_Plugin_UpdateChecker', '3.2');
PucFactory::addVersion('PluginUpdate', 'Puc_v4_Plugin_Update', '3.2');
PucFactory::addVersion('PluginInfo', 'Puc_v4_Plugin_Info', '3.2');
PucFactory::addVersion('PucGitHubChecker', 'Puc_v4_GitHub_PluginChecker', '3.2');
