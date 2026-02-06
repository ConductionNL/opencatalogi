<?php

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
    public function __construct(
        private readonly IConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container
    ) {
    }

    public function getName(): string
    {
        return "Initialize OpenCatalogi settings";
    }

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
            $result = $settingsService->loadSettings(force: false);

            $registerCount = count($result["registers"] ?? []);
            $schemaCount = count($result["schemas"] ?? []);
            $objectCount = count($result["objects"] ?? []);

            $output->info("Configuration loaded: {$registerCount} registers, {$schemaCount} schemas, {$objectCount} objects");
        } catch (\Exception $e) {
            // Non-fatal: log warning but dont fail the repair step.
            $output->warning("Failed to load configuration: " . $e->getMessage());
        }

        $output->advance(1);
        $output->finishProgress();
    }
}
