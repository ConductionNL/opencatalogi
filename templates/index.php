<?php

use OCP\Util;
use OCA\OpenCatalogi\Service\ScriptManifestLoader;

$appId = OCA\OpenCatalogi\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'main', $appId . '-main');
Util::addStyle($appId, 'main');
?>

<div id="opencatalogi"></div>


