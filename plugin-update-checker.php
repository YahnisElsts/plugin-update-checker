<?php
/**
 * Plugin Update Checker Library 3.1
 * http://w-shadow.com/
 * 
 * Copyright 2016 Janis Elsts
 * Released under the MIT license. See license.txt for details.
 */

if ( !class_exists('PluginUpdateChecker_3_1', false) ):

/**
 * A custom plugin update checker. 
 * 
 * @author Janis Elsts
 * @copyright 2016
 * @version 3.0
 * @access public
 */
class PluginUpdateChecker_3_1 {
	public $metadataUrl = ''; //The URL of the plugin's metadata file.
	public $pluginAbsolutePath = ''; //Full path of the main plugin file.
	public $pluginFile = '';  //Plugin filename relative to the plugins directory. Many WP APIs use this to identify plugins.
	public $slug = '';        //Plugin slug.
	public $optionName = '';  //Where to store the update info.
	public $muPluginFile = ''; //For MU plugins, the plugin filename relative to the mu-plugins directory.

	public $debugMode = false; //Set to TRUE to enable error reporting. Errors are raised using trigger_error()
                               //and should be logged to the standard PHP error log.
	public $scheduler;

	protected $upgraderStatus;

	private $debugBarPlugin = null;
	private $cachedInstalledVersion = null;

	private $metadataHost = ''; //The host component of $metadataUrl.

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
		$this->metadataUrl = $metadataUrl;
		$this->pluginAbsolutePath = $pluginFile;
		$this->pluginFile = plugin_basename($this->pluginAbsolutePath);
		$this->muPluginFile = $muPluginFile;
		$this->slug = $slug;
		$this->optionName = $optionName;
		$this->debugMode = (bool)(constant('WP_DEBUG'));

		//If no slug is specified, use the name of the main plugin file as the slug.
		//For example, 'my-cool-plugin/cool-plugin.php' becomes 'cool-plugin'.
		if ( empty($this->slug) ){
			$this->slug = basename($this->pluginFile, '.php');
		}
		
		if ( empty($this->optionName) ){
			$this->optionName = 'external_updates-' . $this->slug;
		}

		//Backwards compatibility: If the plugin is a mu-plugin but no $muPluginFile is specified, assume
		//it's the same as $pluginFile given that it's not in a subdirectory (WP only looks in the base dir).
		if ( (strpbrk($this->pluginFile, '/\\') === false) && $this->isUnknownMuPlugin() ) {
			$this->muPluginFile = $this->pluginFile;
		}

		$this->scheduler = $this->createScheduler($checkPeriod);
		$this->upgraderStatus = new PucUpgraderStatus_3_1();

