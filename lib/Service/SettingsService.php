<?php
/**
 * OpenCatalogi Settings Service
 *
 * This file contains the service class for handling settings in the OpenCatalogi application.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCP\AppFramework\Http\JSONResponse;
use OC_App;
use OCA\OpenCatalogi\AppInfo\Application;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving, saving, and loading settings,
 * as well as managing configuration for different object types.
 */
class SettingsService
{

    /**
     * This property holds the name of the application, which is used for identification and configuration purposes.
     *
     * @var string $appName The name of the app.
     */
    private string $appName;

    /**
     * This constant represents the unique identifier for the OpenRegister application, used to check its installation and status.
     *
     * @var string $openRegisterAppId The ID of the OpenRegister app.
     */
    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * This constant defines the minimum version of the OpenRegister application that is required for compatibility and functionality.
     *
     * @var string $minOpenRegisterVersion The minimum required version of OpenRegister.
     */
    private const MIN_OPENREGISTER_VERSION = '0.1.7';


    /**
     * SettingsService constructor.
     *
     * @param IAppConfig         $config     App configuration interface.
     * @param IRequest           $request    Request interface.
     * @param ContainerInterface $container  Container for dependency injection.
     * @param IAppManager        $appManager App manager interface.
     */
    public function __construct(private readonly IAppConfig $config, private readonly IRequest $request, private readonly ContainerInterface $container, private readonly IAppManager $appManager)
    {
        // Indulge in setting the application name for identification and configuration purposes.
        $this->appName = 'opencatalogi';

    }//end __construct()


    /**
     * Checks if OpenRegister is installed and meets version requirements.
     *
     * @param string|null $minVersion Minimum required version (e.g. '1.0.0').
     *
     * @return boolean True if OpenRegister is installed and meets version requirements.
     */
    public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool
    {
        if ($this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === false) {
            return false;
        }

        if ($minVersion === null) {
            return true;
        }

        $currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
        return version_compare($currentVersion, $minVersion, '>=');

    }//end isOpenRegisterInstalled()


    /**
     * Checks if OpenRegister is enabled.
     *
     * @return boolean True if OpenRegister is enabled.
     */
    public function isOpenRegisterEnabled(): bool
    {
        return $this->appManager->isEnabled(self::OPENREGISTER_APP_ID) === true;

    }//end isOpenRegisterEnabled()


