<?php

/**
 * Broadcast Cron Job
 *
 * This background job handles the periodic broadcasting of this OpenCatalogi directory
 * to other instances in the network. It runs every 4 hours to notify external
 * directories about this instance.
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

use OCA\OpenCatalogi\Service\BroadcastService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use Psr\Log\LoggerInterface;

/**
 * Broadcast Background Job
 *
 * Handles periodic broadcasting of this OpenCatalogi directory to other instances.
 * This job runs every 4 hours to maintain directory synchronization across the network.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class Broadcast extends TimedJob
{


    /**
     * Constructor for Broadcast cron job
     *
     * @param ITimeFactory     $time             Time factory for scheduling
     * @param BroadcastService $broadcastService Service for handling broadcasts
     * @param LoggerInterface  $logger           Logger for recording broadcast activities
     */
    public function __construct(
        ITimeFactory $time,
        private readonly BroadcastService $broadcastService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($time);

        // Set interval to 4 hours (4 * 60 * 60 = 14400 seconds)
        $this->setInterval(14400);

        // Set job to run during low-load times to minimize system impact
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Prevent parallel runs to avoid duplicate broadcasts
        $this->setAllowParallelRuns(false);

    }//end __construct()


    /**
     * Execute the broadcast job
     *
     * This method is called by the Nextcloud background job system every 4 hours.
     * It broadcasts this directory to all known external OpenCatalogi instances.
     *
     * @param array $arguments Arguments passed to the job (unused in this implementation)
     *
     * @return void
     *
     * @throws \Exception When broadcasting fails critically
     */
    protected function run($arguments): void
    {
        try {
            // Log the start of the broadcast process
            $this->logger->info('Starting scheduled broadcast of OpenCatalogi directory');

            // Perform the broadcast to all known directories
            // Passing null means broadcast to all known instances
            $this->broadcastService->broadcast(null);

            // Log successful completion
            $this->logger->info('Successfully completed scheduled broadcast of OpenCatalogi directory');
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            $this->logger->error(
                'Failed to complete scheduled broadcast: '.$e->getMessage(),
                [
                    'exception' => $e,
                    'trace'     => $e->getTraceAsString(),
                ]
            );

            // Re-throw the exception to mark the job as failed
            throw $e;
        }//end try

    }//end run()


}//end class
