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

        // Initialization disabled due to duplicate key violations (unique_uuid constraint)
        // when importing configuration on app install/update. Users should manually
        // initialize via the settings page if needed.
        $output->info('Automatic initialization disabled - please initialize manually via settings if needed');

        $output->advance(1);
        $output->finishProgress();
    }
}
