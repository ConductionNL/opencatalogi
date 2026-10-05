<?php

/**
 * Comment period withdrawal cron job.
 *
 * Daily pass that withdraws a publication whose public comment period closed and
 * which was set to be withdrawn when it did. It is the ONLY scheduled part of the
 * comment period: the three states, the reaction form and the close itself are
 * all derived at the read, so a pass that has not run yet never leaves a closed
 * period reading as open.
 *
 * Registered via appinfo/info.xml <background-jobs>, which is the registration
 * that makes a TimedJob run. A job class nobody registers there never runs, and
 * this app already shipped one of those.
 *
 * @category Cron
 * @package  OCA\OpenCatalogi\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily comment-period withdrawal job.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */
class CommentPeriodWithdrawal extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for scheduling.
	 * @param CommentPeriodWithdrawalService $withdrawals The withdrawal pass.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CommentPeriodWithdrawalService $withdrawals,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Once a day. A comment period closes on a date, not at a minute.
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the daily withdrawal pass.
	 *
	 * @param array $argument Arguments passed to the job.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	protected function run($argument): void {
		try {
			$counts = $this->withdrawals->withdrawClosedPeriods();
			$this->logger->info('[CommentPeriodWithdrawal] withdrawal pass complete', $counts);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[CommentPeriodWithdrawal] withdrawal pass failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
