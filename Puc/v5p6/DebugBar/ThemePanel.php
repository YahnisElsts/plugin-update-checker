<?php

namespace YahnisElsts\PluginUpdateChecker\v5p6\DebugBar;

use YahnisElsts\PluginUpdateChecker\v5p6\Theme\UpdateChecker;

if ( !class_exists(ThemePanel::class, false) ):

	class ThemePanel extends Panel {
		/**
		 * @var UpdateChecker
		 */
		protected $updateChecker;

		protected function displayConfigHeader() {
			$this->row('Theme directory', htmlentities($this->updateChecker->directoryName, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
			parent::displayConfigHeader();
		}

		protected function getUpdateFields() {
			return array_merge(parent::getUpdateFields(), array('details_url'));
		}
	}

endif;
