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
		$updateSource = $this->chooseReference();
		if ( $updateSource ) {
			$ref = $updateSource->name;
			$info->version = $updateSource->version;
			$info->last_updated = $updateSource->updated;
			$info->download_url = $updateSource->downloadUrl;

			if ( !empty($updateSource->changelog) ) {
				$info->sections['changelog'] = $updateSource->changelog;
			}
			if ( isset($updateSource->downloadCount) ) {
				$info->downloaded = $updateSource->downloadCount;
			}
		} else {
			return null;
		}

		if ( !empty($info->download_url) && !empty($this->accessToken) ) {
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
			$info->sections['changelog'] = $api->getRemoteChangelog($ref, dirname($this->getAbsolutePath()));
			if ( empty($info->sections['changelog']) ) {
				$info->sections['changelog'] = __('There is no changelog available.', 'plugin-update-checker');
			}
		}

		if ( empty($info->last_updated) ) {
			//Fetch the latest commit that changed the tag/branch and use it as the "last_updated" date.
			$info->last_updated = $api->getLatestCommitTime($ref);
		}

		$info = apply_filters($this->getUniqueName('request_info_result'), $info, null);
		return $info;
	}

	/**
	 * @return Puc_v4_VcsReference|null
	 */
	protected function chooseReference() {
		$api = $this->api;
		$updateSource = null;

		if ( $this->branch === 'master' ) {
			//Use the latest release.
			$updateSource = $api->getLatestRelease();
			if ( $updateSource === null ) {
				//Failing that, use the tag with the highest version number.
				$updateSource = $api->getLatestTag();
			}
		}
		//Alternatively, just use the branch itself.
		if ( empty($ref) ) {
			$updateSource = $api->getBranch($this->branch);
		}

		return $updateSource;
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
		$readme = $this->api->getRemoteReadme($ref);
		if ( empty($readmeTxt) ) {
			return;
		}

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