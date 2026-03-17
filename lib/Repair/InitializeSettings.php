<?php
/**
 * Repair step for initializing OpenCatalogi settings.
 *
 * Runs during app install or upgrade to load configuration.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\Service\SettingsService;

/**
 * Repair step that initializes OpenCatalogi settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
 * The configuration import is idempotent - running multiple times will not create duplicates.
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings repair step.
     *
     * @param IConfig            $config     The Nextcloud config service.
     * @param IAppManager        $appManager The app manager.
     * @param ContainerInterface $container  The dependency injection container.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The repair step name.
     */
    public function getName(): string
    {
        return "Initialize OpenCatalogi settings";
    }//end getName()

    /**
     * Run the repair step to initialize settings.
     *
     * @param IOutput $output The output handler for progress reporting.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->startProgress(2);

        // Check if OpenRegister is available (required dependency).
        if (in_array("openregister", $this->appManager->getInstalledApps(), true) === false) {
            $output->warning("OpenRegister app is not installed - skipping configuration import");
            $output->advance(2);
            $output->finishProgress();
            return;
        }

        $output->info("Loading OpenCatalogi configuration...");
        $output->advance(1);

        try {
            // Get the settings service and load configuration.
            // The import is idempotent - existing objects will be skipped, not duplicated.
            $settingsService = $this->container->get(SettingsService::class);
            $result          = $settingsService->loadSettings(force: false);

            $registerCount = count($result["registers"] ?? []);
            $schemaCount   = count($result["schemas"] ?? []);
            $objectCount   = count($result["objects"] ?? []);

            $output->info(
                "Configuration loaded: {$registerCount} registers, {$schemaCount} schemas, {$objectCount} objects"
            );
        } catch (\Exception $e) {
            // Non-fatal: log warning but dont fail the repair step.
            $output->warning("Failed to load configuration: ".$e->getMessage());
        }

        $output->advance(1);
        $output->finishProgress();
    }//end run()
}//end class
