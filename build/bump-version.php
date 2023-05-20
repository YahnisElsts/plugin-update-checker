<?php

function versionToInfix($versionNumber) {
	if ( !isValidVersion($versionNumber) ) {
		throw new InvalidArgumentException("Invalid version number: $versionNumber");
	}

	$parts = explode('.', $versionNumber);
	return 'v' . $parts[0] . 'p' . $parts[1];
}

function infixToVersion($infix) {
	$parts = explode('p', substr($infix, 1));
	$version = $parts[0] . '.' . $parts[1];
	if ( !isValidVersion($version) ) {
		throw new InvalidArgumentException("Invalid version infix: $infix");
	}
	return $version;
}

function isValidVersion($version) {
	return preg_match('/^\d+\.\d+$/', $version);
}

//Switch to the parent directory so that we can use relative paths where needed.
$oldDir = getcwd();
$repositoryRoot = __DIR__ . '/..';
chdir($repositoryRoot);

function updateVersionNumbers($filePath, $oldVersion, $newVersion) {
	$content = file_get_contents($filePath);
	if ( $content === false ) {
		echo "Failed to read file: $filePath\n";
		exit(1);
	}

	$content = preg_replace("/\b" . preg_quote($oldVersion, '/') . "\b/", $newVersion, $content);
	$content = preg_replace(
		"/\b" . preg_quote(versionToInfix($oldVersion), '/') . "\b/",
		versionToInfix($newVersion),
		$content
	);
	file_put_contents($filePath, $content);
}

//Check for uncommitted changes.
exec('git status --porcelain', $output, $returnCode);
if ( $returnCode !== 0 ) {
	echo "Failed to check for uncommitted changes. Git not installed or not in a Git repository?\n";
	chdir($oldDir);
	exit(1);
}
if ( !empty($output) ) {
	echo "You have uncommitted changes. Please commit or stash them before running this script.\n";
	chdir($oldDir);
	exit(1);
}

//Get the current version.
$currentVersionDir = glob($repositoryRoot . '/Puc/v*p*')[0];
if ( !is_dir($currentVersionDir) ) {
	echo "Failed to find the current version's subdirectory.\n";
	chdir($oldDir);
	exit(1);
}
$currentVersion = infixToVersion(basename($currentVersionDir));

//Ask the user for the new version number
echo "Current version is $currentVersion. Enter new version number: ";
$newVersion = trim(fgets(STDIN));
if ( !isValidVersion($newVersion) ) {
	echo "Invalid version number: $newVersion\n";
	chdir($oldDir);
	exit(1);
}

//Get the old and new version in vXpY and X.Y formats.
$oldVersionInfix = basename($currentVersionDir);
$newVersionInfix = versionToInfix($newVersion);
$oldVersion = $currentVersion;

//Create a new branch for the version update.
exec("git checkout -b \"version-bump-$newVersion\"");

//Rename the Puc/vXpY directory using Git.
exec("git mv \"Puc/$oldVersionInfix\" \"Puc/$newVersionInfix\"");

//Define the list of directories to search
$directoriesToSearch = ['css', 'js', 'Puc'];

//Replace old version infix and old version number in the source code
foreach ($directoriesToSearch as $dir) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
	foreach ($iterator as $file) {
		if ( $file->isFile() ) {
			updateVersionNumbers($file->getPathname(), $oldVersion, $newVersion);
		}
	}
}

//Update version numbers in certain files that are not in the above subdirectories.
$standaloneFiles = [
	'plugin-update-checker.php',
	'README.md',
	'languages/plugin-update-checker.pot',
];
foreach ($standaloneFiles as $standaloneFile) {
	updateVersionNumbers($repositoryRoot . '/' . $standaloneFile, $oldVersion, $newVersion);
}

//Rename the loader file and update the version numbers.
$oldLoaderFileName = "load-$oldVersionInfix.php";
$newLoaderFileName = "load-$newVersionInfix.php";
exec("git mv \"$oldLoaderFileName\" \"$newLoaderFileName\"");
updateVersionNumbers($repositoryRoot . '/' . $newLoaderFileName, $oldVersion, $newVersion);

//Replace the old loader file name with new one in files that use it.
//plugin-update-checker.php was already updated by the previous step, but we'll
//include it here anyway in case the code order or loader naming scheme changes.
$filesUsingLoader = ['plugin-update-checker.php', 'composer.json'];
foreach ($filesUsingLoader as $fileName) {
	$fullFileName = $repositoryRoot . '/' . $fileName;
	$content = file_get_contents($fullFileName);
	$content = str_replace($oldLoaderFileName, $newLoaderFileName, $content);
	file_put_contents($fullFileName, $content);
}

//Commit the changes.
exec('git add .');
exec("git commit -m \"Bump version number to $newVersion\"");

echo "Version number bumped to $newVersion.\n";

//Switch back to the original directory.
chdir($oldDir);

