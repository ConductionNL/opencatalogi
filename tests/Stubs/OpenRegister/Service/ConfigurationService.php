<?php
/**
 * Stub for OCA\OpenRegister\Service\ConfigurationService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for ConfigurationService used by PHPUnit mocks in bare CI.
 */
class ConfigurationService
{

    /**
     * Check if OpenConnector is available.
     *
     * @return boolean
     */
    public function hasOpenConnector(): bool
    {
        return false;

    }//end hasOpenConnector()


    /**
     * Export config.
     *
     * @param mixed   $input          Input configuration.
     * @param boolean $includeObjects Whether to include objects.
     *
     * @return array<mixed>
     */
    public function exportConfig(mixed $input=[], bool $includeObjects=false): array
    {
        return [];

    }//end exportConfig()


    /**
     * Get the configured app version stored in OR configuration.
     *
     * @param string $appId The app identifier.
     *
     * @return string|null The configured version or null if not set.
     */
    public function getConfiguredAppVersion(string $appId): string|null
    {
        return null;

    }//end getConfiguredAppVersion()


    /**
     * Import configuration data from an app.
     *
     * @param string  $appId   The app identifier.
     * @param array   $data    The configuration data to import.
     * @param string  $version The version string of the configuration.
     * @param boolean $force   Force import even if version matches.
     *
     * @return array<mixed> Import result with registers and schemas.
     */
    public function importFromApp(string $appId, array $data, string $version, bool $force=false): array
    {
        return [];

    }//end importFromApp()


}//end class
