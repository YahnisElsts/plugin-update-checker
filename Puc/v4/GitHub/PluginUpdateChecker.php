<?php

if ( !class_exists('Puc_v4_GitHub_PluginUpdateChecker', false) ):

class Puc_v4_GitHub_PluginUpdateChecker extends Puc_v4_Plugin_UpdateChecker {
	/**
	 * @var string Either a fully qualified repository URL, or just "user/repo-name".
	 */
	protected $repositoryUrl;

	/**
	 * @var string The branch to use as the latest version. Defaults to "master".
	 */
	protected $branch = 'master';

	/**
	 * @var string GitHub authentication token. Optional.
	 */
	protected $accessToken;

	/**
	 * @var Puc_v4_GitHub_Api
	 */
	protected $api;

	public function __construct(
		$repositoryUrl,
		$pluginFile,
		$slug = '',
		$checkPeriod = 12,
		$optionName = '',
		$muPluginFile = ''
	) {
		$this->repositoryUrl = $repositoryUrl;
		parent::__construct($repositoryUrl, $pluginFile, $slug, $checkPeriod, $optionName, $muPluginFile);
	}

	/**
	 * Set the GitHub branch to use for updates. Defaults to 'master'.
	 *
	 * @param string $branch
	 * @return $this
	 */
	public function setBranch($branch) {
		$this->branch = empty($branch) ? 'master' : $branch;
		return $this;
	}

	/**
	 * Retrieve details about the latest plugin version from GitHub.
	 *
	 * @param array $unusedQueryArgs Unused.
	 * @return Puc_v4_Plugin_Info
	 */
	public function requestInfo($unusedQueryArgs = array()) {
		$api = $this->api = new Puc_v4_GitHub_Api($this->repositoryUrl, $this->accessToken);

		$info = new Puc_v4_Plugin_Info();
		$info->filename = $this->pluginFile;
		$info->slug = $this->slug;

		$this->setInfoFromHeader($this->getPluginHeader(), $info);

		//Figure out which reference (tag or branch) we'll use to get the latest version of the plugin.
		$ref = $this->branch;
		if ( $this->branch === 'master' ) {
			//Use the latest release.
			$release = $api->getLatestRelease();
			if ( $release !== null ) {
				$ref = $release->tag_name;
				$info->version = ltrim($release->tag_name, 'v'); //Remove the "v" prefix from "v1.2.3".
				$info->last_updated = $release->created_at;
				$info->download_url = $release->zipball_url;

				if ( !empty($release->body) ) {
					$info->sections['changelog'] = $this->parseMarkdown($release->body);
				}
				if ( isset($release->assets[0]) ) {
					$info->downloaded = $release->assets[0]->download_count;
				}
			} else {
				//Failing that, use the tag with the highest version number.
				$tag = $api->getLatestTag();
				if ( $tag !== null ) {
					$ref = $tag->name;
					$info->version = $tag->name;
					$info->download_url = $tag->zipball_url;
				}
			}
		}

		if ( empty($info->download_url) ) {
			$info->download_url = $api->buildArchiveDownloadUrl($ref);
		} else if ( !empty($this->accessToken) ) {
			$info->download_url = add_query_arg('access_token', $this->accessToken, $info->download_url);
		}

		//Get headers from the main plugin file in this branch/tag. Its "Version" header and other metadata
		//are what the WordPress install will actually see after upgrading, so they take precedence over releases/tags.
		$mainPluginFile = basename($this->pluginFile);
		$remotePlugin = $api->getRemoteFile($mainPluginFile, $ref);
		if ( !empty($remotePlugin) ) {
			$remoteHeader = $this->getFileHeader($remotePlugin);
			$this->setInfoFromHeader($remoteHeader, $info);
		}

		//Try parsing readme.txt. If it's formatted according to WordPress.org standards, it will contain
		//a lot of useful information like the required/tested WP version, changelog, and so on.
		if ( $this->readmeTxtExistsLocally() ) {
			$this->setInfoFromRemoteReadme($ref, $info);
		}

		//The changelog might be in a separate file.
		if ( empty($info->sections['changelog']) ) {
			$info->sections['changelog'] = $this->getRemoteChangelog($ref);
			if ( empty($info->sections['changelog']) ) {
				$info->sections['changelog'] = __('There is no changelog available.', 'plugin-update-checker');
			}
		}

		if ( empty($info->last_updated) ) {
			//Fetch the latest commit that changed the main plugin file and use it as the "last_updated" date.
			//It's reasonable to assume that every update will change the version number in that file.
			$latestCommit = $api->getLatestCommit($mainPluginFile, $ref);
			if ( $latestCommit !== null ) {
				$info->last_updated = $latestCommit->commit->author->date;
			}
		}

		$info = apply_filters($this->getUniqueName('request_info_result'), $info, null);
		return $info;
	}

