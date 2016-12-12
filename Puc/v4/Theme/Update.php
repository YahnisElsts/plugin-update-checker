<?php

if ( class_exists('Puc_v4_Theme_Update', false) ):

	class Puc_v4_Theme_Update extends Puc_v4_Update {
		public $details_url = '';

		protected static $extraFields = array('details_url');

		/**
		 * Transform the metadata into the format used by WordPress core.
		 *
		 * @return object
		 */
		public function toWpFormat() {
			$update = parent::toWpFormat();

			$update->theme = $this->slug;
			$update->new_version = $this->version;
			$update->package = $this->download_url;
			$update->details_url = $this->details_url;

			return $update;
		}

		/**
		 * Create a new instance of Theme_Update from its JSON-encoded representation.
		 *
		 * @param string $json Valid JSON string representing a theme information object.
		 * @return self New instance of ThemeUpdate, or NULL on error.
		 */
		public static function fromJson($json) {
			$instance = new self();
			if ( !parent::createFromJson($json, $instance) ) {
				return null;
			}
			return $instance;
		}

		/**
		 * Basic validation.
		 *
		 * @param StdClass $apiResponse
		 * @return bool|WP_Error
		 */
		protected function validateMetadata($apiResponse) {
			$required = array('version', 'details_url');
			foreach($required as $key) {
				if ( !isset($apiResponse->$key) || empty($apiResponse->$key) ) {
					return new WP_Error(
						'tuc-invalid-metadata',
						sprintf('The theme metadata is missing the required "%s" key.', $key)
					);
				}
			}
			return true;
		}

		protected function getFieldNames() {
			return array_merge(parent::getFieldNames(), self::$extraFields);
		}

		protected function getFilterPrefix() {
			return 'tuc_';
		}
	}

endif;