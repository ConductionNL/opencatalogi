<?php

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


    public function __construct(
        ITimeFactory $time,
        private readonly DirectoryService $directoryService
    ) {
        parent::__construct($time);

        // Run every hour
        $this->setInterval(3600);

        // Delay until low-load time
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time.
        $this->setAllowParallelRuns(false);

    }//end __construct()


    /**
     * Lets run the cron sync
     *
     * @param array            $arguments
     * @param DirectoryService $directoryService
     */
    protected function run($arguments)
    {
        $this->directoryService->doCronSync();

    }//end run()


}//end class
