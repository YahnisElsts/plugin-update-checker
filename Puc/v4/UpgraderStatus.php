<?php
if ( !class_exists('Puc_v4_UpgraderStatus', false) ):

	/**
	 * A utility class that helps figure out which plugin WordPress is upgrading.
	 *
	 * It may seem strange to have an separate class just for that, but the task is surprisingly complicated.
	 * Core classes like Plugin_Upgrader don't expose the plugin file name during an in-progress update (AFAICT).
	 * This class uses a few workarounds and heuristics to get the file name.
	 */
	class Puc_v4_UpgraderStatus {
		private $upgradedPluginFile = null; //The plugin that is currently being upgraded by WordPress.

		public function __construct() {
			//Keep track of which plugin WordPress is currently upgrading.
			add_filter('upgrader_pre_install', array($this, 'setUpgradedPlugin'), 10, 2);
			add_filter('upgrader_package_options', array($this, 'setUpgradedPluginFromOptions'), 10, 1);
			add_filter('upgrader_post_install', array($this, 'clearUpgradedPlugin'), 10, 1);
			add_action('upgrader_process_complete', array($this, 'clearUpgradedPlugin'), 10, 1);
		}

		/**
		 * Is there and update being installed RIGHT NOW, for a specific plugin?
		 *
		 * Caution: This method is unreliable. WordPress doesn't make it easy to figure out what it is upgrading,
		 * and upgrader implementations are liable to change without notice.
		 *
		 * @param string $pluginFile The plugin to check.
		 * @param WP_Upgrader|null $upgrader The upgrader that's performing the current update.
		 * @return bool True if the plugin identified by $pluginFile is being upgraded.
		 */
		public function isPluginBeingUpgraded($pluginFile, $upgrader = null) {
			if ( isset($upgrader) ) {
				$upgradedPluginFile = $this->getPluginBeingUpgradedBy($upgrader);
				if ( !empty($upgradedPluginFile) ) {
					$this->upgradedPluginFile = $upgradedPluginFile;
				}
			}
			return ( !empty($this->upgradedPluginFile) && ($this->upgradedPluginFile === $pluginFile) );
		}

		/**
		 * Get the file name of the plugin that's currently being upgraded.
		 *
		 * @param Plugin_Upgrader|WP_Upgrader $upgrader
		 * @return string|null
		 */
		private function getPluginBeingUpgradedBy($upgrader) {
			if ( !isset($upgrader, $upgrader->skin) ) {
				return null;
			}

			//Figure out which plugin is being upgraded.
			$pluginFile = null;
			$skin = $upgrader->skin;
			if ( $skin instanceof Plugin_Upgrader_Skin ) {
				if ( isset($skin->plugin) && is_string($skin->plugin) && ($skin->plugin !== '') ) {
					$pluginFile = $skin->plugin;
				}
			} elseif ( isset($skin->plugin_info) && is_array($skin->plugin_info) ) {
				//This case is tricky because Bulk_Plugin_Upgrader_Skin (etc) doesn't actually store the plugin
				//filename anywhere. Instead, it has the plugin headers in $plugin_info. So the best we can
				//do is compare those headers to the headers of installed plugins.
				$pluginFile = $this->identifyPluginByHeaders($skin->plugin_info);
			}

			return $pluginFile;
		}

		/**
		 * Identify an installed plugin based on its headers.
		 *
		 * @param array $searchHeaders The plugin file header to look for.
		 * @return string|null Plugin basename ("foo/bar.php"), or NULL if we can't identify the plugin.
		 */
		private function identifyPluginByHeaders($searchHeaders) {
			if ( !function_exists('get_plugins') ){
				/** @noinspection PhpIncludeInspection */
				require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
			}

			$installedPlugins = get_plugins();
			$matches = array();
			foreach($installedPlugins as $pluginBasename => $headers) {
				$diff1 = array_diff_assoc($headers, $searchHeaders);
				$diff2 = array_diff_assoc($searchHeaders, $headers);
				if ( empty($diff1) && empty($diff2) ) {
					$matches[] = $pluginBasename;
				}
			}

			//It's possible (though very unlikely) that there could be two plugins with identical
			//headers. In that case, we can't unambiguously identify the plugin that's being upgraded.
			if ( count($matches) !== 1 ) {
				return null;
			}

			return reset($matches);
		}

		/**
		 * @access private
		 *
		 * @param mixed $input
		 * @param array $hookExtra
		 * @return mixed Returns $input unaltered.
		 */
		public function setUpgradedPlugin($input, $hookExtra) {
			if (!empty($hookExtra['plugin']) && is_string($hookExtra['plugin'])) {
				$this->upgradedPluginFile = $hookExtra['plugin'];
			} else {
				$this->upgradedPluginFile = null;
			}
			return $input;
		}

		/**
		 * @access private
		 *
		 * @param array $options
		 * @return array
		 */
		public function setUpgradedPluginFromOptions($options) {
			if (isset($options['hook_extra']['plugin']) && is_string($options['hook_extra']['plugin'])) {
				$this->upgradedPluginFile = $options['hook_extra']['plugin'];
			} else {
				$this->upgradedPluginFile = null;
			}
			return $options;
		}

		/**
		 * @access private
		 *
		 * @param mixed $input
		 * @return mixed Returns $input unaltered.
		 */
		public function clearUpgradedPlugin($input = null) {
			$this->upgradedPluginFile = null;
			return $input;
		}
	}

endif;