<?php
/**
 * Bootstrap file for Unit Tests
 *
 * This bootstrap loads the full Nextcloud environment since tests run inside
 * the Nextcloud Docker container. This gives access to \OC::$server and the
 * full DI container, enabling tests to cover code that depends on Nextcloud services.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
if (file_exists(__DIR__ . '/../../../lib/base.php')) {
    require_once __DIR__ . '/../../../lib/base.php';
}

// Load OpenRegister autoloader so its classes are available for mocking.
// Skip if Psr\Log\LoggerInterface is already loaded (avoids v1/v3 conflict
// when OpenRegister's vendor ships an older psr/log than the NC server).
$openRegisterAutoload = __DIR__ . '/../../openregister/vendor/autoload.php';
if (file_exists($openRegisterAutoload)
    && !interface_exists('Psr\Log\LoggerInterface')
) {
    require_once $openRegisterAutoload;
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

error_log('[UNIT TEST BOOTSTRAP] Full Nextcloud bootstrap complete - \OC::$server available');
