<?php

if ( !class_exists('Puc_v5p0_DebugBar_ThemePanel', false) ):

	class Puc_v5p0_DebugBar_ThemePanel extends Puc_v5p0_DebugBar_Panel {
		/**
		 * @var Puc_v5p0_Theme_UpdateChecker
		 */
		protected $updateChecker;

		protected function displayConfigHeader() {
			$this->row('Theme directory', htmlentities($this->updateChecker->directoryName));
			parent::displayConfigHeader();
		}

		protected function getUpdateFields() {
			return array_merge(parent::getUpdateFields(), array('details_url'));
		}
	}

endif;