    /**
     * Attempts to install or update OpenRegister.
     *
     * @param string|null $minVersion Minimum required version.
     *
     * @return boolean True if installation/update was successful.
     * @throws \RuntimeException If installation/update fails.
     */
    public function installOrUpdateOpenRegister(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool
    {
        try {
            if ($this->isOpenRegisterInstalled($minVersion) === false) {
                // Removed problematic download functionality
                // Then install the downloaded app.
                if (OC_App::installApp(self::OPENREGISTER_APP_ID) === false) {
                    throw new \RuntimeException('Failed to install OpenRegister');
                }

                // Enable the app after installation.
                if (OC_App::enable(self::OPENREGISTER_APP_ID) === false) {
                    throw new \RuntimeException('Failed to enable OpenRegister');
                }
            } else if ($minVersion !== null) {
                // Check if update is needed.
                $currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
                if (version_compare($currentVersion, $minVersion, '<') === true) {
                    // Removed problematic download functionality
                    // Then update the app.
                    if (OC_App::updateApp(self::OPENREGISTER_APP_ID) === false) {
                        throw new \RuntimeException('Failed to update OpenRegister');
                    }
                }

                // Ensure the app is enabled after update.
                if ($this->isOpenRegisterEnabled() === false) {
                    if (OC_App::enable(self::OPENREGISTER_APP_ID) === false) {
                        throw new \RuntimeException('Failed to enable OpenRegister after update');
                    }
                }
            }//end if

            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to install/update OpenRegister: '.$e->getMessage());
        }//end try

    }//end installOrUpdateOpenRegister()


    /**
     * Attempts to auto-configure registers and schemas.
     *
     * @return array The updated configuration.
     * @throws \RuntimeException If auto-configuration fails.
     */
    public function autoConfigure(): array
    {
        try {
            $registerMapper = $this->getRegisterMapper();
            $registers      = $registerMapper->findAll();

            $registerSlug = 'publication';

            if (empty($registers) === true) {
                return [];
            }

            $configuration = [];
            foreach ($this->getSettings()['objectTypes'] as $type) {
                // Try to find a register with a matching name.
                $matchingRegister = null;
                foreach ($registers as $register) {
                    // Convert Register entity to array if needed
                    $registerData = is_array($register) ? $register : $register->jsonSerialize();
                    if (stripos($registerData['slug'], $registerSlug) !== false) {
                        $matchingRegister = $registerData;
                        break;
                    }
                }

                if ($matchingRegister !== null) {
                    $configuration["{$type}_register"] = $matchingRegister['id'];

                    // Try to find a matching schema.
                    if (empty($matchingRegister['schemas']) === false) {
                        foreach ($matchingRegister['schemas'] as $schema) {
                            if (is_array($schema) === true) {
                                continue;
                            }

                            if (stripos($schema['title'], $type) !== false) {
                                $configuration["{$type}_schema"] = $schema['id'];
                                break;
                            }
                        }
                    }
                }
            }//end foreach

            return $configuration;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to auto-configure: '.$e->getMessage());
        }//end try

    }//end autoConfigure()


    /**
     * Initializes the app with all required components.
     *
     * @param string|null $minOpenRegisterVersion Minimum required OpenRegister version.
     *
     * @return array The initialization results.
     */
    public function initialize(?string $minOpenRegisterVersion = self::MIN_OPENREGISTER_VERSION): array
    {
        $results = [
            'openRegister'   => false,
            'autoConfigured' => false,
            'settingsLoaded' => false,
            'errors'         => [],
        ];

        try {
            // Check and install/update OpenRegister.
            if ($this->isOpenRegisterInstalled($minOpenRegisterVersion) === false) {
                $this->installOrUpdateOpenRegister($minOpenRegisterVersion);
            }

            $results['openRegister'] = true;

            // Auto-configure registers and schemas.
            $configuration = $this->autoConfigure();
            if (empty($configuration) === false) {
                $this->updateSettings($configuration);
                $results['autoConfigured'] = true;
            }

            // Load settings from file only if needed.
            if ($this->shouldLoadSettings()) {
                $this->loadSettings();
                $results['settingsLoaded'] = true;
            } else {
                $results['settingsLoaded'] = true;
// Already up to date
            }
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }//end try

        return $results;

    }//end initialize()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the RegisterMapper from the container.
     *
     * @return \OCA\OpenRegister\Db\RegisterMapper|null The RegisterMapper if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getRegisterMapper(): ?\OCA\OpenRegister\Db\RegisterMapper
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
        }

