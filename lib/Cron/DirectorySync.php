<?php
/**
 * Directory Sync Cron Job for OpenCatalogi.
 *
 * @category Cron
 * @package  OCA\OpenCatalogi\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Docs: https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class DirectorySync extends TimedJob
{


    /**
     * Constructor for DirectorySync cron job.
     *
     * @param ITimeFactory     $time             Time factory for scheduling.
     * @param DirectoryService $directoryService Service for directory synchronization.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly DirectoryService $directoryService
    ) {
        parent::__construct($time);

        // Run every minute.
        $this->setInterval(60);

        // Delay until low-load time.
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time.
        $this->setAllowParallelRuns(false);

    }//end __construct()


    /**
     * Run the cron sync job.
     *
     * @param array $arguments Arguments passed to the job.
     *
     * @return void
     */
    protected function run(array $arguments): void
    {
        // Disabled for now; triggers too many times and needs fixing/refactor.
        $this->directoryService->doCronSync();

    }//end run()


}//end class
