<?php
/**
 * Plugin Update Checker Library 4.0
 * http://w-shadow.com/
 * 
 * Copyright 2016 Janis Elsts
 * Released under the MIT license. See license.txt for details.
 */

require dirname(__FILE__) . '/Puc/v4/Autoloader.php';
new Puc_v4_Autoloader();

//Register classes defined in this file with the factory.
Puc_v4_Factory::addVersion('Plugin_UpdateChecker', 'Puc_v4_Plugin_UpdateChecker', '4.0');
Puc_v4_Factory::addVersion('Theme_UpdateChecker', 'Puc_v4_Theme_UpdateChecker', '4.0');
Puc_v4_Factory::addVersion('GitHub_PluginUpdateChecker', 'Puc_v4_GitHub_PluginUpdateChecker', '4.0');
Puc_v4_Factory::addVersion('GitHub_ThemeUpdateChecker', 'Puc_v4_GitHub_ThemeUpdateChecker', '4.0');