		$this->installHooks();
	}

	/**
	 * Create an instance of the scheduler.
	 *
	 * This is implemented as a method to make it possible for plugins to subclass the update checker
	 * and substitute their own scheduler.
	 *
	 * @param int $checkPeriod
	 * @return PucScheduler_3_1
	 */
	protected function createScheduler($checkPeriod) {
		return new PucScheduler_3_1($this, $checkPeriod);
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
		add_filter('site_transient_update_plugins', array($this, 'injectTranslationUpdates'));

		add_filter('plugin_row_meta', array($this, 'addCheckForUpdatesLink'), 10, 2);
		add_action('admin_init', array($this, 'handleManualCheck'));
		add_action('all_admin_notices', array($this, 'displayManualCheckResult'));

		//Clear the version number cache when something - anything - is upgraded or WP clears the update cache.
		add_filter('upgrader_post_install', array($this, 'clearCachedVersion'));
		add_action('delete_site_transient_update_plugins', array($this, 'clearCachedVersion'));
		//Clear translation updates when WP clears the update cache.
		//This needs to be done directly because the library doesn't actually remove obsolete plugin updates,
		//it just hides them (see getUpdate()). We can't do that with translations - too much disk I/O.
		add_action('delete_site_transient_update_plugins', array($this, 'clearCachedTranslationUpdates'));

		if ( did_action('plugins_loaded') ) {
			$this->initDebugBarPanel();
		} else {
			add_action('plugins_loaded', array($this, 'initDebugBarPanel'));
		}

		//Rename the update directory to be the same as the existing directory.
		add_filter('upgrader_source_selection', array($this, 'fixDirectoryName'), 10, 3);

		//Enable language support (i18n).
		load_plugin_textdomain('plugin-update-checker', false, plugin_basename(dirname(__FILE__)) . '/languages');

		//Allow HTTP requests to the metadata URL even if it's on a local host.
		$this->metadataHost = @parse_url($this->metadataUrl, PHP_URL_HOST);
		add_filter('http_request_host_is_external', array($this, 'allowMetadataHost'), 10, 2);
	}
	
	/**
	 * Explicitly allow HTTP requests to the metadata URL.
	 *
	 * WordPress has a security feature where the HTTP API will reject all requests that are sent to
	 * another site hosted on the same server as the current site (IP match), a local host, or a local
	 * IP, unless the host exactly matches the current site.
	 *
	 * This feature is opt-in (at least in WP 4.4). Apparently some people enable it.
	 *
	 * That can be a problem when you're developing your plugin and you decide to host the update information
	 * on the same server as your test site. Update requests will mysteriously fail.
	 *
	 * We fix that by adding an exception for the metadata host.
	 *
	 * @param bool $allow
	 * @param string $host
	 * @return bool
	 */
	public function allowMetadataHost($allow, $host) {
		if ( strtolower($host) === strtolower($this->metadataHost) ) {
			return true;
		}
		return $allow;
	}

	/**
	 * Retrieve plugin info from the configured API endpoint.
	 * 
	 * @uses wp_remote_get()
	 * 
	 * @param array $queryArgs Additional query arguments to append to the request. Optional.
	 * @return PluginInfo_3_1
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
			$pluginInfo = PluginInfo_3_1::fromJson($result['body']);
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
	private function validateApiResponse($result) {
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
	 * @return PluginUpdate_3_1 An instance of PluginUpdate, or NULL when no updates are available.
	 */
	public function requestUpdate(){
		//For the sake of simplicity, this function just calls requestInfo() 
		//and transforms the result accordingly.
		$pluginInfo = $this->requestInfo(array('checking_for_updates' => '1'));
		if ( $pluginInfo == null ){
			return null;
		}
		$update = PluginUpdate_3_1::fromPluginInfo($pluginInfo);

		//Keep only those translation updates that apply to this site.
		$update->translations = $this->filterApplicableTranslations($update->translations);

		return $update;
	}

	/**
	 * Filter a list of translation updates and return a new list that contains only updates
	 * that apply to the current site.
	 *
	 * @param array $translations
	 * @return array
	 */
	private function filterApplicableTranslations($translations) {
		$languages = array_flip(array_values(get_available_languages()));
		$installedTranslations = wp_get_installed_translations('plugins');
		if ( isset($installedTranslations[$this->slug]) ) {
			$installedTranslations = $installedTranslations[$this->slug];
		} else {
			$installedTranslations = array();
		}

		$applicableTranslations = array();
		foreach($translations as $translation) {
			//Does it match one of the available core languages?
			$isApplicable = array_key_exists($translation->language, $languages);
			//Is it more recent than an already-installed translation?
			if ( isset($installedTranslations[$translation->language]) ) {
				$updateTimestamp = strtotime($translation->updated);
				$installedTimestamp = strtotime($installedTranslations[$translation->language]['PO-Revision-Date']);
				$isApplicable = $updateTimestamp > $installedTimestamp;
			}

			if ( $isApplicable ) {
				$applicableTranslations[] = $translation;
			}
		}

		return $applicableTranslations;
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
	 * Check for plugin updates.
	 * The results are stored in the DB option specified in $optionName.
	 *
	 * @return PluginUpdate_3_1|null
	 */
	public function checkForUpdates(){
		$installedVersion = $this->getInstalledVersion();
		//Fail silently if we can't find the plugin or read its header.
		if ( $installedVersion === null ) {
			$this->triggerError(
				sprintf('Skipping update check for %s - installed version unknown.', $this->pluginFile),
				E_USER_WARNING
			);
			return null;
		}

		$state = $this->getUpdateState();
		if ( empty($state) ){
			$state = new stdClass;
			$state->lastCheck = 0;
			$state->checkedVersion = '';
			$state->update = null;
		}
		
		$state->lastCheck = time();
		$state->checkedVersion = $installedVersion;
		$this->setUpdateState($state); //Save before checking in case something goes wrong 
		
		$state->update = $this->requestUpdate();
		$this->setUpdateState($state);

		return $this->getUpdate();
	}
	
	/**
	 * Load the update checker state from the DB.
	 *  
	 * @return stdClass|null
	 */
	public function getUpdateState() {
		$state = get_site_option($this->optionName, null);
		if ( empty($state) || !is_object($state)) {
			$state = null;
		}

		if ( isset($state, $state->update) && is_object($state->update) ) {
			$state->update = PluginUpdate_3_1::fromObject($state->update);
		}
		return $state;
	}
	
	
	/**
	 * Persist the update checker state to the DB.
	 * 
	 * @param StdClass $state
	 * @return void
	 */
	private function setUpdateState($state) {
		if ( isset($state->update) && is_object($state->update) && method_exists($state->update, 'toStdClass') ) {
			$update = $state->update; /** @var PluginUpdate_3_1 $update */
			$state->update = $update->toStdClass();
		}
		update_site_option($this->optionName, $state);
	}

	/**
	 * Reset update checker state - i.e. last check time, cached update data and so on.
	 *
	 * Call this when your plugin is being uninstalled, or if you want to
	 * clear the update cache.
	 */
	public function resetUpdateState() {
		delete_site_option($this->optionName);
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
		//Is there an update to insert?
		$update = $this->getUpdate();

		//No update notifications for mu-plugins unless explicitly enabled. The MU plugin file
		//is usually different from the main plugin file so the update wouldn't show up properly anyway.
		if ( $this->isUnknownMuPlugin() ) {
			$update = null;
		}

		if ( !empty($update) ) {
			//Let plugins filter the update info before it's passed on to WordPress.
			$update = apply_filters('puc_pre_inject_update-' . $this->slug, $update);
			$updates = $this->addUpdateToList($updates, $update);
		} else {
			//Clean up any stale update info.
			$updates = $this->removeUpdateFromList($updates);
		}

		return $updates;
	}

	/**
	 * @param StdClass|null $updates
	 * @param PluginUpdate_3_1 $updateToAdd
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
	 * Insert translation updates into the list maintained by WordPress.
	 *
	 * @param stdClass $updates
	 * @return stdClass
	 */
	public function injectTranslationUpdates($updates) {
		$translationUpdates = $this->getTranslationUpdates();
		if ( empty($translationUpdates) ) {
			return $updates;
		}

		//Being defensive.
		if ( !is_object($updates) ) {
			$updates = new stdClass();
		}
		if ( !isset($updates->translations) ) {
			$updates->translations = array();
		}

		//In case there's a name collision with a plugin hosted on wordpress.org,
		//remove any preexisting updates that match our plugin.
		$translationType = 'plugin';
		$filteredTranslations = array();
		foreach($updates->translations as $translation) {
			if ( ($translation['type'] === $translationType) && ($translation['slug'] === $this->slug) ) {
				continue;
			}
			$filteredTranslations[] = $translation;
		}
		$updates->translations = $filteredTranslations;

		//Add our updates to the list.
		foreach($translationUpdates as $update) {
			$convertedUpdate = array_merge(
				array(
					'type' => $translationType,
					'slug' => $this->slug,
					'autoupdate' => 0,
					//AFAICT, WordPress doesn't actually use the "version" field for anything.
					//But lets make sure it's there, just in case.
					'version' => isset($update->version) ? $update->version : ('1.' . strtotime($update->updated)),
				),
				(array)$update
			);

			$updates->translations[] = $convertedUpdate;
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
	 * @return PluginUpdate_3_1|null
	 */
	public function getUpdate() {
		$state = $this->getUpdateState(); /** @var StdClass $state */

		//Is there an update available?
		if ( isset($state, $state->update) ) {
			$update = $state->update;
			//Check if the update is actually newer than the currently installed version.
			$installedVersion = $this->getInstalledVersion();
			if ( ($installedVersion !== null) && version_compare($update->version, $installedVersion, '>') ){
				$update->filename = $this->pluginFile;
				return $update;
			}
		}
		return null;
	}

	/**
	 * Get a list of available translation updates.
	 *
	 * This method will return an empty array if there are no updates.
	 * Uses cached update data.
	 *
	 * @return array
	 */
	public function getTranslationUpdates() {
		$state = $this->getUpdateState();
		if ( isset($state, $state->update, $state->update->translations) ) {
			return $state->update->translations;
		}
		return array();
	}

	/**
	 * Remove all cached translation updates.
	 *
	 * @see wp_clean_update_cache
	 */
	public function clearCachedTranslationUpdates() {
		$state = $this->getUpdateState();
		if ( isset($state, $state->update, $state->update->translations) ) {
			$state->update->translations = array();
			$this->setUpdateState($state);
		}
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

		if ( $isRelevant && current_user_can('update_plugins') ) {
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
			&& current_user_can('update_plugins')
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
	 * Register a callback for one of the update checker filters.
	 *
	 * Identical to add_filter(), except it automatically adds the "puc_" prefix
	 * and the "-$plugin_slug" suffix to the filter name. For example, "request_info_result"
	 * becomes "puc_request_info_result-your_plugin_slug".
	 *
	 * @param string $tag
	 * @param callable $callback
	 * @param int $priority
	 * @param int $acceptedArgs
	 */
	public function addFilter($tag, $callback, $priority = 10, $acceptedArgs = 1) {
		add_filter('puc_' . $tag . '-' . $this->slug, $callback, $priority, $acceptedArgs);
	}

	/**
	 * Initialize the update checker Debug Bar plugin/add-on thingy.
	 */
	public function initDebugBarPanel() {
		$debugBarPlugin = dirname(__FILE__) . '/debug-bar-plugin.php';
		if ( class_exists('Debug_Bar', false) && file_exists($debugBarPlugin) ) {
			/** @noinspection PhpIncludeInspection */
			require_once $debugBarPlugin;
			$this->debugBarPlugin = new PucDebugBarPlugin_3_1($this);
		}
	}

	/**
	 * Trigger a PHP error, but only when $debugMode is enabled.
	 *
	 * @param string $message
	 * @param int $errorType
	 */
	protected function triggerError($message, $errorType) {
		if ( $this->debugMode ) {
			trigger_error($message, $errorType);
		}
	}
}

endif;

if ( !class_exists('PluginInfo_3_1', false) ):

/**
 * A container class for holding and transforming various plugin metadata.
 * 
 * @author Janis Elsts
 * @copyright 2016
 * @version 3.0
 * @access public
 */
class PluginInfo_3_1 {
	//Most fields map directly to the contents of the plugin's info.json file.
	//See the relevant docs for a description of their meaning.  
	public $name;
	public $slug;
	public $version;
	public $homepage;
	public $sections = array();
	public $banners;
	public $translations = array();
	public $download_url;

	public $author;
	public $author_homepage;
	
	public $requires;
	public $tested;
	public $upgrade_notice;
	
	public $rating;
	public $num_ratings;
	public $downloaded;
	public $active_installs;
	public $last_updated;
	
	public $id = 0; //The native WP.org API returns numeric plugin IDs, but they're not used for anything.

	public $filename; //Plugin filename relative to the plugins directory.
		
	/**
	 * Create a new instance of PluginInfo from JSON-encoded plugin info 
	 * returned by an external update API.
	 * 
	 * @param string $json Valid JSON string representing plugin info.
	 * @return PluginInfo_3_1|null New instance of PluginInfo, or NULL on error.
	 */
	public static function fromJson($json){
		/** @var StdClass $apiResponse */
		$apiResponse = json_decode($json);
		if ( empty($apiResponse) || !is_object($apiResponse) ){
			trigger_error(
				"Failed to parse plugin metadata. Try validating your .json file with http://jsonlint.com/",
				E_USER_NOTICE
			);
			return null;
		}
		
		$valid = self::validateMetadata($apiResponse);
		if ( is_wp_error($valid) ){
			trigger_error($valid->get_error_message(), E_USER_NOTICE);
			return null;
		}
		
		$info = new self();
		foreach(get_object_vars($apiResponse) as $key => $value){
			$info->$key = $value;
		}

		//json_decode decodes assoc. arrays as objects. We want it as an array.
		$info->sections = (array)$info->sections;
		
		return $info;		
	}

	/**
	 * Very, very basic validation.
	 *
	 * @param StdClass $apiResponse
	 * @return bool|WP_Error
	 */
	protected static function validateMetadata($apiResponse) {
		if (
			!isset($apiResponse->name, $apiResponse->version)
			|| empty($apiResponse->name)
			|| empty($apiResponse->version)
		) {
			return new WP_Error(
				'puc-invalid-metadata',
				"The plugin metadata file does not contain the required 'name' and/or 'version' keys."
			);
		}
		return true;
	}

	
	/**
	 * Transform plugin info into the format used by the native WordPress.org API
	 * 
	 * @return object
	 */
	public function toWpFormat(){
		$info = new stdClass;
		
		//The custom update API is built so that many fields have the same name and format
		//as those returned by the native WordPress.org API. These can be assigned directly. 
		$sameFormat = array(
			'name', 'slug', 'version', 'requires', 'tested', 'rating', 'upgrade_notice',
			'num_ratings', 'downloaded', 'active_installs', 'homepage', 'last_updated',
		);
		foreach($sameFormat as $field){
			if ( isset($this->$field) ) {
				$info->$field = $this->$field;
			} else {
				$info->$field = null;
			}
		}

		//Other fields need to be renamed and/or transformed.
		$info->download_link = $this->download_url;
		$info->author = $this->getFormattedAuthor();
		$info->sections = array_merge(array('description' => ''), $this->sections);

		if ( !empty($this->banners) ) {
			//WP expects an array with two keys: "high" and "low". Both are optional.
			//Docs: https://wordpress.org/plugins/about/faq/#banners
			$info->banners = is_object($this->banners) ? get_object_vars($this->banners) : $this->banners;
			$info->banners = array_intersect_key($info->banners, array('high' => true, 'low' => true));
		}

		return $info;
	}

	protected function getFormattedAuthor() {
		if ( !empty($this->author_homepage) ){
			return sprintf('<a href="%s">%s</a>', $this->author_homepage, $this->author);
		}
		return $this->author;
	}
}
	
endif;

if ( !class_exists('PluginUpdate_3_1', false) ):

/**
 * A simple container class for holding information about an available update.
 * 
 * @author Janis Elsts
 * @copyright 2016
 * @version 3.0
 * @access public
 */
class PluginUpdate_3_1 {
	public $id = 0;
	public $slug;
	public $version;
	public $homepage;
	public $download_url;
	public $upgrade_notice;
	public $tested;
	public $translations = array();
	public $filename; //Plugin filename relative to the plugins directory.

	private static $fields = array(
		'id', 'slug', 'version', 'homepage', 'tested',
		'download_url', 'upgrade_notice', 'filename',
		'translations'
	);
	
	/**
	 * Create a new instance of PluginUpdate from its JSON-encoded representation.
	 * 
	 * @param string $json
	 * @return PluginUpdate_3_1|null
	 */
	public static function fromJson($json){
		//Since update-related information is simply a subset of the full plugin info,
		//we can parse the update JSON as if it was a plugin info string, then copy over
		//the parts that we care about.
		$pluginInfo = PluginInfo_3_1::fromJson($json);
		if ( $pluginInfo != null ) {
			return self::fromPluginInfo($pluginInfo);
		} else {
			return null;
		}
	}

	/**
	 * Create a new instance of PluginUpdate based on an instance of PluginInfo.
	 * Basically, this just copies a subset of fields from one object to another.
	 * 
	 * @param PluginInfo_3_1 $info
	 * @return PluginUpdate_3_1
	 */
	public static function fromPluginInfo($info){
		return self::fromObject($info);
	}
	
	/**
	 * Create a new instance of PluginUpdate by copying the necessary fields from 
	 * another object.
	 *  
	 * @param StdClass|PluginInfo_3_1|PluginUpdate_3_1 $object The source object.
	 * @return PluginUpdate_3_1 The new copy.
	 */
	public static function fromObject($object) {
		$update = new self();
		$fields = self::$fields;
		if ( !empty($object->slug) ) {
			$fields = apply_filters('puc_retain_fields-' . $object->slug, $fields);
		}
		foreach($fields as $field){
			if (property_exists($object, $field)) {
				$update->$field = $object->$field;
			}
		}
		return $update;
	}
	
	/**
	 * Create an instance of StdClass that can later be converted back to 
	 * a PluginUpdate. Useful for serialization and caching, as it avoids
	 * the "incomplete object" problem if the cached value is loaded before
	 * this class.
	 * 
	 * @return StdClass
	 */
	public function toStdClass() {
		$object = new stdClass();
		$fields = self::$fields;
		if ( !empty($this->slug) ) {
			$fields = apply_filters('puc_retain_fields-' . $this->slug, $fields);
		}
		foreach($fields as $field){
			if (property_exists($this, $field)) {
				$object->$field = $this->$field;
			}
		}
		return $object;
	}
	
	
	/**
	 * Transform the update into the format used by WordPress native plugin API.
	 * 
	 * @return object
	 */
	public function toWpFormat(){
		$update = new stdClass;

		$update->id = $this->id;
		$update->slug = $this->slug;
		$update->new_version = $this->version;
		$update->url = $this->homepage;
		$update->package = $this->download_url;
		$update->tested = $this->tested;
		$update->plugin = $this->filename;

		if ( !empty($this->upgrade_notice) ){
			$update->upgrade_notice = $this->upgrade_notice;
		}
		
		return $update;
	}
}
	
endif;

if ( !class_exists('PucScheduler_3_1', false) ):

/**
 * The scheduler decides when and how often to check for updates.
 * It calls @see PluginUpdateChecker::checkForUpdates() to perform the actual checks.
 *
 * @version 3.0
 */
class PucScheduler_3_1 {
	public $checkPeriod = 12; //How often to check for updates (in hours).
	public $throttleRedundantChecks = false; //Check less often if we already know that an update is available.
	public $throttledCheckPeriod = 72;

	/**
	 * @var PluginUpdateChecker_3_1
	 */
	protected $updateChecker;

	private $cronHook = null;

	/**
	 * Scheduler constructor.
	 *
	 * @param PluginUpdateChecker_3_1 $updateChecker
	 * @param int $checkPeriod How often to check for updates (in hours).
	 */
	public function __construct($updateChecker, $checkPeriod) {
		$this->updateChecker = $updateChecker;
		$this->checkPeriod = $checkPeriod;

		//Set up the periodic update checks
		$this->cronHook = 'check_plugin_updates-' . $this->updateChecker->slug;
		if ( $this->checkPeriod > 0 ){

			//Trigger the check via Cron.
			//Try to use one of the default schedules if possible as it's less likely to conflict
			//with other plugins and their custom schedules.
			$defaultSchedules = array(
				1  => 'hourly',
				12 => 'twicedaily',
				24 => 'daily',
			);
			if ( array_key_exists($this->checkPeriod, $defaultSchedules) ) {
				$scheduleName = $defaultSchedules[$this->checkPeriod];
			} else {
				//Use a custom cron schedule.
				$scheduleName = 'every' . $this->checkPeriod . 'hours';
				add_filter('cron_schedules', array($this, '_addCustomSchedule'));
			}

			if ( !wp_next_scheduled($this->cronHook) && !defined('WP_INSTALLING') ) {
				wp_schedule_event(time(), $scheduleName, $this->cronHook);
			}
			add_action($this->cronHook, array($this, 'maybeCheckForUpdates'));

			register_deactivation_hook($this->updateChecker->pluginFile, array($this, '_removeUpdaterCron'));

			//In case Cron is disabled or unreliable, we also manually trigger
			//the periodic checks while the user is browsing the Dashboard.
			add_action( 'admin_init', array($this, 'maybeCheckForUpdates') );

			//Like WordPress itself, we check more often on certain pages.
			/** @see wp_update_plugins */
			add_action('load-update-core.php', array($this, 'maybeCheckForUpdates'));
			add_action('load-plugins.php', array($this, 'maybeCheckForUpdates'));
			add_action('load-update.php', array($this, 'maybeCheckForUpdates'));
			//This hook fires after a bulk update is complete.
			add_action('upgrader_process_complete', array($this, 'maybeCheckForUpdates'), 11, 0);

		} else {
			//Periodic checks are disabled.
			wp_clear_scheduled_hook($this->cronHook);
		}
	}

	/**
	 * Check for updates if the configured check interval has already elapsed.
	 * Will use a shorter check interval on certain admin pages like "Dashboard -> Updates" or when doing cron.
	 *
	 * You can override the default behaviour by using the "puc_check_now-$slug" filter.
	 * The filter callback will be passed three parameters:
	 *     - Current decision. TRUE = check updates now, FALSE = don't check now.
	 *     - Last check time as a Unix timestamp.
	 *     - Configured check period in hours.
	 * Return TRUE to check for updates immediately, or FALSE to cancel.
	 *
	 * This method is declared public because it's a hook callback. Calling it directly is not recommended.
	 */
	public function maybeCheckForUpdates(){
		if ( empty($this->checkPeriod) ){
			return;
		}

		$state = $this->updateChecker->getUpdateState();
		$shouldCheck =
			empty($state) ||
			!isset($state->lastCheck) ||
			( (time() - $state->lastCheck) >= $this->getEffectiveCheckPeriod() );

		//Let plugin authors substitute their own algorithm.
		$shouldCheck = apply_filters(
			'puc_check_now-' . $this->updateChecker->slug,
			$shouldCheck,
			(!empty($state) && isset($state->lastCheck)) ? $state->lastCheck : 0,
			$this->checkPeriod
		);

		if ( $shouldCheck ) {
			$this->updateChecker->checkForUpdates();
		}
	}

	/**
	 * Calculate the actual check period based on the current status and environment.
	 *
	 * @return int Check period in seconds.
	 */
	protected function getEffectiveCheckPeriod() {
		$currentFilter = current_filter();
		if ( in_array($currentFilter, array('load-update-core.php', 'upgrader_process_complete')) ) {
			//Check more often when the user visits "Dashboard -> Updates" or does a bulk update.
			$period = 60;
		} else if ( in_array($currentFilter, array('load-plugins.php', 'load-update.php')) ) {
			//Also check more often on the "Plugins" page and /wp-admin/update.php.
			$period = 3600;
		} else if ( $this->throttleRedundantChecks && ($this->updateChecker->getUpdate() !== null) ) {
			//Check less frequently if it's already known that an update is available.
			$period = $this->throttledCheckPeriod * 3600;
		} else if ( defined('DOING_CRON') && constant('DOING_CRON') ) {
			//WordPress cron schedules are not exact, so lets do an update check even
			//if slightly less than $checkPeriod hours have elapsed since the last check.
			$cronFuzziness = 20 * 60;
			$period = $this->checkPeriod * 3600 - $cronFuzziness;
		} else {
			$period = $this->checkPeriod * 3600;
		}

		return $period;
	}

	/**
	 * Add our custom schedule to the array of Cron schedules used by WP.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function _addCustomSchedule($schedules){
		if ( $this->checkPeriod && ($this->checkPeriod > 0) ){
			$scheduleName = 'every' . $this->checkPeriod . 'hours';
			$schedules[$scheduleName] = array(
				'interval' => $this->checkPeriod * 3600,
				'display' => sprintf('Every %d hours', $this->checkPeriod),
			);
		}
		return $schedules;
	}

	/**
	 * Remove the scheduled cron event that the library uses to check for updates.
	 *
	 * @return void
	 */
	public function _removeUpdaterCron(){
		wp_clear_scheduled_hook($this->cronHook);
	}

	/**
	 * Get the name of the update checker's WP-cron hook. Mostly useful for debugging.
	 *
	 * @return string
	 */
	public function getCronHookName() {
		return $this->cronHook;
	}
}

endif;


if ( !class_exists('PucUpgraderStatus_3_1', false) ):

/**
 * A utility class that helps figure out which plugin WordPress is upgrading.
 *
 * It may seem strange to have an separate class just for that, but the task is surprisingly complicated.
 * Core classes like Plugin_Upgrader don't expose the plugin file name during an in-progress update (AFAICT).
 * This class uses a few workarounds and heuristics to get the file name.
 */
class PucUpgraderStatus_3_1 {
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
	 * @return PluginUpdateChecker_3_1
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

require_once(dirname(__FILE__) . '/github-checker.php');

//Register classes defined in this file with the factory.
PucFactory::addVersion('PluginUpdateChecker', 'PluginUpdateChecker_3_1', '3.1');
PucFactory::addVersion('PluginUpdate', 'PluginUpdate_3_1', '3.1');
PucFactory::addVersion('PluginInfo', 'PluginInfo_3_1', '3.1');
PucFactory::addVersion('PucGitHubChecker', 'PucGitHubChecker_3_1', '3.1');
