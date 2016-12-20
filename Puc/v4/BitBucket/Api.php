<?php
if ( !class_exists('Puc_v4_BitBucket_Api', false) ):

	class Puc_v4_BitBucket_Api {
		public function __construct($repositoryUrl, $credentials = array()) {

		}

		/**
		 * @param string $ref
		 * @return array
		 */
		public function getRemoteReadme($ref) {
			return array();
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return stdClass|null
		 */
		public function getTag($tagName) {
			return null;
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return stdClass|null
		 */
		public function getLatestTag() {
			return null;
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function getRemoteFile($path, $ref = 'master') {
			return null;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function getLatestCommitTime($ref) {
			return null;
		}

		public function getRemoteChangelog($ref, $localDirectory) {
			$filename = $this->findChangelogName($localDirectory);
			if ( empty($filename) ) {
				return null;
			}

			$changelog = $this->getRemoteFile($filename, $ref);
			if ( $changelog === null ) {
				return null;
			}

			/** @noinspection PhpUndefinedClassInspection */
			$instance = Parsedown::instance();
			return $instance->text($changelog);
		}

		protected function findChangelogName($directory) {
			if ( empty($directory) || !is_dir($directory) || ($directory === '.') ) {
				return null;
			}

			$possibleNames = array('CHANGES.md', 'CHANGELOG.md', 'changes.md', 'changelog.md');
			$files = scandir($directory);
			$foundNames = array_intersect($possibleNames, $files);

			if ( !empty($foundNames) ) {
				return reset($foundNames);
			}
			return null;
		}
	}

endif;