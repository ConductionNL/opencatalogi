<?php
/**
 * Retention evaluation cron job.
 *
 * Daily background pass that evaluates publications against their stored
 * retention term and acts per the configured retentionAction (RET-005). This is
 * the only new moving part of the publication-retention-lifecycle change; it is a
 * dumb evaluator that delegates entirely to RetentionService, which consumes the
 * OpenRegister published-predicate, lifecycle declaration and audit trail (hydra
 * ADR-022). Registered via appinfo/info.xml <background-jobs>, the canonical
 * Nextcloud TimedJob registration (NOT the invalid registerJob-on-context pattern
 * that previously left fleet jobs never running).
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
 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
 */

namespace OCA\OpenCatalogi\Cron;

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily retention-evaluation background job.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class RetentionEvaluation extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time             Time factory for scheduling.
     * @param RetentionService $retentionService The retention service.
     * @param LoggerInterface  $logger           Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly RetentionService $retentionService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($time);

        // Run once a day (86400 seconds).
        $this->setInterval(86400);

        // Defer to low-load time.
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only one instance at a time.
        $this->setAllowParallelRuns(false);

    }//end __construct()

    /**
     * Execute the daily retention evaluation.
     *
     * @param array $argument Arguments passed to the job.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
     */
    protected function run($argument): void
    {
        try {
            $counts = $this->retentionService->evaluate();
            $this->logger->info(
                '[RetentionEvaluation] retention pass complete',
                $counts
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                '[RetentionEvaluation] retention pass failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end run()
}//end class
