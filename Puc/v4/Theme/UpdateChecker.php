<?php

if ( class_exists('Puc_v4_Theme_UpdateChecker', false) ):

	class Puc_v4_Theme_UpdateChecker extends Puc_v4_UpdateChecker {
		protected $filterPrefix = 'tuc_';
		protected $updateClass = 'Puc_v4_Theme_Update';

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

			parent::__construct($metadataUrl, $customSlug ? $customSlug : $stylesheet, $checkPeriod, $optionName);
		}

		protected function installHooks() {
			parent::installHooks();

			//Insert our update info into the update list maintained by WP.
			add_filter('site_transient_update_themes', array($this,'injectUpdate'));

			//TODO: Rename the update directory to be the same as the existing directory.
			//add_filter('upgrader_source_selection', array($this, 'fixDirectoryName'), 10, 3);
		}

		/**
		 * Insert the latest update (if any) into the update list maintained by WP.
		 *
		 * @param StdClass $updates Update list.
		 * @return StdClass Modified update list.
		 */
		public function injectUpdate($updates) {
			//Is there an update to insert?
			$update = $this->getUpdate();

			if ( !empty($update) ) {
				//Let themes filter the update info before it's passed on to WordPress.
				$update = apply_filters($this->getFilterName('pre_inject_update'), $update);
				$updates->response[$this->stylesheet] = $update->toWpFormat();
			} else {
				//Clean up any stale update info.
				unset($updates->response[$this->stylesheet]);
			}

			return $updates;
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

			$queryArgs = apply_filters($this->filterPrefix . 'request_update_query_args-' . $this->slug, $queryArgs);

			//Various options for the wp_remote_get() call. Plugins can filter these, too.
			$options = array(
				'timeout' => 10, //seconds
				'headers' => array(
					'Accept' => 'application/json'
				),
			);
			$options = apply_filters($this->filterPrefix . 'request_update_options-' . $this->slug, $options);

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
				$this->filterPrefix . 'request_update_result-' . $this->slug,
				$themeUpdate,
				$result
			);
			return $themeUpdate;
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
	}

endif;