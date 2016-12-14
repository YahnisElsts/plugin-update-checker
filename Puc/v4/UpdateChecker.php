<?php

if ( !class_exists('Puc_v4_UpdateChecker', false) ):

	abstract class Puc_v4_UpdateChecker {
		protected $filterPrefix = 'puc_';
		protected $updateClass = '';

		/**
		 * Set to TRUE to enable error reporting. Errors are raised using trigger_error()
		 * and should be logged to the standard PHP error log.
		 * @var bool
		 */
		public $debugMode = false;

		/**
		 * @var string Where to store the update info.
		 */
		public $optionName = '';

		/**
		 * @var string The URL of the metadata file.
		 */
		public $metadataUrl = '';

		/**
		 * @var string Plugin slug or theme directory name.
		 */
		public $slug = '';

		/**
		 * @var Puc_v4_Scheduler
		 */
		public $scheduler;

		/**
		 * @var string The host component of $metadataUrl.
		 */
		protected $metadataHost = '';

		public function __construct($metadataUrl, $slug, $checkPeriod = 12, $optionName = '') {
			$this->debugMode = (bool)(constant('WP_DEBUG'));
			$this->metadataUrl = $metadataUrl;
			$this->slug = $slug;

			$this->optionName = $optionName;
			if ( empty($this->optionName) ) {
				//BC: Initially the library only supported plugin updates and didn't use type prefixes
				//in the option name. Lets use the same prefix-less name when possible.
				if ( $this->filterPrefix === 'puc_' ) {
					$this->optionName = 'external_updates-' . $this->slug;
				} else {
					$this->optionName = $this->filterPrefix . 'external_updates-' . $this->slug;
				}
			}

			$this->scheduler = $this->createScheduler($checkPeriod);

			$this->loadTextDomain();
			$this->installHooks();
		}

		protected function loadTextDomain() {
			//We're not using load_plugin_textdomain() or its siblings because figuring out where
			//the library is located (plugin, mu-plugin, theme, custom wp-content paths) is messy.
			$domain = 'plugin-update-checker';
			$locale = apply_filters('plugin_locale', is_admin() ? get_user_locale() : get_locale(), $domain);

			$moFile = $domain . '-' . $locale . '.mo';
			$path = realpath(dirname(__FILE__) . '/../../languages');

			if ($path && file_exists($path)) {
				load_textdomain($domain, $path . '/ ' . $moFile);
			}
		}

		protected function installHooks() {
			//TODO: Translation updates
			//TODO: Fix directory name

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
		 * Create an instance of the scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4_Scheduler
		 */
		abstract protected function createScheduler($checkPeriod);

		/**
		 * Check for updates. The results are stored in the DB option specified in $optionName.
		 *
		 * @return Puc_v4_Update|null
		 */
		public function checkForUpdates() {
			$installedVersion = $this->getInstalledVersion();
			//Fail silently if we can't find the plugin/theme or read its header.
			if ( $installedVersion === null ) {
				$this->triggerError(
					sprintf('Skipping update check for %s - installed version unknown.', $this->slug),
					E_USER_WARNING
				);
				return null;
			}

			$state = $this->getUpdateState();
			if ( empty($state) ) {
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
			if ( empty($state) || !is_object($state) ) {
				$state = null;
			}

			if ( isset($state, $state->update) && is_object($state->update) ) {
				$state->update = call_user_func(array($this->updateClass, 'fromObject'), $state->update);
			}
			return $state;
		}

		/**
		 * Persist the update checker state to the DB.
		 *
		 * @param StdClass $state
		 * @return void
		 */
		protected function setUpdateState($state) {
			if (isset($state->update) && is_object($state->update) && method_exists($state->update, 'toStdClass')) {
				$update = $state->update;
				/** @var Puc_v4_Update $update */
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
		 * Get the details of the currently available update, if any.
		 *
		 * If no updates are available, or if the last known update version is below or equal
		 * to the currently installed version, this method will return NULL.
		 *
		 * Uses cached update data. To retrieve update information straight from
		 * the metadata URL, call requestUpdate() instead.
		 *
		 * @return Puc_v4_Update|Puc_v4_Plugin_Update|Puc_v4_Theme_Update|null
		 */
		public function getUpdate() {
			$state = $this->getUpdateState(); /** @var StdClass $state */

			//Is there an update available?
			if ( isset($state, $state->update) ) {
				$update = $state->update;
				//Check if the update is actually newer than the currently installed version.
				$installedVersion = $this->getInstalledVersion();
				if ( ($installedVersion !== null) && version_compare($update->version, $installedVersion, '>') ){
					return $update;
				}
			}
			return null;
		}

		/**
		 * Retrieve the latest update (if any) from the configured API endpoint.
		 *
		 * @return Puc_v4_Update An instance of Update, or NULL when no updates are available.
		 */
		abstract public function requestUpdate();

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
				return new WP_Error(
					$this->filterPrefix . 'no_response_code',
					'wp_remote_get() returned an unexpected result.'
				);
			}

			if ( $result['response']['code'] !== 200 ) {
				return new WP_Error(
					$this->filterPrefix . 'unexpected_response_code',
					'HTTP response code is ' . $result['response']['code'] . ' (expected: 200)'
				);
			}

			if ( empty($result['body']) ) {
				return new WP_Error($this->filterPrefix . 'empty_response', 'The metadata file appears to be empty.');
			}

			return true;
		}

		/**
		 * Get the currently installed version of the plugin or theme.
		 *
		 * @return string Version number.
		 */
		abstract public function getInstalledVersion();

		/**
		 * Register a callback for one of the update checker filters.
		 *
		 * Identical to add_filter(), except it automatically adds the "puc_"/"tuc_" prefix
		 * and the "-$slug" suffix to the filter name. For example, "request_info_result"
		 * becomes "puc_request_info_result-your_plugin_slug".
		 *
		 * @param string $tag
		 * @param callable $callback
		 * @param int $priority
		 * @param int $acceptedArgs
		 */
		public function addFilter($tag, $callback, $priority = 10, $acceptedArgs = 1) {
			add_filter($this->getFilterName($tag), $callback, $priority, $acceptedArgs);
		}

		/**
		 * Get the full name of an update checker filter or action.
		 *
		 * This method adds the "puc_"/"tuc_" prefix and the "-$slug" suffix to the filter name.
		 * For example, "pre_inject_update" becomes "puc_pre_inject_update-plugin-slug".
		 *
		 * @param string $baseTag
		 * @return string
		 */
		public function getFilterName($baseTag) {
			return $this->filterPrefix . $baseTag . '-' . $this->slug;
		}

		/**
		 * Trigger a PHP error, but only when $debugMode is enabled.
		 *
		 * @param string $message
		 * @param int $errorType
		 */
		protected function triggerError($message, $errorType) {
			if ($this->debugMode) {
				trigger_error($message, $errorType);
			}
		}
	}

endif;