<?php
if ( !class_exists('Puc_v4_DebugBar_Extension', false) ):

	class Puc_v4_DebugBar_Extension {
		/** @var Puc_v4_UpdateChecker */
		protected $updateChecker;
		protected $panelClass = 'Puc_v4_DebugBar_Panel';

		public function __construct($updateChecker, $panelClass = null) {
			$this->updateChecker = $updateChecker;
			if ( isset($panelClass) ) {
				$this->panelClass = $panelClass;
			}

			add_filter('debug_bar_panels', array($this, 'addDebugBarPanel'));
			add_action('debug_bar_enqueue_scripts', array($this, 'enqueuePanelDependencies'));

			add_action('wp_ajax_puc_v4_debug_check_now', array($this, 'ajaxCheckNow'));
		}

		/**
		 * Register the PUC Debug Bar panel.
		 *
		 * @param array $panels
		 * @return array
		 */
		public function addDebugBarPanel($panels) {
			if ( $this->updateChecker->userCanInstallUpdates() ) {
				$panels[] = new $this->panelClass($this->updateChecker);
			}
			return $panels;
		}

		/**
		 * Enqueue our Debug Bar scripts and styles.
		 */
		public function enqueuePanelDependencies() {
			wp_enqueue_style(
				'puc-debug-bar-style-v4',
				$this->getLibraryUrl("/css/puc-debug-bar.css"),
				array('debug-bar'),
				'20161217'
			);

			wp_enqueue_script(
				'puc-debug-bar-js-v4',
				$this->getLibraryUrl("/js/debug-bar.js"),
				array('jquery'),
				'20161219'
			);
		}

		/**
		 * Run an update check and output the result. Useful for making sure that
		 * the update checking process works as expected.
		 */
		public function ajaxCheckNow() {
			if ( $_POST['uid'] !== $this->updateChecker->getUniqueName('uid') ) {
				return;
			}
			$this->preAjaxReqest();
			$update = $this->updateChecker->checkForUpdates();
			if ( $update !== null ) {
				echo "An update is available:";
				echo '<pre>', htmlentities(print_r($update, true)), '</pre>';
			} else {
				echo 'No updates found.';
			}
			exit;
		}

		/**
		 * Check access permissions and enable error display (for debugging).
		 */
		protected function preAjaxReqest() {
			if ( !$this->updateChecker->userCanInstallUpdates() ) {
				die('Access denied');
			}
			check_ajax_referer('puc-ajax');

			error_reporting(E_ALL);
			@ini_set('display_errors','On');
		}

		/**
		 * @param string $filePath
		 * @return string
		 */
		private function getLibraryUrl($filePath) {
			$absolutePath = realpath(dirname(__FILE__) . '/../../../' . ltrim($filePath, '/'));

			//Where is the library located inside the WordPress directory structure?
			$absolutePath = wp_normalize_path($absolutePath);

			$pluginDir = wp_normalize_path(WP_PLUGIN_DIR);
			$muPluginDir = wp_normalize_path(WPMU_PLUGIN_DIR);
			$themeDir = wp_normalize_path(get_theme_root());

			if ( (strpos($absolutePath, $pluginDir) === 0) || (strpos($absolutePath, $muPluginDir) === 0) ) {
				//It's part of a plugin.
				return plugins_url(basename($absolutePath), $absolutePath);
			} else if ( strpos($absolutePath, $themeDir) === 0 ) {
				//It's part of a theme.
				$relativePath = substr($absolutePath, strlen($themeDir) + 1);
				$template = substr($relativePath, 0, strpos($relativePath, '/'));
				$baseUrl = get_theme_root_uri($template);

				if ( !empty($baseUrl) && $relativePath ) {
					return $baseUrl . '/' . $relativePath;
				}
			}

			return '';
		}
	}

endif;