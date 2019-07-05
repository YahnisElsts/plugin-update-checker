<?php
/**
 * Plugin Update Checker Library 4.7
 * http://w-shadow.com/
 *
 * Copyright 2019 Janis Elsts
 * Released under the MIT license. See license.txt for details.
 */

require dirname(__FILE__) . '/Puc/v4p7/Factory.php';
require dirname(__FILE__) . '/Puc/v4/Factory.php';
require dirname(__FILE__) . '/Puc/v4p7/Autoloader.php';
new Puc_v4p7_Autoloader();

//Register classes defined in this version with the factory.
foreach (
	array(
		'Plugin_UpdateChecker' => 'Puc_v4p7_Plugin_UpdateChecker',
		'Theme_UpdateChecker'  => 'Puc_v4p7_Theme_UpdateChecker',

		'Vcs_PluginUpdateChecker' => 'Puc_v4p7_Vcs_PluginUpdateChecker',
		'Vcs_ThemeUpdateChecker'  => 'Puc_v4p7_Vcs_ThemeUpdateChecker',

		'GitHubApi'    => 'Puc_v4p7_Vcs_GitHubApi',
		'BitBucketApi' => 'Puc_v4p7_Vcs_BitBucketApi',
		'GitLabApi'    => 'Puc_v4p7_Vcs_GitLabApi',
	)
	as $pucGeneralClass => $pucVersionedClass
) {
	Puc_v4_Factory::addVersion($pucGeneralClass, $pucVersionedClass, '4.7');
	//Also add it to the minor-version factory in case the major-version factory
	//was already defined by another, older version of the update checker.
	Puc_v4p7_Factory::addVersion($pucGeneralClass, $pucVersionedClass, '4.7');
}
