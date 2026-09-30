<?php

/**
 * OpenCatalogi Woo Readiness Check background job.
 *
 * Runs the harvester readiness check once a day while at least one catalogue
 * publishes a Woo sitemap, so the settings panel always shows a current verdict
 * without anyone pressing Run check.
 *
 * @category BackgroundJob
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The daily readiness check.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
 */
class WooReadinessCheck extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time      The time factory.
	 * @param WooReadinessService $readiness The readiness check.
	 * @param LoggerInterface     $logger    Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WooReadinessService $readiness,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the check when a catalogue is Woo-enabled; otherwise do nothing.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The framework passes an argument this job does not take.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
	 */
	protected function run($argument): void {
		try {
			$report = $this->readiness->runWhenEnabled();
			if ($report !== null) {
				$this->logger->info('[WooReadinessCheck] verdict: ' . (string)($report['verdict'] ?? ''));
			}
		} catch (\Throwable $e) {
			$this->logger->error('[WooReadinessCheck] the check failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
