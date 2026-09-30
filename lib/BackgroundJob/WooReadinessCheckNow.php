<?php

/**
 * OpenCatalogi Woo Readiness Check, queued once.
 *
 * Queued when a catalogue starts publishing a Woo sitemap, so a readiness
 * report exists right after the switch. Queued rather than run in the save,
 * because the check makes outbound requests and a save must not wait on them.
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
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * The readiness check, run once.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
 */
class WooReadinessCheckNow extends QueuedJob {

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

	}//end __construct()

	/**
	 * Run the check.
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
				$this->logger->info('[WooReadinessCheckNow] verdict: ' . (string)($report['verdict'] ?? ''));
			}
		} catch (\Throwable $e) {
			$this->logger->error('[WooReadinessCheckNow] the check failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
