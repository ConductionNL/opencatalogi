<?php
/**
 * Directory sync cron job.
 *
 * @category Cron
 * @package  OCA\OpenCatalogi\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

namespace OCA\OpenCatalogi\Cron;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\IAppConfig;

/**
 * Background job for periodic directory synchronization.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class DirectorySync extends TimedJob
{
    /**
     * Minimum allowed interval in seconds (15 minutes).
     *
     * @var integer
     */
    public const MIN_INTERVAL_SECONDS = 900;

    /**
     * Maximum allowed interval in seconds (24 hours).
     *
     * @var integer
     */
    public const MAX_INTERVAL_SECONDS = 86400;

    /**
     * Default interval in seconds (1 hour).
     *
     * @var integer
     */
    public const DEFAULT_INTERVAL_SECONDS = 3600;

    /**
     * Constructor.
     *
     * @param ITimeFactory     $time             Time factory for scheduling.
     * @param DirectoryService $directoryService The directory service.
     * @param IAppConfig       $config           App config for reading the sync interval.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly DirectoryService $directoryService,
        IAppConfig $config
    ) {
        parent::__construct($time);

        // Read interval from IAppConfig, clamped to [MIN_INTERVAL_SECONDS, MAX_INTERVAL_SECONDS].
        // A gewijzigde waarde is direct actief bij de volgende scheduling-tick omdat Nextcloud
        // per tick een nieuwe TimedJob instantieert.
        $configured = (int) $config->getValueInt(
            'opencatalogi',
            'sync_interval_seconds',
            self::DEFAULT_INTERVAL_SECONDS
        );
        $interval   = max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, $configured));

        $this->setInterval($interval);

        // Delay until low-load time.
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time.
        $this->setAllowParallelRuns(false);

    }//end __construct()

    /**
     * Run the cron sync.
     *
     * @param array $argument Arguments passed to the job.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/dashboard/spec.md
     */
    protected function run($argument): void
    {
        $this->directoryService->doCronSync();

    }//end run()
}//end class
