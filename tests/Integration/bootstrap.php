<?php
/**
 * Bootstrap file for Integration tests
 *
 * Integration tests make HTTP calls to a running Nextcloud instance,
 * so they don't need the full Nextcloud bootstrap. They only need
 * composer's autoloader for dependencies like Guzzle.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

// Include Composer's autoloader for dependencies (GuzzleHttp, PHPUnit, etc.)
require_once __DIR__ . '/../../vendor/autoload.php';

