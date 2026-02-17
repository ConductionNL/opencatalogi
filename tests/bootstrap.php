<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Only load the Nextcloud app when running inside a Nextcloud environment
if (class_exists('OC_App')) {
	\OC_App::loadApp(OCA\OpenCatalogi\AppInfo\Application::APP_ID);
	OC_Hook::clear();
}
