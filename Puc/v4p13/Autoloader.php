<?php

if ( !class_exists('Puc_v4p13_Autoloader', false) ):

	class Puc_v4p13_Autoloader {
		private $prefix = '';
		private $rootDir = '';
		private $libraryDir = '';

		private $staticMap;

		public function __construct() {
			$this->rootDir = dirname(__FILE__) . '/';

			if ( version_compare(PHP_VERSION, '5.3', '>=') && __NAMESPACE__ ) {
				$namespaceWithSlash = __NAMESPACE__ . '\\';
			} else {
				$namespaceWithSlash = '';
			}

			$nameParts = explode('_', substr(__CLASS__, strlen($namespaceWithSlash)), 3);
			$this->prefix = $namespaceWithSlash . $nameParts[0] . '_' . $nameParts[1] . '_';

			$this->libraryDir = $this->rootDir . '../..';
			if ( !self::isPhar() ) {
				$this->libraryDir = realpath($this->libraryDir);
			}
			$this->libraryDir = $this->libraryDir . '/';

			$this->staticMap = array(
				$namespaceWithSlash . 'PucReadmeParser' => 'vendor/PucReadmeParser.php',
				$namespaceWithSlash . 'Parsedown'       => 'vendor/Parsedown.php',
				$namespaceWithSlash . 'Puc_v4_Factory'  => 'Puc/v4/Factory.php',
			);

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
			if ( isset($this->staticMap[$className]) && file_exists($this->libraryDir . $this->staticMap[$className]) ) {
				/** @noinspection PhpIncludeInspection */
				include ($this->libraryDir . $this->staticMap[$className]);
				return;
			}

			if (strpos($className, $this->prefix) === 0) {
				$path = substr($className, strlen($this->prefix));
				$path = str_replace('_', '/', $path);
				$path = $this->rootDir . $path . '.php';

				if (file_exists($path)) {
					/** @noinspection PhpIncludeInspection */
					include $path;
				}
			}
		}
	}

endif;
