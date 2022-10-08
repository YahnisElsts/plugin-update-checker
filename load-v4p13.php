<?php
require dirname(__FILE__) . '/Puc/v5p0/Autoloader.php';
new Puc_v5p0_Autoloader();

require dirname(__FILE__) . '/Puc/v5p0/Factory.php';
require dirname(__FILE__) . '/Puc/v5/Factory.php';

//Register classes defined in this version with the factory.
foreach (
	array(
		'Plugin_UpdateChecker' => 'Puc_v5p0_Plugin_UpdateChecker',
		'Theme_UpdateChecker'  => 'Puc_v5p0_Theme_UpdateChecker',

		'Vcs_PluginUpdateChecker' => 'Puc_v5p0_Vcs_PluginUpdateChecker',
		'Vcs_ThemeUpdateChecker'  => 'Puc_v5p0_Vcs_ThemeUpdateChecker',

		'GitHubApi'    => 'Puc_v5p0_Vcs_GitHubApi',
		'BitBucketApi' => 'Puc_v5p0_Vcs_BitBucketApi',
		'GitLabApi'    => 'Puc_v5p0_Vcs_GitLabApi',
	)
	as $pucGeneralClass => $pucVersionedClass
) {
	Puc_v4_Factory::addVersion($pucGeneralClass, $pucVersionedClass, '5.0');
	//Also add it to the minor-version factory in case the major-version factory
	//was already defined by another, older version of the update checker.
	Puc_v5p0_Factory::addVersion($pucGeneralClass, $pucVersionedClass, '5.0');
}

