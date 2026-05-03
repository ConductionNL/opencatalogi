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
 */

namespace OCA\OpenCatalogi\Cron;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;

/**
 * Background job for periodic directory synchronization.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class DirectorySync extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time             Time factory for scheduling.
     * @param DirectoryService $directoryService The directory service.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly DirectoryService $directoryService
    ) {
        parent::__construct($time);

        // Run every hour.
        $this->setInterval(3600);

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
     */
    protected function run($argument): void
    {
        $this->directoryService->doCronSync();

    }//end run()
}//end class
