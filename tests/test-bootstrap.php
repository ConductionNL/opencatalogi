<?php

declare(strict_types=1);

// Bypass checkInstalled to run tests without a fully initialized Nextcloud instance.
define('PHPUNIT_RUN', 1);
define('OC_CONSOLE', 1);

// Point Nextcloud to our readable test config directory.
define('NEXTCLOUD_CONFIG_DIR', '/tmp/nc-config/');

$appDir = '/srv/nextcloud/apps/opencatalogi';
$ncDir  = '/srv/nextcloud';

// Load the app's vendor autoloader.
require_once $appDir . '/vendor/autoload.php';

// Load NC server base to get OCP interfaces in the autoloader.
$basePath = $ncDir . '/lib/base.php';
if (file_exists($basePath) === true) {
    // Suppress config.php permission errors; OC_CONSOLE bypasses checkInstalled.
    set_error_handler(static function (int $errno, string $errstr): bool {
        if (str_contains($errstr, 'config.php') || str_contains($errstr, 'Permission denied')) {
            return true;
        }

        return false;
    });
    try {
        require_once $basePath;
    } catch (\Throwable $e) {
        // Ignore bootstrap errors; interfaces still available via composer ocp.
    }

    restore_error_handler();
}

// Load OpenRegister autoloader for mocking its classes.
$openRegisterAutoload = $appDir . '/../openregister/vendor/autoload.php';
if (file_exists($openRegisterAutoload) === true
    && interface_exists('Psr\Log\LoggerInterface') === false
) {
    require_once $openRegisterAutoload;
}
