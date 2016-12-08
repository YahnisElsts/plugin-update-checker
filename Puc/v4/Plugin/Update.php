<?php
if ( !class_exists('Puc_v4_Plugin_Update', false) ):

	/**
	 * A simple container class for holding information about an available update.
	 *
	 * @author Janis Elsts
	 * @copyright 2016
	 * @version 3.2
	 * @access public
	 */
	class Puc_v4_Plugin_Update {
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
		 * @return Puc_v4_Plugin_Update|null
		 */
		public static function fromJson($json){
			//Since update-related information is simply a subset of the full plugin info,
			//we can parse the update JSON as if it was a plugin info string, then copy over
			//the parts that we care about.
			$pluginInfo = Puc_v4_Plugin_Info::fromJson($json);
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
		 * @param Puc_v4_Plugin_Info $info
		 * @return Puc_v4_Plugin_Update
		 */
		public static function fromPluginInfo($info){
			return self::fromObject($info);
		}

		/**
		 * Create a new instance of PluginUpdate by copying the necessary fields from
		 * another object.
		 *
		 * @param StdClass|Puc_v4_Plugin_Info|Puc_v4_Plugin_Update $object The source object.
		 * @return Puc_v4_Plugin_Update The new copy.
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