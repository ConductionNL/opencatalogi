<?php

/**
 * OpenCatalogi Saved Search Matching Job.
 *
 * Every 15 minutes, tells residents about new publications that match their
 * saved searches (hydra `woo-citizen-journey`, C2). The work is in
 * SavedSearchMatcher; this job gives it the moment in the instance's time
 * zone (`default_timezone`, else Europe/Amsterdam), so "after 07:00" means
 * 07:00 where the municipality is. Without portaliq nobody can own a saved
 * search, so the job then does nothing.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The saved-search matching run.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */
class SavedSearchMatchingJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time       The time factory.
	 * @param SavedSearchMatcher $matcher    The matching.
	 * @param IAppManager        $appManager Whether portaliq is installed.
	 * @param IConfig            $config     The instance time zone.
	 * @param LoggerInterface    $logger     The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SavedSearchMatcher $matcher,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the matching when portaliq is installed.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The framework passes an argument this job does not take.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	protected function run($argument): void {
		if ($this->appManager->isInstalled('portaliq') === false) {
			return;
		}

		try {
			$stats = $this->matcher->run(now: new DateTimeImmutable('now', $this->zone()));
			if ($stats['handled'] > 0 || $stats['failed'] > 0) {
				$this->logger->info('[SavedSearchMatchingJob] run', $stats);
			}
		} catch (Throwable $e) {
			$this->logger->error('[SavedSearchMatchingJob] the run failed: '.$e->getMessage(), ['exception' => $e]);
		}

	}//end run()

	/**
	 * The instance's time zone, else Europe/Amsterdam.
	 *
	 * @return DateTimeZone
	 */
	private function zone(): DateTimeZone {
		try {
			return new DateTimeZone((string)$this->config->getSystemValue('default_timezone', 'Europe/Amsterdam'));
		} catch (Throwable) {
			return new DateTimeZone('Europe/Amsterdam');
		}

	}//end zone()
}//end class