	protected function getRemoteChangelog($ref = '') {
		$filename = $this->getChangelogFilename();
		if ( empty($filename) ) {
			return null;
		}

		$changelog = $this->api->getRemoteFile($filename, $ref);
		if ( $changelog === null ) {
			return null;
		}
		return $this->parseMarkdown($changelog);
	}

	protected function getChangelogFilename() {
		$pluginDirectory = dirname($this->pluginAbsolutePath);
		if ( empty($this->pluginAbsolutePath) || !is_dir($pluginDirectory) || ($pluginDirectory === '.') ) {
			return null;
		}

		$possibleNames = array('CHANGES.md', 'CHANGELOG.md', 'changes.md', 'changelog.md');
		$files = scandir($pluginDirectory);
		$foundNames = array_intersect($possibleNames, $files);

		if ( !empty($foundNames) ) {
			return reset($foundNames);
		}
		return null;
	}

	/**
	 * Convert Markdown to HTML.
	 *
	 * @param string $markdown
	 * @return string
	 */
	protected function parseMarkdown($markdown) {
		/** @noinspection PhpUndefinedClassInspection */
		$instance = Parsedown::instance();
		return $instance->text($markdown);
	}

	/**
	 * Set the access token that will be used to make authenticated GitHub API requests.
	 *
	 * @param string $accessToken
	 * @return $this
	 */
	public function setAccessToken($accessToken) {
		$this->accessToken = $accessToken;
		return $this;
	}

	/**
	 * Copy plugin metadata from a file header to a PluginInfo object.
	 *
	 * @param array $fileHeader
	 * @param Puc_v4_Plugin_Info $pluginInfo
	 */
	protected function setInfoFromHeader($fileHeader, $pluginInfo) {
		$headerToPropertyMap = array(
			'Version' => 'version',
			'Name' => 'name',
			'PluginURI' => 'homepage',
			'Author' => 'author',
			'AuthorName' => 'author',
			'AuthorURI' => 'author_homepage',

			'Requires WP' => 'requires',
			'Tested WP' => 'tested',
			'Requires at least' => 'requires',
			'Tested up to' => 'tested',
		);
		foreach ($headerToPropertyMap as $headerName => $property) {
			if ( isset($fileHeader[$headerName]) && !empty($fileHeader[$headerName]) ) {
				$pluginInfo->$property = $fileHeader[$headerName];
			}
		}

		if ( !empty($fileHeader['Description']) ) {
			$pluginInfo->sections['description'] = $fileHeader['Description'];
		}
	}

	/**
	 * Copy plugin metadata from the remote readme.txt file.
	 *
	 * @param string $ref GitHub tag or branch where to look for the readme.
	 * @param Puc_v4_Plugin_Info $pluginInfo
	 */
	protected function setInfoFromRemoteReadme($ref, $pluginInfo) {
		$readmeTxt = $this->api->getRemoteFile('readme.txt', $ref);
		if ( empty($readmeTxt) ) {
			return;
		}

		$readme = $this->parseReadme($readmeTxt);

		if ( isset($readme['sections']) ) {
			$pluginInfo->sections = array_merge($pluginInfo->sections, $readme['sections']);
		}
		if ( !empty($readme['tested_up_to']) ) {
			$pluginInfo->tested = $readme['tested_up_to'];
		}
		if ( !empty($readme['requires_at_least']) ) {
			$pluginInfo->requires = $readme['requires_at_least'];
		}

		if ( isset($readme['upgrade_notice'], $readme['upgrade_notice'][$pluginInfo->version]) ) {
			$pluginInfo->upgrade_notice = $readme['upgrade_notice'][$pluginInfo->version];
		}
	}

	protected function parseReadme($content) {
		$parser = new PucReadmeParser();
		return $parser->parse_readme_contents($content);
	}

	/**
	 * Check if the currently installed version has a readme.txt file.
	 *
	 * @return bool
	 */
	protected function readmeTxtExistsLocally() {
		$pluginDirectory = dirname($this->pluginAbsolutePath);
		if ( empty($this->pluginAbsolutePath) || !is_dir($pluginDirectory) || ($pluginDirectory === '.') ) {
			return false;
		}
		return is_file($pluginDirectory . '/readme.txt');
	}
}

endif;