<?php
namespace YahnisElsts\PluginUpdateChecker\v5p6\DebugBar;

use YahnisElsts\PluginUpdateChecker\v5p6\UpdateChecker;

if ( !class_exists(Panel::class, false) && class_exists('Debug_Bar_Panel', false) ):

	class Panel extends \Debug_Bar_Panel {
		/** @var UpdateChecker */
		protected $updateChecker;

		private $responseBox = '<div class="puc-ajax-response" style="display: none;"></div>';

		public function __construct($updateChecker) {
			$this->updateChecker = $updateChecker;
			$title = sprintf(
				'<span class="puc-debug-menu-link-%s">PUC (%s)</span>',
				esc_attr($this->updateChecker->getUniqueName('uid')),
				$this->updateChecker->slug
			);
			parent::__construct($title);
		}

		public function render() {
			printf(
				'<div class="puc-debug-bar-panel-v5" id="%1$s" data-slug="%2$s" data-uid="%3$s" data-nonce="%4$s">',
				esc_attr($this->updateChecker->getUniqueName('debug-bar-panel')),
				esc_attr($this->updateChecker->slug),
				esc_attr($this->updateChecker->getUniqueName('uid')),
				esc_attr(wp_create_nonce('puc-ajax'))
			);

			$this->displayConfiguration();
			$this->displayStatus();
			$this->displayCurrentUpdate();

			echo '</div>';
		}

		private function displayConfiguration() {
			echo '<h3>Configuration</h3>';
			echo '<table class="puc-debug-data">';
			$this->displayConfigHeader();
			$this->row('Slug', htmlentities($this->updateChecker->slug, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
			$this->row('DB option', htmlentities($this->updateChecker->optionName, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));

			$requestInfoButton = $this->getMetadataButton();
			$this->row('Metadata URL', htmlentities($this->updateChecker->metadataUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') . ' ' . $requestInfoButton . $this->responseBox);

			$scheduler = $this->updateChecker->scheduler;
			if ( $scheduler->checkPeriod > 0 ) {
				$this->row('Automatic checks', 'Every ' . $scheduler->checkPeriod . ' hours');
			} else {
				$this->row('Automatic checks', 'Disabled');
			}

			if ( isset($scheduler->throttleRedundantChecks) ) {
				if ( $scheduler->throttleRedundantChecks && ($scheduler->checkPeriod > 0) ) {
					$this->row(
						'Throttling',
						sprintf(
							'Enabled. If an update is already available, check for updates every %1$d hours instead of every %2$d hours.',
							$scheduler->throttledCheckPeriod,
							$scheduler->checkPeriod
						)
					);
				} else {
					$this->row('Throttling', 'Disabled');
				}
			}

			$this->updateChecker->onDisplayConfiguration($this);

			echo '</table>';
		}

		protected function displayConfigHeader() {
			//Do nothing. This should be implemented in subclasses.
		}

		protected function getMetadataButton() {
			return '';
		}

		private function displayStatus() {
			echo '<h3>Status</h3>';
			echo '<table class="puc-debug-data">';
			$state = $this->updateChecker->getUpdateState();
			$checkButtonId = $this->updateChecker->getUniqueName('check-now-button');
			if ( function_exists('get_submit_button')  ) {
				$checkNowButton = get_submit_button(
					'Check Now',
					'secondary',
					'puc-check-now-button',
					false,
					array('id' => $checkButtonId)
				);
			} else {
				//get_submit_button() is not available in the frontend. Make a button directly.
				//It won't look the same without admin styles, but it should still work.
				$checkNowButton = sprintf(
					'<input type="button" id="%1$s" name="puc-check-now-button" value="%2$s" class="button button-secondary" />',
					esc_attr($checkButtonId),
					esc_attr('Check Now')
				);
			}

			if ( $state->getLastCheck() > 0 ) {
				$this->row('Last check', $this->formatTimeWithDelta($state->getLastCheck()) . ' ' . $checkNowButton . $this->responseBox);
			} else {
				$this->row('Last check', 'Never');
			}

			$nextCheck = wp_next_scheduled($this->updateChecker->scheduler->getCronHookName());
			$this->row('Next automatic check', $this->formatTimeWithDelta($nextCheck));

			if ( $state->getCheckedVersion() !== '' ) {
				$this->row('Checked version', htmlentities($state->getCheckedVersion(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
				$this->row('Cached update', $state->getUpdate());
			}
			$this->row('Update checker class', htmlentities(get_class($this->updateChecker), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
			echo '</table>';
		}

		private function displayCurrentUpdate() {
			$update = $this->updateChecker->getUpdate();
			if ( $update !== null ) {
				echo '<h3>An Update Is Available</h3>';
				echo '<table class="puc-debug-data">';
				$fields = $this->getUpdateFields();
				foreach($fields as $field) {
					if ( property_exists($update, $field) ) {
						$this->row(
							ucwords(str_replace('_', ' ', $field)),
							isset($update->$field) ? htmlentities($update->$field, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : null
						);
					}
				}
				echo '</table>';
			} else {
				echo '<h3>No updates currently available</h3>';
			}
		}

		protected function getUpdateFields() {
			return array('version', 'download_url', 'slug',);
		}

		private function formatTimeWithDelta($unixTime) {
			if ( empty($unixTime) ) {
				return 'Never';
			}

			$delta = time() - $unixTime;
			$result = human_time_diff(time(), $unixTime);
			if ( $delta < 0 ) {
				$result = 'after ' . $result;
			} else {
				$result = $result . ' ago';
			}
			$result .= ' (' . $this->formatTimestamp($unixTime) . ')';
			return $result;
		}

		private function formatTimestamp($unixTime) {
			return gmdate('Y-m-d H:i:s', $unixTime + (get_option('gmt_offset') * 3600));
		}

		public function row($name, $value) {
			if ( is_object($value) || is_array($value) ) {
				//This is specifically for debugging, so print_r() is fine.
				//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				$value = '<pre>' . htmlentities(print_r($value, true), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') . '</pre>';
			} else if ($value === null) {
				$value = '<code>null</code>';
			}
			printf(
				'<tr><th scope="row">%1$s</th> <td>%2$s</td></tr>',
				esc_html($name),
				//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				$value
			);
		}
	}

endif;