        throw new \RuntimeException('RegisterMapper is not available.');

    }//end getRegisterMapper()


    /**
     * Attempts to retrieve the Schema mapper from the container.
     *
     * @return \OCA\OpenRegister\Db\SchemaMapper|null The Schema mapper if available, null otherwise.
     * @throws \RuntimeException If the mapper is not available.
     */
    public function getSchemaMapper(): ?\OCA\OpenRegister\Db\SchemaMapper
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        }

        throw new \RuntimeException('SchemaMapper is not available.');

    }//end getSchemaMapper()


    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
        }

        throw new \RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()


    /**
     * Retrieve the current settings.
     *
     * @return array The current settings configuration.
     * @throws \RuntimeException If settings retrieval fails.
     */
    public function getSettings(): array
    {
        // Initialize the data array.
        $data                       = [];
        $data['objectTypes']        = [
            'catalog',
            'listing',
            'organization',
            'theme',
            'page',
            'menu',
            'glossary',
        ];
        $data['openRegisters']      = false;
        $data['availableRegisters'] = [];

        // Check if the OpenRegister service is available.
        try {
            $registerMapper = $this->getRegisterMapper();
            if ($registerMapper !== null) {
                $data['openRegisters']      = true;
                $registers                  = $registerMapper->findAll();
                
                // Enrich registers with full schema objects instead of just IDs.
                $data['availableRegisters'] = $this->enrichRegistersWithSchemas($registers);
            }
        } catch (\RuntimeException $e) {
            // Service not available, continue with default values.
        }

        // Build defaults array dynamically based on object types.
        $defaults = [];
        foreach ($data['objectTypes'] as $type) {
            // Always use openregister as source.
            $defaults["{$type}_source"]   = 'openregister';
            $defaults["{$type}_schema"]   = '';
            $defaults["{$type}_register"] = '';
        }

        // Add publishing options defaults.
        $defaults['auto_publish_attachments']      = 'false';
        $defaults['auto_publish_objects']          = 'false';
        $defaults['use_old_style_publishing_view'] = 'false';

        // Get the current values for the object types from the configuration.
        try {
            foreach ($defaults as $key => $defaultValue) {
                $data['configuration'][$key] = $this->config->getValueString($this->appName, $key, $defaultValue);
            }

            return $data;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve settings: '.$e->getMessage());
        }

    }//end getSettings()


    /**
     * Enrich registers with full schema objects instead of just schema IDs.
     *
     * This method takes an array of register entities and replaces their schema ID arrays
     * with full schema objects containing id, title, and other metadata.
     *
     * @param array $registers Array of register entities from OpenRegister.
     *
     * @return array Array of registers with enriched schema data.
     */
    private function enrichRegistersWithSchemas(array $registers): array
    {
        try {
            // Get the schema mapper to fetch full schema objects.
            $schemaMapper = $this->getSchemaMapper();
            if ($schemaMapper === null) {
                // If we can't get the schema mapper, return registers as-is.
                return $registers;
            }

            $enrichedRegisters = [];
            
            foreach ($registers as $register) {
                // Convert register to array if it's an object.
                $registerArray = is_object($register) && method_exists($register, 'jsonSerialize')
                    ? $register->jsonSerialize()
                    : (array) $register;
                
                // Get schema IDs from the register.
                $schemaIds = $registerArray['schemas'] ?? [];
                
                // If schemas is not an array or is empty, keep as-is.
                if (is_array($schemaIds) === false || empty($schemaIds)) {
                    $enrichedRegisters[] = $registerArray;
                    continue;
                }
                
                // Fetch full schema objects for each schema ID.
                $fullSchemas = [];
                foreach ($schemaIds as $schemaId) {
                    // Skip if not a number (already an object).
                    if (is_numeric($schemaId) === false) {
                        $fullSchemas[] = $schemaId;
                        continue;
                    }
                    
                    try {
                        $schema = $schemaMapper->find((int) $schemaId);
                        if ($schema !== null) {
                            // Convert schema entity to array.
                            $schemaArray = is_object($schema) && method_exists($schema, 'jsonSerialize')
                                ? $schema->jsonSerialize()
                                : (array) $schema;
                            $fullSchemas[] = $schemaArray;
                        }
                    } catch (\Exception $e) {
                        // Schema not found, skip it.
                        continue;
                    }
                }
                
                // Replace schema IDs with full schema objects.
                $registerArray['schemas'] = $fullSchemas;
                $enrichedRegisters[]      = $registerArray;
            }
            
            return $enrichedRegisters;
        } catch (\Exception $e) {
            // If enrichment fails, return registers as-is.
            return $registers;
        }//end try

    }//end enrichRegistersWithSchemas()


    /**
     * Update the settings configuration.
     *
     * @param array $data The settings data to update.
     *
     * @return array The updated settings configuration.
     * @throws \RuntimeException If settings update fails.
     */
    public function updateSettings(array $data): array
    {
        try {
            // Update each setting in the configuration.
            foreach ($data as $key => $value) {
                $this->config->setValueString($this->appName, $key, $value);
                // Retrieve the updated value to confirm the change.
                $data[$key] = $this->config->getValueString($this->appName, $key);
            }

            return $data;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update settings: '.$e->getMessage());
        }//end try

    }//end updateSettings()


    /**
     * Get the current publishing options.
     *
     * @return array The current publishing options configuration.
     * @throws \RuntimeException If publishing options retrieval fails.
     */
    public function getPublishingOptions(): array
    {
        try {
            // Retrieve publishing options from configuration with defaults to false.
            $publishingOptions = [
                // Convert string 'true'/'false' to boolean for auto publish attachments setting.
                'auto_publish_attachments'      => $this->config->getValueString($this->appName, 'auto_publish_attachments', 'false') === 'true',
                // Convert string 'true'/'false' to boolean for auto publish objects setting.
                'auto_publish_objects'          => $this->config->getValueString($this->appName, 'auto_publish_objects', 'false') === 'true',
                // Convert string 'true'/'false' to boolean for old style publishing view setting.
                'use_old_style_publishing_view' => $this->config->getValueString($this->appName, 'use_old_style_publishing_view', 'false') === 'true',
            ];

            return $publishingOptions;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve publishing options: '.$e->getMessage());
        }

    }//end getPublishingOptions()


    /**
     * Update the publishing options configuration.
     *
     * @param array $options The publishing options data to update.
     *
     * @return array The updated publishing options configuration.
     * @throws \RuntimeException If publishing options update fails.
     */
    public function updatePublishingOptions(array $options): array
    {
        try {
            // Define valid publishing option keys for security.
            $validOptions = [
                'auto_publish_attachments',
                'auto_publish_objects',
                'use_old_style_publishing_view',
            ];

            $updatedOptions = [];

            // Update each publishing option in the configuration.
            foreach ($validOptions as $option) {
                // Check if this option is provided in the input data.
                if (isset($options[$option]) === true) {
                    // Convert boolean or string to string format for storage.
                    $value = $options[$option] === true || $options[$option] === 'true' ? 'true' : 'false';
                    // Store the value in the configuration.
                    $this->config->setValueString($this->appName, $option, $value);
                    // Retrieve and convert back to boolean for the response.
                    $updatedOptions[$option] = $this->config->getValueString($this->appName, $option) === 'true';
                }
            }

            return $updatedOptions;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update publishing options: '.$e->getMessage());
        }//end try

    }//end updatePublishingOptions()


    /**
     * Load settings from the publication_register_magic.json file.
     *
     * This method supports both old and new versions of OpenRegister:
     * - New version (>= 0.2.10): Uses importFromFilePath method.
     * - Old version (< 0.2.10): Uses importFromApp method with manual file reading.
     *
     * @param boolean $force Whether to force the import regardless of version checks.
     *
     * @return array The loaded settings configuration.
     * @throws \RuntimeException If settings loading fails.
     */
    public function loadSettings(bool $force = false): array
    {
        try {
            // Get the absolute path to the app directory
            $appPath = $this->appManager->getAppPath(Application::APP_ID);
            
            // Build absolute path to the file (use magic version for better performance)
            $absoluteFilePath = $appPath . '/lib/Settings/publication_register_magic.json';
            
            // Check if file exists
            if (file_exists($absoluteFilePath) === false) {
                throw new \RuntimeException("Configuration file not found: {$absoluteFilePath}");
            }
            
            // Read the JSON file content
            $jsonContent = file_get_contents($absoluteFilePath);
            if ($jsonContent === false) {
                throw new \RuntimeException("Failed to read configuration file: {$absoluteFilePath}");
            }
            
            // Parse JSON
            $data = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in configuration file: " . json_last_error_msg());
            }
            
            // Calculate relative path from Nextcloud root for sourceUrl tracking
            // appPath is something like /var/www/html/apps-extra/opencatalogi
            // We need to get the part after the Nextcloud root
            $nextcloudRoot = dirname(dirname(dirname($appPath))); // Go up from apps-extra/opencatalogi to root
            $relativeFilePath = str_replace($nextcloudRoot . '/', '', $absoluteFilePath);
            
            // Fallback: if path calculation fails, use the standard relative path
            if (str_starts_with($relativeFilePath, 'apps-extra/') === false && str_starts_with($relativeFilePath, 'apps/') === false) {
                $relativeFilePath = 'apps-extra/opencatalogi/lib/Settings/publication_register_magic.json';
            }
            
            // Set the sourceUrl in the data if not already set
            // This allows the cron job to track the file location
            if (isset($data['x-openregister']) === false) {
                $data['x-openregister'] = [];
            }
            if (isset($data['x-openregister']['sourceUrl']) === false) {
                $data['x-openregister']['sourceUrl'] = $relativeFilePath;
            }
            if (isset($data['x-openregister']['sourceType']) === false) {
                $data['x-openregister']['sourceType'] = 'local';
            }
            
            // Get the configuration service
            $configurationService = $this->getConfigurationService();

            // Get the current app version dynamically.
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);

            // Use importFromApp to import the configuration data directly
            // This avoids the file path resolution issue in importFromFilePath
            return $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $data,
                version: $currentAppVersion,
                force: $force
            );

            // Update app configuration with imported schema and register IDs.
            $this->updateObjectTypeConfiguration($result);

            return $result;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to load settings: '.$e->getMessage());
        }//end try

    }//end loadSettings()


    /**
     * Update the app configuration with imported schema and register IDs.
     *
     * After importing the configuration from OpenRegister, this method updates
     * the app configuration with the actual schema and register IDs so that
     * the frontend can construct correct API URLs.
     *
     * @param array $importResult The result from the importFromApp call containing registers and schemas.
     *
     * @return void
     */
    private function updateObjectTypeConfiguration(array $importResult): void
    {
        // Get the object types that need configuration.
        $objectTypes = [
            'catalog',
            'listing',
            'organization',
            'theme',
            'page',
            'menu',
            'glossary',
        ];

        // Build a map of schema slugs to schema IDs.
        $schemaMap = [];
        foreach (($importResult['schemas'] ?? []) as $index => $schema) {
            // Handle both object and array formats.
            if (is_object($schema) === true) {
                // Nextcloud entities have jsonSerialize() method to convert to array.
                if (method_exists($schema, 'jsonSerialize') === true) {
                    $schemaArray = $schema->jsonSerialize();
                    if (isset($schemaArray['slug']) === true && isset($schemaArray['id']) === true) {
                        $schemaMap[$schemaArray['slug']] = $schemaArray['id'];
                    }
                }
            } else if (is_array($schema) === true && isset($schema['slug']) === true) {
                $schemaMap[$schema['slug']] = ($schema['id'] ?? $schema['uuid'] ?? null);
            }
        }

        // Get the register ID (all schemas share the same publication register).
        $registerId = null;
        foreach (($importResult['registers'] ?? []) as $register) {
            // Handle both object and array formats.
            if (is_object($register) === true) {
                // Nextcloud entities have jsonSerialize() method to convert to array.
                if (method_exists($register, 'jsonSerialize') === true) {
                    $registerArray = $register->jsonSerialize();
                    if (isset($registerArray['slug']) === true && $registerArray['slug'] === 'publication') {
                        $registerId = ($registerArray['id'] ?? $registerArray['uuid'] ?? null);
                        break;
                    }
                }
            } else if (is_array($register) === true && isset($register['slug']) === true) {
                // Already an array.
                if ($register['slug'] === 'publication') {
                    $registerId = ($register['id'] ?? $register['uuid'] ?? null);
                    break;
                }
            }
        }

        // Update configuration for each object type.
        foreach ($objectTypes as $type) {
            // Set source to openregister.
            $this->config->setValueString($this->appName, "{$type}_source", 'openregister');

            // Set schema ID if found in the import result.
            if (isset($schemaMap[$type]) === true && $schemaMap[$type] !== null) {
                $this->config->setValueString($this->appName, "{$type}_schema", (string) $schemaMap[$type]);
            }

            // Set register ID if found.
            if ($registerId !== null) {
                $this->config->setValueString($this->appName, "{$type}_register", (string) $registerId);
            }
        }

    }//end updateObjectTypeConfiguration()


    /**
     * Check if settings should be loaded based on version comparison.
     *
     * This method compares the current app version with the stored configuration
     * version to determine if a settings import is needed.
     *
     * @return boolean True if settings should be loaded, false otherwise.
     * @throws \RuntimeException If version checking fails.
     */
    private function shouldLoadSettings(): bool
    {
        try {
            // Get the current app version
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);

            // Get the configuration service to check stored version
            $configurationService = $this->getConfigurationService();
            $storedVersion        = $configurationService->getConfiguredAppVersion(Application::APP_ID);

            // If no stored version exists, we need to load settings
            if ($storedVersion === null) {
                return true;
            }

            // Compare versions using semantic versioning
            // Load settings if current version is newer than stored version
            return version_compare($currentAppVersion, $storedVersion, '>');
        } catch (\Exception $e) {
            // If we can't determine versions, err on the side of loading settings
            return true;
        }//end try

    }//end shouldLoadSettings()


    /**
     * Get version information for the app and configuration.
     *
     * This method returns version information including the current app version
     * and the stored configuration version in OpenRegister.
     *
     * @return array Version information with app and configuration versions.
     * @throws \RuntimeException If version retrieval fails.
     */
    public function getVersionInfo(): array
    {
        try {
            // Get the current app version
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);

            // Get the configuration service to check stored version
            $configurationService = $this->getConfigurationService();
            $storedConfigVersion  = $configurationService->getConfiguredAppVersion(Application::APP_ID);

            // Determine if versions match
            $versionsMatch = $storedConfigVersion !== null &&
                           version_compare($currentAppVersion, $storedConfigVersion, '=');

            return [
                'appName'           => 'OpenCatalogi',
                'appVersion'        => $currentAppVersion,
                'configuredVersion' => $storedConfigVersion,
                'versionsMatch'     => $versionsMatch,
                'needsUpdate'       => $storedConfigVersion === null ||
                               version_compare($currentAppVersion, $storedConfigVersion, '>'),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to get version information: '.$e->getMessage());
        }//end try

    }//end getVersionInfo()


    /**
     * Manually trigger configuration import from JSON.
     *
     * This method allows system administrators to manually trigger the import
     * process, bypassing version checks.
     *
     * @param boolean $forceImport Whether to force import regardless of version.
     *
     * @return array The import results with success/error information.
     */
    public function manualImport(bool $forceImport = false): array
    {
        try {
            // Get version info first
            $versionInfo = $this->getVersionInfo();

            // Check if import is needed (unless forced)
            if ($forceImport === false && $versionInfo['versionsMatch']) {
                return [
                    'success'     => false,
                    'message'     => 'Configuration is already up to date. Use force import if you want to reimport.',
                    'versionInfo' => $versionInfo,
                ];
            }

            // Perform the import
            $importResult = $this->loadSettings($forceImport);

            // Get updated version info
            $updatedVersionInfo = $this->getVersionInfo();

            return [
                'success'      => true,
                'message'      => 'Configuration imported successfully.',
                'importResult' => $importResult,
                'versionInfo'  => $updatedVersionInfo,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end manualImport()


}//end class
