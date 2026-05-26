<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');

// IMcpToolProvider ships in openregister PR #1466; load the test stub so static
// analysis can resolve the interface OpenCatalogiToolProvider implements.
if (interface_exists('OCA\\OpenRegister\\Mcp\\IMcpToolProvider') === false) {
    require_once __DIR__ . '/tests/Stubs/Mcp/IMcpToolProvider.php';
}
