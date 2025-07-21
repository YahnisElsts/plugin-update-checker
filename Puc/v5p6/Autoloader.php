<?php

namespace YahnisElsts\PluginUpdateChecker\v5p6;

if ( !class_exists(Autoloader::class, false) ):

	class Autoloader {
		const DEFAULT_NS_PREFIX = 'YahnisElsts\\PluginUpdateChecker\\';

		private $prefix;
		private $rootDir;
		private $libraryDir;

		private $staticMap;

		public function __construct() {
			$this->rootDir = dirname(__FILE__) . '/';

			$namespaceWithSlash = __NAMESPACE__ . '\\';
			$this->prefix = $namespaceWithSlash;

			$this->libraryDir = $this->rootDir . '../..';
			if ( !self::isPhar() ) {
				$this->libraryDir = realpath($this->libraryDir);
			}
			$this->libraryDir = $this->libraryDir . '/';

			//Usually, dependencies like Parsedown are in the global namespace,
			//but if someone adds a custom namespace to the entire library, they
			//will be in the same namespace as this class.
			$isCustomNamespace = (
				substr($namespaceWithSlash, 0, strlen(self::DEFAULT_NS_PREFIX)) !== self::DEFAULT_NS_PREFIX
			);
			$libraryPrefix = $isCustomNamespace ? $namespaceWithSlash : '';

			$this->staticMap = array(
				$libraryPrefix . 'PucReadmeParser' => 'vendor/PucReadmeParser.php',
				$libraryPrefix . 'Parsedown'       => 'vendor/Parsedown.php',
			);

			//Add the generic, major-version-only factory class to the static map.
			$versionSeparatorPos = strrpos(__NAMESPACE__, '\\v');
			if ( $versionSeparatorPos !== false ) {
				$versionSegment = substr(__NAMESPACE__, $versionSeparatorPos + 1);
				$pointPos = strpos($versionSegment, 'p');
				if ( ($pointPos !== false) && ($pointPos > 1) ) {
					$majorVersionSegment = substr($versionSegment, 0, $pointPos);
					$majorVersionNs = __NAMESPACE__ . '\\' . $majorVersionSegment;
					$this->staticMap[$majorVersionNs . '\\PucFactory'] =
						'Puc/' . $majorVersionSegment . '/Factory.php';
				}
			}

			spl_autoload_register(array($this, 'autoload'));
		}

		/**
		 * Determine if this file is running as part of a Phar archive.
		 *
		 * @return bool
		 */
		private static function isPhar() {
			//Check if the current file path starts with "phar://".
			static $pharProtocol = 'phar://';
			return (substr(__FILE__, 0, strlen($pharProtocol)) === $pharProtocol);
		}

		public function autoload($className) {
			if (isset($this->staticMap[$className])) {
				$file = $this->libraryDir . $this->staticMap[$className];
				$realFile = realpath($file);
				$realBase = realpath($this->libraryDir);

				// Check file exists and is inside libraryDir
				if ($realFile && strpos($realFile, $realBase) === 0 && file_exists($realFile)) {
					include $realFile;
					return;
				}
			}

			if (strpos($className, $this->prefix) === 0) {
				$path = substr($className, strlen($this->prefix));
				$path = str_replace(array('_', '\\'), '/', $path);
				$file = $this->rootDir . $path . '.php';

				$realFile = realpath($file);
				$realBase = realpath($this->rootDir);

				// Ensure the file is inside rootDir and exists
				if ($realFile && strpos($realFile, $realBase) === 0 && file_exists($realFile)) {
					include $realFile;
				}
			}
		}

	}

endif;
