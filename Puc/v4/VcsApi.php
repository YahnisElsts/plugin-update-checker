<?php
if ( !class_exists('Puc_v4_VcsApi') ):

	abstract class Puc_v4_VcsApi {
		/**
		 * Get the readme.txt file from the remote repository and parse it
		 * according to the plugin readme standard.
		 *
		 * @param string $ref Tag or branch name.
		 * @return array Parsed readme.
		 */
		public function getRemoteReadme($ref = 'master') {
			$fileContents = $this->getRemoteFile('readme.txt', $ref);
			if ( empty($fileContents) ) {
				return array();
			}

			$parser = new PucReadmeParser();
			return $parser->parse_readme_contents($fileContents);
		}

		/**
		 * Get a branch.
		 *
		 * @param string $branchName
		 * @return Puc_v4_VcsReference|null
		 */
		abstract public function getBranch($branchName);

		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return Puc_v4_VcsReference|null
		 */
		abstract public function getTag($tagName);

		/**
		 * Get the tag that looks like the highest version number.
		 * (Implementations should skip pre-release versions if possible.)
		 *
		 * @return Puc_v4_VcsReference|null
		 */
		abstract public function getLatestTag();

		/**
		 * Check if a tag name string looks like a version number.
		 *
		 * @param string $name
		 * @return bool
		 */
		protected function looksLikeVersion($name) {
			//Tag names may be prefixed with "v", e.g. "v1.2.3".
			$name = ltrim($name, 'v');

			//The version string must start with a number.
			if ( !is_numeric($name) ) {
				return false;
			}

			//The goal is to accept any SemVer-compatible or "PHP-standardized" version number.
			return (preg_match('@^(\d{1,5}?)(\.\d{1,10}?){0,4}?($|[abrdp+_\-]|\s)@i', $name) === 1);
		}

		/**
		 * Compare two tag names as if they were version number.
		 *
		 * @param string $tag1
		 * @param string $tag2
		 * @return int
		 */
		protected function compareTagNames($tag1, $tag2) {
			if ( !isset($tag1) ) {
				return 1;
			}
			if ( !isset($tag2) ) {
				return -1;
			}
			return -version_compare(ltrim($tag1, 'v'), ltrim($tag2, 'v'));
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		abstract public function getRemoteFile($path, $ref = 'master');

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		abstract public function getLatestCommitTime($ref);

		/**
		 * Get the contents of the changelog file from the repository.
		 *
		 * @param string $ref
		 * @param string $localDirectory Full path to the local plugin or theme directory.
		 * @return null|string The HTML contents of the changelog.
		 */
		public function getRemoteChangelog($ref, $localDirectory) {
			$filename = $this->findChangelogName($localDirectory);
			if ( empty($filename) ) {
				return null;
			}

			$changelog = $this->getRemoteFile($filename, $ref);
			if ( $changelog === null ) {
				return null;
			}

			return Parsedown::instance()->text($changelog);
		}

		/**
		 * Guess the name of the changelog file.
		 *
		 * @param string $directory
		 * @return string|null
		 */
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
