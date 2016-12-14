<?php
if ( !class_exists('Puc_v4_Plugin_UpdateChecker', false) ):

	/**
	 * A custom plugin update checker.
	 *
	 * @author Janis Elsts
	 * @copyright 2016
	 * @access public
	 */
	class Puc_v4_Plugin_UpdateChecker extends Puc_v4_UpdateChecker {
		protected $updateClass = 'Puc_v4_Plugin_Update';
		protected $updateTransient = 'update_plugins';
		protected $translationType = 'plugin';

		public $pluginAbsolutePath = ''; //Full path of the main plugin file.
		public $pluginFile = '';  //Plugin filename relative to the plugins directory. Many WP APIs use this to identify plugins.
		public $muPluginFile = ''; //For MU plugins, the plugin filename relative to the mu-plugins directory.

		protected $upgraderStatus;

		private $debugBarPlugin = null;
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

			$this->upgraderStatus = new Puc_v4_UpgraderStatus();

			parent::__construct($metadataUrl, $slug, $checkPeriod, $optionName);
		}

		/**
		 * Create an instance of the scheduler.
		 *
		 * This is implemented as a method to make it possible for plugins to subclass the update checker
		 * and substitute their own scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4_Scheduler
		 */
		protected function createScheduler($checkPeriod) {
			$scheduler = new Puc_v4_Scheduler($this, $checkPeriod, array('load-plugins.php'));
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

			//Insert our update info into the update array maintained by WP.
			add_filter('site_transient_update_plugins', array($this,'injectUpdate')); //WP 3.0+
			add_filter('transient_update_plugins', array($this,'injectUpdate')); //WP 2.8+

			add_filter('plugin_row_meta', array($this, 'addCheckForUpdatesLink'), 10, 2);
			add_action('admin_init', array($this, 'handleManualCheck'));
			add_action('all_admin_notices', array($this, 'displayManualCheckResult'));

			//Clear the version number cache when something - anything - is upgraded or WP clears the update cache.
			add_filter('upgrader_post_install', array($this, 'clearCachedVersion'));
			add_action('delete_site_transient_update_plugins', array($this, 'clearCachedVersion'));

			if ( did_action('plugins_loaded') ) {
				$this->initDebugBarPanel();
			} else {
				add_action('plugins_loaded', array($this, 'initDebugBarPanel'));
			}

			//Rename the update directory to be the same as the existing directory.
			add_filter('upgrader_source_selection', array($this, 'fixDirectoryName'), 10, 3);

			//Enable language support (i18n).
			//TODO: The directory path has changed.
			load_plugin_textdomain('plugin-update-checker', false, plugin_basename(dirname(__FILE__)) . '/languages');

			parent::installHooks();
		}

		/**
		 * Retrieve plugin info from the configured API endpoint.
		 *
		 * @uses wp_remote_get()
		 *
		 * @param array $queryArgs Additional query arguments to append to the request. Optional.
		 * @return Puc_v4_Plugin_Info
		 */
		public function requestInfo($queryArgs = array()){
			//Query args to append to the URL. Plugins can add their own by using a filter callback (see addQueryArgFilter()).
			$installedVersion = $this->getInstalledVersion();
			$queryArgs['installed_version'] = ($installedVersion !== null) ? $installedVersion : '';
			$queryArgs = apply_filters('puc_request_info_query_args-'.$this->slug, $queryArgs);

			//Various options for the wp_remote_get() call. Plugins can filter these, too.
			$options = array(
				'timeout' => 10, //seconds
				'headers' => array(
					'Accept' => 'application/json'
				),
			);
			$options = apply_filters('puc_request_info_options-'.$this->slug, $options);

			//The plugin info should be at 'http://your-api.com/url/here/$slug/info.json'
			$url = $this->metadataUrl;
			if ( !empty($queryArgs) ){
				$url = add_query_arg($queryArgs, $url);
			}

			$result = wp_remote_get(
				$url,
				$options
			);

			//Try to parse the response
			$status = $this->validateApiResponse($result);
			$pluginInfo = null;
			if ( !is_wp_error($status) ){
				$pluginInfo = Puc_v4_Plugin_Info::fromJson($result['body']);
				if ( $pluginInfo !== null ) {
					$pluginInfo->filename = $this->pluginFile;
					$pluginInfo->slug = $this->slug;
				}
			} else {
				$this->triggerError(
					sprintf('The URL %s does not point to a valid plugin metadata file. ', $url)
					. $status->get_error_message(),
					E_USER_WARNING
				);
			}

			$pluginInfo = apply_filters('puc_request_info_result-'.$this->slug, $pluginInfo, $result);
			return $pluginInfo;
		}

		/**
		 * Check if $result is a successful update API response.
		 *
		 * @param array|WP_Error $result
		 * @return true|WP_Error
		 */
		protected function validateApiResponse($result) {
			if ( is_wp_error($result) ) { /** @var WP_Error $result */
				return new WP_Error($result->get_error_code(), 'WP HTTP Error: ' . $result->get_error_message());
			}

			if ( !isset($result['response']['code']) ) {
				return new WP_Error('puc_no_response_code', 'wp_remote_get() returned an unexpected result.');
			}

			if ( $result['response']['code'] !== 200 ) {
				return new WP_Error(
					'puc_unexpected_response_code',
					'HTTP response code is ' . $result['response']['code'] . ' (expected: 200)'
				);
			}

			if ( empty($result['body']) ) {
				return new WP_Error('puc_empty_response', 'The metadata file appears to be empty.');
			}

			return true;
		}

		/**
		 * Retrieve the latest update (if any) from the configured API endpoint.
		 *
		 * @uses PluginUpdateChecker::requestInfo()
		 *
		 * @return Puc_v4_Plugin_Update An instance of PluginUpdate, or NULL when no updates are available.
		 */
		public function requestUpdate() {
			//For the sake of simplicity, this function just calls requestInfo()
			//and transforms the result accordingly.
			$pluginInfo = $this->requestInfo(array('checking_for_updates' => '1'));
			if ( $pluginInfo == null ){
				return null;
			}
			$update = Puc_v4_Plugin_Update::fromPluginInfo($pluginInfo);

			//Keep only those translation updates that apply to this site.
			$update->translations = $this->filterApplicableTranslations($update->translations);

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
			$pluginInfo = apply_filters('puc_pre_inject_info-' . $this->slug, $pluginInfo);
			if ( $pluginInfo ) {
				return $pluginInfo->toWpFormat();
			}

			return $result;
		}

		/**
		 * Insert the latest update (if any) into the update list maintained by WP.
		 *
		 * @param StdClass $updates Update list.
		 * @return StdClass Modified update list.
		 */
		public function injectUpdate($updates){
			//TODO: Unify this.

			//Is there an update to insert?
			$update = $this->getUpdate();

			if ( !$this->shouldShowUpdates() ) {
				$update = null;
			}

			if ( !empty($update) ) {
				//Let plugins filter the update info before it's passed on to WordPress.
				$update = apply_filters($this->getFilterName('pre_inject_update'), $update);
				$updates = $this->addUpdateToList($updates, $update);
			} else {
				//Clean up any stale update info.
				$updates = $this->removeUpdateFromList($updates);
			}

			return $updates;
		}

		protected function shouldShowUpdates() {
			//No update notifications for mu-plugins unless explicitly enabled. The MU plugin file
			//is usually different from the main plugin file so the update wouldn't show up properly anyway.
			return !$this->isUnknownMuPlugin();
		}

		/**
		 * @param StdClass|null $updates
		 * @param Puc_v4_Plugin_Update $updateToAdd
		 * @return StdClass
		 */
		private function addUpdateToList($updates, $updateToAdd) {
			if ( !is_object($updates) ) {
				$updates = new stdClass();
				$updates->response = array();
			}

			$wpUpdate = $updateToAdd->toWpFormat();
			$pluginFile = $this->pluginFile;

			if ( $this->isMuPlugin() ) {
				//WP does not support automatic update installation for mu-plugins, but we can still display a notice.
				$wpUpdate->package = null;
				$pluginFile = $this->muPluginFile;
			}
			$updates->response[$pluginFile] = $wpUpdate;
			return $updates;
		}

		/**
		 * @param stdClass|null $updates
		 * @return stdClass|null
		 */
		private function removeUpdateFromList($updates) {
			if ( isset($updates, $updates->response) ) {
				unset($updates->response[$this->pluginFile]);
				if ( !empty($this->muPluginFile) ) {
					unset($updates->response[$this->muPluginFile]);
				}
			}
			return $updates;
		}

		/**
		 * Rename the update directory to match the existing plugin directory.
		 *
		 * When WordPress installs a plugin or theme update, it assumes that the ZIP file will contain
		 * exactly one directory, and that the directory name will be the same as the directory where
		 * the plugin/theme is currently installed.
		 *
		 * GitHub and other repositories provide ZIP downloads, but they often use directory names like
		 * "project-branch" or "project-tag-hash". We need to change the name to the actual plugin folder.
		 *
		 * This is a hook callback. Don't call it from a plugin.
		 *
		 * @param string $source The directory to copy to /wp-content/plugins. Usually a subdirectory of $remoteSource.
		 * @param string $remoteSource WordPress has extracted the update to this directory.
		 * @param WP_Upgrader $upgrader
		 * @return string|WP_Error
		 */
		public function fixDirectoryName($source, $remoteSource, $upgrader) {
			global $wp_filesystem; /** @var WP_Filesystem_Base $wp_filesystem */

			//Basic sanity checks.
			if ( !isset($source, $remoteSource, $upgrader, $upgrader->skin, $wp_filesystem) ) {
				return $source;
			}

			//If WordPress is upgrading anything other than our plugin, leave the directory name unchanged.
			if ( !$this->isPluginBeingUpgraded($upgrader) ) {
				return $source;
			}

			//Rename the source to match the existing plugin directory.
			$pluginDirectoryName = dirname($this->pluginFile);
			if ( $pluginDirectoryName === '.' ) {
				return $source;
			}
			$correctedSource = trailingslashit($remoteSource) . $pluginDirectoryName . '/';
			if ( $source !== $correctedSource ) {
				//The update archive should contain a single directory that contains the rest of plugin files. Otherwise,
				//WordPress will try to copy the entire working directory ($source == $remoteSource). We can't rename
				//$remoteSource because that would break WordPress code that cleans up temporary files after update.
				if ( $this->isBadDirectoryStructure($remoteSource) ) {
					return new WP_Error(
						'puc-incorrect-directory-structure',
						sprintf(
							'The directory structure of the update is incorrect. All plugin files should be inside ' .
							'a directory named <span class="code">%s</span>, not at the root of the ZIP file.',
							htmlentities($this->slug)
						)
					);
				}

				/** @var WP_Upgrader_Skin $upgrader->skin */
				$upgrader->skin->feedback(sprintf(
					'Renaming %s to %s&#8230;',
					'<span class="code">' . basename($source) . '</span>',
					'<span class="code">' . $pluginDirectoryName . '</span>'
				));

				if ( $wp_filesystem->move($source, $correctedSource, true) ) {
					$upgrader->skin->feedback('Plugin directory successfully renamed.');
					return $correctedSource;
				} else {
					return new WP_Error(
						'puc-rename-failed',
						'Unable to rename the update to match the existing plugin directory.'
					);
				}
			}

			return $source;
		}

		/**
		 * Check for incorrect update directory structure. An update must contain a single directory,
		 * all other files should be inside that directory.
		 *
		 * @param string $remoteSource Directory path.
		 * @return bool
		 */
		private function isBadDirectoryStructure($remoteSource) {
			global $wp_filesystem; /** @var WP_Filesystem_Base $wp_filesystem */

			$sourceFiles = $wp_filesystem->dirlist($remoteSource);
			if ( is_array($sourceFiles) ) {
				$sourceFiles = array_keys($sourceFiles);
				$firstFilePath = trailingslashit($remoteSource) . $sourceFiles[0];
				return (count($sourceFiles) > 1) || (!$wp_filesystem->is_dir($firstFilePath));
			}

			//Assume it's fine.
			return false;
		}

		/**
		 * Is there and update being installed RIGHT NOW, for this specific plugin?
		 *
		 * @param WP_Upgrader|null $upgrader The upgrader that's performing the current update.
		 * @return bool
		 */
		public function isPluginBeingUpgraded($upgrader = null) {
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
		 * @return Puc_v4_Plugin_Update|null
		 */
		public function getUpdate() {
			$update = parent::getUpdate();
			if ( isset($update) ) {
				$update->filename = $this->pluginFile;
			}
			return $update;
		}

		/**
		 * Add a "Check for updates" link to the plugin row in the "Plugins" page. By default,
		 * the new link will appear after the "Visit plugin site" link.
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

				$linkText = apply_filters('puc_manual_check_link-' . $this->slug, __('Check for updates', 'plugin-update-checker'));
				if ( !empty($linkText) ) {
					/** @noinspection HtmlUnknownTarget */
					$pluginMeta[] = sprintf('<a href="%s">%s</a>', esc_attr($linkUrl), $linkText);
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
				if ( $status == 'no_update' ) {
					$message = __('This plugin is up to date.', 'plugin-update-checker');
				} else if ( $status == 'update_available' ) {
					$message = __('A new version of this plugin is available.', 'plugin-update-checker');
				} else {
					$message = sprintf(__('Unknown update checker status "%s"', 'plugin-update-checker'), htmlentities($status));
				}
				printf(
					'<div class="updated notice is-dismissible"><p>%s</p></div>',
					apply_filters('puc_manual_check_message-' . $this->slug, $message, $status)
				);
			}
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
			add_filter('puc_request_info_query_args-'.$this->slug, $callback);
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
		public function addHttpRequestArgFilter($callback){
			add_filter('puc_request_info_options-'.$this->slug, $callback);
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
		public function addResultFilter($callback){
			add_filter('puc_request_info_result-'.$this->slug, $callback, 10, 2);
		}

		/**
		 * Initialize the update checker Debug Bar plugin/add-on thingy.
		 */
		public function initDebugBarPanel() {
			$debugBarPlugin = dirname(__FILE__) . '/../../../debug-bar-plugin.php';
			if ( class_exists('Debug_Bar', false) && file_exists($debugBarPlugin) ) {
				/** @noinspection PhpIncludeInspection */
				require_once $debugBarPlugin;
				$this->debugBarPlugin = new PucDebugBarPlugin_3_2($this);
			}
		}


	}

endif;