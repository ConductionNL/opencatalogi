<?php
use OCP\Util;
use OCA\OpenCatalogi\Service\ScriptManifestLoader;

$appId = OCA\OpenCatalogi\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'adminSettings', $appId . '-settings');
Util::addStyle($appId, 'main');

?>

<div id="settings"></div>