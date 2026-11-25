<?php

/**
 * OpenCatalogi Finish Upgrade After OpenRegister.
 *
 * Runs this app's post-migration repair steps again once OpenRegister has been
 * upgraded, for an `occ upgrade` that upgraded this app first.
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Repair\LoggedRepairOutput;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the post-migration repair steps of this app once OpenRegister no longer waits for its upgrade.
 *
 * `occ upgrade` takes the apps of one type in alphabetical order, so this app is
 * upgraded before OpenRegister. Its configuration import and the publication
 * backfills need OpenRegister's new tables, so InitializeSettings queues this job
 * instead of importing against the old ones. The steps are idempotent; while
 * OpenRegister still waits, the job queues itself again.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */
class FinishUpgradeAfterOpenRegister extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time       The clock.
	 * @param IAppManager        $appManager Says whether OpenRegister still waits and lists this app's steps.
	 * @param ContainerInterface $container  Resolves each repair step and the job list the job queues itself on again.
	 * @param LoggerInterface    $logger     Receives what the steps report.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run this app's post-migration steps in their declared order, or wait for OpenRegister.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) QueuedJob fixes the signature.
	 */
	protected function run($argument): void {
		if ($this->appManager->isUpgradeRequired(appId: 'openregister') === true) {
			$this->container->get(IJobList::class)->add(job: self::class);
			return;
		}

		$output = new LoggedRepairOutput(logger: $this->logger);
		foreach ($this->postMigrationSteps() as $class) {
			$this->runStep(class: $class, output: $output);
		}

	}//end run()

	/**
	 * The post-migration repair steps this app declares in appinfo/info.xml.
	 *
	 * @return list<string> The step classes, in order.
	 */
	private function postMigrationSteps(): array {
		$info = $this->appManager->getAppInfo(appId: AppIdentity::APP_ID);
		$steps = ($info['repair-steps']['post-migration'] ?? []);

		return array_values(array_filter((array)$steps, 'is_string'));

	}//end postMigrationSteps()

	/**
	 * Run one step; a step that fails is logged and the next one still runs.
	 *
	 * @param string             $class  The step class.
	 * @param LoggedRepairOutput $output Where the step reports.
	 *
	 * @return void
	 */
	private function runStep(string $class, LoggedRepairOutput $output): void {
		try {
			$step = $this->container->get($class);
			if ($step instanceof IRepairStep === true) {
				$step->run($output);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'FinishUpgradeAfterOpenRegister: repair step ' . $class . ' failed: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID, 'exception' => $e]
			);
		}

	}//end runStep()
}//end class
