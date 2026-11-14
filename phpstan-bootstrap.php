<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');

// OpenRegister's MCP contracts: load the test stubs when OpenRegister is not
// installed, so static analysis can resolve the interface
// OpenCatalogiScannableServices implements and the #[McpTool] attribute.
if (interface_exists('OCA\\OpenRegister\\Mcp\\IMcpScannableServices') === false) {
    require_once __DIR__ . '/tests/Stubs/OpenRegister/Mcp/IMcpScannableServices.php';
}

if (class_exists('OCA\\OpenRegister\\Mcp\\Attribute\\McpTool') === false) {
    require_once __DIR__ . '/tests/Stubs/OpenRegister/Mcp/Attribute/McpTool.php';
}
