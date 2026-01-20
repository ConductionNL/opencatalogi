<?php

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCA\OpenCatalogi\AppInfo\Application;

/**
 * Repair step that initializes OpenCatalogi settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
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
        return 'Initialize OpenCatalogi settings';
    }

    public function run(IOutput $output): void
    {
        $output->startProgress(1);

        try {
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);
            $lastInitializedVersion = $this->config->getAppValue(Application::APP_ID, 'last_initialized_version', '');

            // Only initialize if version changed or never initialized
            if ($lastInitializedVersion === $currentAppVersion) {
                $output->info('Settings already initialized for version ' . $currentAppVersion);
                $output->advance(1);
                $output->finishProgress();
                return;
            }

            $output->info('Initializing settings for version ' . $currentAppVersion);

            // Get the settings service and initialize
            $settingsService = $this->container->get(\OCA\OpenCatalogi\Service\SettingsService::class);
            $result = $settingsService->initialize();

            // Mark this version as initialized regardless of partial failures
            // This prevents repeated attempts on every request
            $this->config->setAppValue(Application::APP_ID, 'last_initialized_version', $currentAppVersion);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $output->warning('Initialization warning: ' . $error);
                }
            }

            $output->info('Settings initialization completed');

        } catch (\Exception $e) {
            // Still mark as initialized to prevent repeated failures
            $currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);
            $this->config->setAppValue(Application::APP_ID, 'last_initialized_version', $currentAppVersion);
            $output->warning('Settings initialization failed: ' . $e->getMessage());
        }

        $output->advance(1);
        $output->finishProgress();
    }
}
