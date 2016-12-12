<?php

if ( !class_exists('Puc_v4_UpdateChecker', false) ):

	abstract class Puc_v4_UpdateChecker {
		protected $filterPrefix = 'puc_';

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