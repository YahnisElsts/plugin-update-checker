<?php

if ( !class_exists('Puc_v4_Theme_UpdateChecker', false) ):

	class Puc_v4_Theme_UpdateChecker extends Puc_v4_UpdateChecker {
		protected $filterSuffix = 'theme';
		protected $updateClass = 'Puc_v4_Theme_Update';
		protected $updateTransient = 'update_themes';
		protected $translationType = 'theme';

		/**
		 * @var string Theme directory name.
		 */
		protected $stylesheet;

		/**
		 * @var WP_Theme Theme object.
		 */
		protected $theme;

		public function __construct($metadataUrl, $stylesheet = null, $customSlug = null, $checkPeriod = 12, $optionName = '') {
			if ( $stylesheet === null ) {
				$stylesheet = get_stylesheet();
			}
			$this->stylesheet = $stylesheet;
			$this->theme = wp_get_theme($this->stylesheet);

			parent::__construct(
				$metadataUrl,
				$stylesheet,
				$customSlug ? $customSlug : $stylesheet,
				$checkPeriod,
				$optionName
			);

			add_action('admin_notices', function() {
				//var_dump(get_site_transient('update_plugins'));
				//var_dump(get_site_transient('update_themes'));
			});
		}

		protected function installHooks() {
			parent::installHooks();
		}

		/**
		 * For themes, the update array is indexed by theme directory name.
		 *
		 * @return string
		 */
		protected function getUpdateListKey() {
			return $this->directoryName;
		}

		/**
		 * Retrieve the latest update (if any) from the configured API endpoint.
		 *
		 * @return Puc_v4_Update An instance of Update, or NULL when no updates are available.
		 */
		public function requestUpdate() {
			//Query args to append to the URL. Themes can add their own by using a filter callback (see addQueryArgFilter()).
			$queryArgs = array();
			$installedVersion = $this->getInstalledVersion();
			$queryArgs['installed_version'] = ($installedVersion !== null) ? $installedVersion : '';

			$queryArgs = apply_filters($this->getFilterName('request_update_query_args'), $queryArgs);

			//Various options for the wp_remote_get() call. Plugins can filter these, too.
			$options = array(
				'timeout' => 10, //seconds
				'headers' => array(
					'Accept' => 'application/json'
				),
			);
			$options = apply_filters($this->getFilterName('request_update_options'), $options);

			$url = $this->metadataUrl;
			if ( !empty($queryArgs) ){
				$url = add_query_arg($queryArgs, $url);
			}

			$result = wp_remote_get($url, $options);

			//Try to parse the response
			$status = $this->validateApiResponse($result);
			$themeUpdate = null;
			if ( !is_wp_error($status) ){
				$themeUpdate = Puc_v4_Theme_Update::fromJson($result['body']);
				if ( $themeUpdate !== null ) {
					$themeUpdate->slug = $this->slug;
				}
			} else {
				$this->triggerError(
					sprintf('The URL %s does not point to a valid theme metadata file. ', $url)
					. $status->get_error_message(),
					E_USER_WARNING
				);
			}

			$themeUpdate = apply_filters(
				$this->getFilterName('request_update_result'),
				$themeUpdate,
				$result
			);
			return $themeUpdate;
		}

		public function userCanInstallUpdates() {
			return current_user_can('update_themes');
		}

		/**
		 * Get the currently installed version of the plugin or theme.
		 *
		 * @return string Version number.
		 */
		public function getInstalledVersion() {
			return $this->theme->get('Version');
		}

		/**
		 * Create an instance of the scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4_Scheduler
		 */
		protected function createScheduler($checkPeriod) {
			return new Puc_v4_Scheduler($this, $checkPeriod, array('load-themes.php'));
		}

		//TODO: Various add*filter utilities for backwards compatibility.

		/**
		 * Is there an update being installed right now for this theme?
		 *
		 * @param WP_Upgrader|null $upgrader The upgrader that's performing the current update.
		 * @return bool
		 */
		public function isBeingUpgraded($upgrader = null) {
			return $this->upgraderStatus->isThemeBeingUpgraded($this->stylesheet, $upgrader);
		}

		protected function createDebugBarExtension() {
			return new Puc_v4_DebugBar_Extension($this, 'Puc_v4_DebugBar_ThemePanel');
		}

	}

endif;