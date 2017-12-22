<?php

if ( !class_exists('Puc_v4p4_Theme_UpdateChecker', false) ):

	class Puc_v4p4_Theme_UpdateChecker extends Puc_v4p4_UpdateChecker {
		protected $filterSuffix = 'theme';
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
		 * @return Puc_v4p4_Update|null An instance of Update, or NULL when no updates are available.
		 */
		public function requestUpdate() {
			list($themeUpdate, $result) = $this->requestMetadata('Puc_v4p4_Theme_Update', 'request_update');

			if ( $themeUpdate !== null ) {
				/** @var Puc_v4p4_Theme_Update $themeUpdate */
				$themeUpdate->slug = $this->slug;
			}

			$themeUpdate = $this->filterUpdateResult($themeUpdate, $result);
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
		 * @return string
		 */
		public function getAbsoluteDirectoryPath() {
			if ( method_exists($this->theme, 'get_stylesheet_directory') ) {
				return $this->theme->get_stylesheet_directory(); //Available since WP 3.4.
			}
			return get_theme_root($this->stylesheet) . '/' . $this->stylesheet;
		}

		/**
		 * Create an instance of the scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4p4_Scheduler
		 */
		protected function createScheduler($checkPeriod) {
			return new Puc_v4p4_Scheduler($this, $checkPeriod, array('load-themes.php'));
		}

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
			return new Puc_v4p4_DebugBar_Extension($this, 'Puc_v4p4_DebugBar_ThemePanel');
		}

		/**
		 * Register a callback for filtering query arguments.
		 *
		 * The callback function should take one argument - an associative array of query arguments.
		 * It should return a modified array of query arguments.
		 *
		 * @param callable $callback
		 * @return void
		 */
		public function addQueryArgFilter($callback){
			$this->addFilter('request_update_query_args', $callback);
		}

		/**
		 * Register a callback for filtering arguments passed to wp_remote_get().
		 *
		 * The callback function should take one argument - an associative array of arguments -
		 * and return a modified array or arguments. See the WP documentation on wp_remote_get()
		 * for details on what arguments are available and how they work.
		 *
		 * @uses add_filter() This method is a convenience wrapper for add_filter().
		 *
		 * @param callable $callback
		 * @return void
		 */
		public function addHttpRequestArgFilter($callback) {
			$this->addFilter('request_update_options', $callback);
		}

		/**
		 * Register a callback for filtering theme updates retrieved from the external API.
		 *
		 * The callback function should take two arguments. If the theme update was retrieved
		 * successfully, the first argument passed will be an instance of Theme_Update. Otherwise,
		 * it will be NULL. The second argument will be the corresponding return value of
		 * wp_remote_get (see WP docs for details).
		 *
		 * The callback function should return a new or modified instance of Theme_Update or NULL.
		 *
		 * @uses add_filter() This method is a convenience wrapper for add_filter().
		 *
		 * @param callable $callback
		 * @return void
		 */
		public function addResultFilter($callback) {
			$this->addFilter('request_update_result', $callback, 10, 2);
		}

		/**
		 * @return array
		 */
		protected function getHeaderNames() {
			return array(
				'Name'        => 'Theme Name',
				'ThemeURI'    => 'Theme URI',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'Version'     => 'Version',
				'Template'    => 'Template',
				'Status'      => 'Status',
				'Tags'        => 'Tags',
				'TextDomain'  => 'Text Domain',
				'DomainPath'  => 'Domain Path',
			);
		}
	}

endif;