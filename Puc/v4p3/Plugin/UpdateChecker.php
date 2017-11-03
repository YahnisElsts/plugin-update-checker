<?php
if ( !class_exists('Puc_v4p3_Plugin_UpdateChecker', false) ):

	/**
	 * A custom plugin update checker.
	 *
	 * @author Janis Elsts
	 * @copyright 2016
	 * @access public
	 */
	class Puc_v4p3_Plugin_UpdateChecker extends Puc_v4p3_UpdateChecker {
		protected $updateTransient = 'update_plugins';
		protected $translationType = 'plugin';

		public $pluginAbsolutePath = ''; //Full path of the main plugin file.
		public $pluginFile = '';  //Plugin filename relative to the plugins directory. Many WP APIs use this to identify plugins.
		public $muPluginFile = ''; //For MU plugins, the plugin filename relative to the mu-plugins directory.

		private $cachedInstalledVersion = null;

		/**
		 * Class constructor.
		 *
		 * @param string $metadataUrl The URL of the plugin's metadata file.
		 * @param string $pluginFile Fully qualified path to the main plugin file.
		 * @param string $slug The plugin's 'slug'. If not specified, the filename part of $pluginFile sans '.php' will be used as the slug.
		 * @param integer $checkPeriod How often to check for updates (in hours). Defaults to checking every 12 hours. Set to 0 to disable automatic update checks.
		 * @param string $optionName Where to store book-keeping info about update checks. Defaults to 'external_updates-$slug'.
		 * @param string $muPluginFile Optional. The plugin filename relative to the mu-plugins directory.
		 */
		public function __construct($metadataUrl, $pluginFile, $slug = '', $checkPeriod = 12, $optionName = '', $muPluginFile = ''){
			$this->pluginAbsolutePath = $pluginFile;
			$this->pluginFile = plugin_basename($this->pluginAbsolutePath);
			$this->muPluginFile = $muPluginFile;

			//If no slug is specified, use the name of the main plugin file as the slug.
			//For example, 'my-cool-plugin/cool-plugin.php' becomes 'cool-plugin'.
			if ( empty($slug) ){
				$slug = basename($this->pluginFile, '.php');
			}

			//Plugin slugs must be unique.
			$slugCheckFilter = 'puc_is_slug_in_use-' . $this->slug;
			$slugUsedBy = apply_filters($slugCheckFilter, false);
			if ( $slugUsedBy ) {
				$this->triggerError(sprintf(
					'Plugin slug "%s" is already in use by %s. Slugs must be unique.',
					htmlentities($this->slug),
					htmlentities($slugUsedBy)
				), E_USER_ERROR);
			}
			add_filter($slugCheckFilter, array($this, 'getAbsolutePath'));

			//Backwards compatibility: If the plugin is a mu-plugin but no $muPluginFile is specified, assume
			//it's the same as $pluginFile given that it's not in a subdirectory (WP only looks in the base dir).
			if ( (strpbrk($this->pluginFile, '/\\') === false) && $this->isUnknownMuPlugin() ) {
				$this->muPluginFile = $this->pluginFile;
			}

			//To prevent a crash during plugin uninstallation, remove updater hooks when the user removes the plugin.
			//Details: https://github.com/YahnisElsts/plugin-update-checker/issues/138#issuecomment-335590964
			add_action('uninstall_' . $this->pluginFile, array($this, 'removeHooks'));

			parent::__construct($metadataUrl, dirname($this->pluginFile), $slug, $checkPeriod, $optionName);
		}

		/**
		 * Create an instance of the scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4p3_Scheduler
		 */
		protected function createScheduler($checkPeriod) {
			$scheduler = new Puc_v4p3_Scheduler($this, $checkPeriod, array('load-plugins.php'));
			register_deactivation_hook($this->pluginFile, array($scheduler, 'removeUpdaterCron'));
			return $scheduler;
		}

		/**
		 * Install the hooks required to run periodic update checks and inject update info
		 * into WP data structures.
		 *
		 * @return void
		 */
		protected function installHooks(){
			//Override requests for plugin information
			add_filter('plugins_api', array($this, 'injectInfo'), 20, 3);

			add_filter('plugin_row_meta', array($this, 'addViewDetailsLink'), 10, 3);
			add_filter('plugin_row_meta', array($this, 'addCheckForUpdatesLink'), 10, 2);
			add_action('admin_init', array($this, 'handleManualCheck'));
			add_action('all_admin_notices', array($this, 'displayManualCheckResult'));

			//Clear the version number cache when something - anything - is upgraded or WP clears the update cache.
			add_filter('upgrader_post_install', array($this, 'clearCachedVersion'));
			add_action('delete_site_transient_update_plugins', array($this, 'clearCachedVersion'));

			parent::installHooks();
		}

		/**
		 * Remove update checker hooks.
		 *
		 * The intent is to prevent a fatal error that can happen if the plugin has an uninstall
		 * hook. During uninstallation, WP includes the main plugin file (which creates a PUC instance),
		 * the uninstall hook runs, WP deletes the plugin files and then updates some transients.
		 * If PUC hooks are still around at this time, they could throw an error while trying to
		 * autoload classes from files that no longer exist.
		 *
		 * The "site_transient_{$transient}" filter is the main problem here, but let's also remove
		 * most other PUC hooks to be safe.
		 *
		 * @internal
		 */
		public function removeHooks() {
			parent::removeHooks();

			remove_filter('plugins_api', array($this, 'injectInfo'), 20);

			remove_filter('plugin_row_meta', array($this, 'addViewDetailsLink'), 10);
			remove_filter('plugin_row_meta', array($this, 'addCheckForUpdatesLink'), 10);
			remove_action('admin_init', array($this, 'handleManualCheck'));
			remove_action('all_admin_notices', array($this, 'displayManualCheckResult'));

			remove_filter('upgrader_post_install', array($this, 'clearCachedVersion'));
			remove_action('delete_site_transient_update_plugins', array($this, 'clearCachedVersion'));
		}

		/**
		 * Retrieve plugin info from the configured API endpoint.
		 *
		 * @uses wp_remote_get()
		 *
		 * @param array $queryArgs Additional query arguments to append to the request. Optional.
		 * @return Puc_v4p3_Plugin_Info
		 */
		public function requestInfo($queryArgs = array()) {
			list($pluginInfo, $result) = $this->requestMetadata('Puc_v4p3_Plugin_Info', 'request_info', $queryArgs);

			if ( $pluginInfo !== null ) {
				/** @var Puc_v4p3_Plugin_Info $pluginInfo */
				$pluginInfo->filename = $this->pluginFile;
				$pluginInfo->slug = $this->slug;
			}

			$pluginInfo = apply_filters($this->getUniqueName('request_info_result'), $pluginInfo, $result);
			return $pluginInfo;
		}

		/**
		 * Retrieve the latest update (if any) from the configured API endpoint.
		 *
		 * @uses PluginUpdateChecker::requestInfo()
		 *
		 * @return Puc_v4p3_Update|null An instance of Plugin_Update, or NULL when no updates are available.
		 */
		public function requestUpdate() {
			//For the sake of simplicity, this function just calls requestInfo()
			//and transforms the result accordingly.
			$pluginInfo = $this->requestInfo(array('checking_for_updates' => '1'));
			if ( $pluginInfo === null ){
				return null;
			}
			$update = Puc_v4p3_Plugin_Update::fromPluginInfo($pluginInfo);

			$update = $this->filterUpdateResult($update);

			return $update;
		}

		/**
		 * Get the currently installed version of the plugin.
		 *
		 * @return string Version number.
		 */
		public function getInstalledVersion(){
			if ( isset($this->cachedInstalledVersion) ) {
				return $this->cachedInstalledVersion;
			}

			$pluginHeader = $this->getPluginHeader();
			if ( isset($pluginHeader['Version']) ) {
				$this->cachedInstalledVersion = $pluginHeader['Version'];
				return $pluginHeader['Version'];
			} else {
				//This can happen if the filename points to something that is not a plugin.
				$this->triggerError(
					sprintf(
						"Can't to read the Version header for '%s'. The filename is incorrect or is not a plugin.",
						$this->pluginFile
					),
					E_USER_WARNING
				);
				return null;
			}
		}

		/**
		 * Get plugin's metadata from its file header.
		 *
		 * @return array
		 */
		protected function getPluginHeader() {
			if ( !is_file($this->pluginAbsolutePath) ) {
				//This can happen if the plugin filename is wrong.
				$this->triggerError(
					sprintf(
						"Can't to read the plugin header for '%s'. The file does not exist.",
						$this->pluginFile
					),
					E_USER_WARNING
				);
				return array();
			}

			if ( !function_exists('get_plugin_data') ){
				/** @noinspection PhpIncludeInspection */
				require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
			}
			return get_plugin_data($this->pluginAbsolutePath, false, false);
		}

		/**
		 * @return array
		 */
		protected function getHeaderNames() {
			return array(
				'Name' => 'Plugin Name',
				'PluginURI' => 'Plugin URI',
				'Version' => 'Version',
				'Description' => 'Description',
				'Author' => 'Author',
				'AuthorURI' => 'Author URI',
				'TextDomain' => 'Text Domain',
				'DomainPath' => 'Domain Path',
				'Network' => 'Network',

				//The newest WordPress version that this plugin requires or has been tested with.
				//We support several different formats for compatibility with other libraries.
				'Tested WP' => 'Tested WP',
				'Requires WP' => 'Requires WP',
				'Tested up to' => 'Tested up to',
				'Requires at least' => 'Requires at least',
			);
		}


		/**
		 * Intercept plugins_api() calls that request information about our plugin and
		 * use the configured API endpoint to satisfy them.
		 *
		 * @see plugins_api()
		 *
		 * @param mixed $result
		 * @param string $action
		 * @param array|object $args
		 * @return mixed
		 */
		public function injectInfo($result, $action = null, $args = null){
			$relevant = ($action == 'plugin_information') && isset($args->slug) && (
					($args->slug == $this->slug) || ($args->slug == dirname($this->pluginFile))
				);
			if ( !$relevant ) {
				return $result;
			}

			$pluginInfo = $this->requestInfo();
			$pluginInfo = apply_filters($this->getUniqueName('pre_inject_info'), $pluginInfo);
			if ( $pluginInfo ) {
				return $pluginInfo->toWpFormat();
			}

			return $result;
		}

		protected function shouldShowUpdates() {
			//No update notifications for mu-plugins unless explicitly enabled. The MU plugin file
			//is usually different from the main plugin file so the update wouldn't show up properly anyway.
			return !$this->isUnknownMuPlugin();
		}

		/**
		 * @param stdClass|null $updates
		 * @param stdClass $updateToAdd
		 * @return stdClass
		 */
		protected function addUpdateToList($updates, $updateToAdd) {
			if ( $this->isMuPlugin() ) {
				//WP does not support automatic update installation for mu-plugins, but we can
				//still display a notice.
				$updateToAdd->package = null;
			}
			return parent::addUpdateToList($updates, $updateToAdd);
		}

		/**
		 * @param stdClass|null $updates
		 * @return stdClass|null
		 */
		protected function removeUpdateFromList($updates) {
			$updates = parent::removeUpdateFromList($updates);
			if ( !empty($this->muPluginFile) && isset($updates, $updates->response) ) {
				unset($updates->response[$this->muPluginFile]);
			}
			return $updates;
		}

		/**
		 * For plugins, the update array is indexed by the plugin filename relative to the "plugins"
		 * directory. Example: "plugin-name/plugin.php".
		 *
		 * @return string
		 */
		protected function getUpdateListKey() {
			if ( $this->isMuPlugin() ) {
				return $this->muPluginFile;
			}
			return $this->pluginFile;
		}

		/**
		 * Alias for isBeingUpgraded().
		 *
		 * @deprecated
		 * @param WP_Upgrader|null $upgrader The upgrader that's performing the current update.
		 * @return bool
		 */
		public function isPluginBeingUpgraded($upgrader = null) {
			return $this->isBeingUpgraded($upgrader);
		}

		/**
		 * Is there an update being installed for this plugin, right now?
		 *
		 * @param WP_Upgrader|null $upgrader
		 * @return bool
		 */
		public function isBeingUpgraded($upgrader = null) {
			return $this->upgraderStatus->isPluginBeingUpgraded($this->pluginFile, $upgrader);
		}

		/**
		 * Get the details of the currently available update, if any.
		 *
		 * If no updates are available, or if the last known update version is below or equal
		 * to the currently installed version, this method will return NULL.
		 *
		 * Uses cached update data. To retrieve update information straight from
		 * the metadata URL, call requestUpdate() instead.
		 *
		 * @return Puc_v4p3_Plugin_Update|null
		 */
		public function getUpdate() {
			$update = parent::getUpdate();
			if ( isset($update) ) {
				/** @var Puc_v4p3_Plugin_Update $update */
				$update->filename = $this->pluginFile;
			}
			return $update;
		}

		/**
		 * Add a "Check for updates" link to the plugin row in the "Plugins" page. By default,
		 * the new link will appear after the "Visit plugin site" link if present, otherwise
		 * after the "View plugin details" link.
		 *
		 * You can change the link text by using the "puc_manual_check_link-$slug" filter.
		 * Returning an empty string from the filter will disable the link.
		 *
		 * @param array $pluginMeta Array of meta links.
		 * @param string $pluginFile
		 * @return array
		 */
		public function addCheckForUpdatesLink($pluginMeta, $pluginFile) {
			$isRelevant = ($pluginFile == $this->pluginFile)
				|| (!empty($this->muPluginFile) && $pluginFile == $this->muPluginFile);

			if ( $isRelevant && $this->userCanInstallUpdates() ) {
				$linkUrl = wp_nonce_url(
					add_query_arg(
						array(
							'puc_check_for_updates' => 1,
							'puc_slug' => $this->slug,
						),
						self_admin_url('plugins.php')
					),
					'puc_check_for_updates'
				);

				$linkText = apply_filters(
					$this->getUniqueName('manual_check_link'),
					__('Check for updates', 'plugin-update-checker')
				);
				if ( !empty($linkText) ) {
					/** @noinspection HtmlUnknownTarget */
					$pluginMeta[] = sprintf('<a href="%s">%s</a>', esc_attr($linkUrl), $linkText);
				}
			}
			return $pluginMeta;
		}

		/**
		 * Add a "View Details" link to the plugin row in the "Plugins" page. By default,
		 * the new link will appear before the "Visit plugin site" link (if present).
		 *
		 * You can change the link text by using the "puc_view_details_link-$slug" filter.
		 * Returning an empty string from the filter will disable the link.
		 *
		 * You can change the position of the link using the
		 * "puc_view_details_link_position-$slug" filter.
		 * Returning 'before' or 'after' will place the link immediately before/after the
		 * "Visit plugin site" link
		 * Returning 'append' places the link after any existing links at the time of the hook.
		 * Returning 'replace' replaces the "Visit plugin site" link
		 * Returning anything else disables the link when there is a "Visit plugin site" link.
		 * 
		 * If there is no "Visit plugin site" link 'append' is always used!
		 *
		 * @param array $pluginMeta Array of meta links.
		 * @param string $pluginFile
		 * @param array $pluginData Array of plugin header data.
		 * @return array
		 */
		public function addViewDetailsLink($pluginMeta, $pluginFile, $pluginData = array()) {
			$isRelevant = ($pluginFile == $this->pluginFile)
				|| (!empty($this->muPluginFile) && $pluginFile == $this->muPluginFile);

			if ( $isRelevant && $this->userCanInstallUpdates() && !isset($pluginData['slug']) ) {
				$linkText = apply_filters($this->getUniqueName('view_details_link'), __('View details'));
				if ( !empty($linkText) ) {
					$viewDetailsLinkPosition = 'append';

					//Find the "Visit plugin site" link (if present).
					$visitPluginSiteLinkIndex = count($pluginMeta) - 1;
					if ( $pluginData['PluginURI'] ) {
						$escapedPluginUri = esc_url($pluginData['PluginURI']);
						foreach ($pluginMeta as $linkIndex => $existingLink) {
							if ( strpos($existingLink, $escapedPluginUri) !== false ) {
								$visitPluginSiteLinkIndex = $linkIndex;
								$viewDetailsLinkPosition = apply_filters(
									$this->getUniqueName('view_details_link_position'),
									'before'
								);
								break;
							}
						}
					}

					$viewDetailsLink = sprintf('<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
						esc_url(network_admin_url('plugin-install.php?tab=plugin-information&plugin=' . urlencode($this->slug) .
							'&TB_iframe=true&width=600&height=550')),
						esc_attr(sprintf(__('More information about %s'), $pluginData['Name'])),
						esc_attr($pluginData['Name']),
						$linkText
					);
					switch ($viewDetailsLinkPosition) {
						case 'before':
							array_splice($pluginMeta, $visitPluginSiteLinkIndex, 0, $viewDetailsLink);
							break;
						case 'after':
							array_splice($pluginMeta, $visitPluginSiteLinkIndex + 1, 0, $viewDetailsLink);
							break;
						case 'replace':
							$pluginMeta[$visitPluginSiteLinkIndex] = $viewDetailsLink;
							break;
						case 'append':
						default:
							$pluginMeta[] = $viewDetailsLink;
							break;
					}
				}
			}
			return $pluginMeta;
		}


		/**
		 * Check for updates when the user clicks the "Check for updates" link.
		 * @see self::addCheckForUpdatesLink()
		 *
		 * @return void
		 */
		public function handleManualCheck() {
			$shouldCheck =
				isset($_GET['puc_check_for_updates'], $_GET['puc_slug'])
				&& $_GET['puc_slug'] == $this->slug
				&& $this->userCanInstallUpdates()
				&& check_admin_referer('puc_check_for_updates');

			if ( $shouldCheck ) {
				$update = $this->checkForUpdates();
				$status = ($update === null) ? 'no_update' : 'update_available';
				wp_redirect(add_query_arg(
					array(
						'puc_update_check_result' => $status,
						'puc_slug' => $this->slug,
					),
					self_admin_url('plugins.php')
				));
			}
		}

		/**
		 * Display the results of a manual update check.
		 * @see self::handleManualCheck()
		 *
		 * You can change the result message by using the "puc_manual_check_message-$slug" filter.
		 */
		public function displayManualCheckResult() {
			if ( isset($_GET['puc_update_check_result'], $_GET['puc_slug']) && ($_GET['puc_slug'] == $this->slug) ) {
				$status = strval($_GET['puc_update_check_result']);
				$title  = $this->getPluginTitle();
				if ( $status == 'no_update' ) {
					$message = sprintf(_x('The %s plugin is up to date.', 'the plugin title', 'plugin-update-checker'), $title);
				} else if ( $status == 'update_available' ) {
					$message = sprintf(_x('A new version of the %s plugin is available.', 'the plugin title', 'plugin-update-checker'), $title);
				} else {
					$message = sprintf(__('Unknown update checker status "%s"', 'plugin-update-checker'), htmlentities($status));
				}
				printf(
					'<div class="updated notice is-dismissible"><p>%s</p></div>',
					apply_filters($this->getUniqueName('manual_check_message'), $message, $status)
				);
			}
		}

		/**
		 * Get the translated plugin title.
		 *
		 * @return string
		 */
		protected function getPluginTitle() {
			$title  = '';
			$header = $this->getPluginHeader();
			if ( $header && !empty($header['Name']) && isset($header['TextDomain']) ) {
				$title = translate($header['Name'], $header['TextDomain']);
			}
			return $title;
		}

		/**
		 * Check if the current user has the required permissions to install updates.
		 *
		 * @return bool
		 */
		public function userCanInstallUpdates() {
			return current_user_can('update_plugins');
		}

		/**
		 * Check if the plugin file is inside the mu-plugins directory.
		 *
		 * @return bool
		 */
		protected function isMuPlugin() {
			static $cachedResult = null;

			if ( $cachedResult === null ) {
				//Convert both paths to the canonical form before comparison.
				$muPluginDir = realpath(WPMU_PLUGIN_DIR);
				$pluginPath  = realpath($this->pluginAbsolutePath);

				$cachedResult = (strpos($pluginPath, $muPluginDir) === 0);
			}

			return $cachedResult;
		}

		/**
		 * MU plugins are partially supported, but only when we know which file in mu-plugins
		 * corresponds to this plugin.
		 *
		 * @return bool
		 */
		protected function isUnknownMuPlugin() {
			return empty($this->muPluginFile) && $this->isMuPlugin();
		}

		/**
		 * Clear the cached plugin version. This method can be set up as a filter (hook) and will
		 * return the filter argument unmodified.
		 *
		 * @param mixed $filterArgument
		 * @return mixed
		 */
		public function clearCachedVersion($filterArgument = null) {
			$this->cachedInstalledVersion = null;
			return $filterArgument;
		}

		/**
		 * Get absolute path to the main plugin file.
		 *
		 * @return string
		 */
		public function getAbsolutePath() {
			return $this->pluginAbsolutePath;
		}

		/**
		 * @return string
		 */
		public function getAbsoluteDirectoryPath() {
			return dirname($this->pluginAbsolutePath);
		}

		/**
		 * Register a callback for filtering query arguments.
		 *
		 * The callback function should take one argument - an associative array of query arguments.
		 * It should return a modified array of query arguments.
		 *
		 * @uses add_filter() This method is a convenience wrapper for add_filter().
		 *
		 * @param callable $callback
		 * @return void
		 */
		public function addQueryArgFilter($callback){
			$this->addFilter('request_info_query_args', $callback);
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
			$this->addFilter('request_info_options', $callback);
		}

		/**
		 * Register a callback for filtering the plugin info retrieved from the external API.
		 *
		 * The callback function should take two arguments. If the plugin info was retrieved
		 * successfully, the first argument passed will be an instance of  PluginInfo. Otherwise,
		 * it will be NULL. The second argument will be the corresponding return value of
		 * wp_remote_get (see WP docs for details).
		 *
		 * The callback function should return a new or modified instance of PluginInfo or NULL.
		 *
		 * @uses add_filter() This method is a convenience wrapper for add_filter().
		 *
		 * @param callable $callback
		 * @return void
		 */
		public function addResultFilter($callback) {
			$this->addFilter('request_info_result', $callback, 10, 2);
		}

		protected function createDebugBarExtension() {
			return new Puc_v4p3_DebugBar_PluginExtension($this);
		}
	}

endif;
