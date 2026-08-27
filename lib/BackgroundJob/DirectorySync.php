<?php

/**
 * Directory sync cron job.
 *
 * @category Cron
 * @package  OCA\OpenCatalogi\BackgroundJob
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
 * @spec openspec/specs/dashboard/spec.md
 */

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Background job for periodic directory synchronization.
 *
 * @see https://docs.nextcloud.com/server/latest/developer_manual/basics/backgroundjobs.html
 */
class DirectorySync extends TimedJob {
	/**
	 * Minimum allowed interval in seconds (15 minutes).
	 *
	 * The bounds live on SettingsService, which is what publishes them through
	 * getSyncOptions() and clamps a submitted value in updateSyncOptions().
	 * They were defined here and read from there, which both coupled a service
	 * to a cron class and left the same clamp expressed in two places. These
	 * aliases keep `DirectorySync::MIN_INTERVAL_SECONDS` working for any
	 * existing caller.
	 *
	 * @var integer
	 */
	public const MIN_INTERVAL_SECONDS = SettingsService::MIN_INTERVAL_SECONDS;

	/**
	 * Maximum allowed interval in seconds (24 hours).
	 *
	 * @var integer
	 */
	public const MAX_INTERVAL_SECONDS = SettingsService::MAX_INTERVAL_SECONDS;

	/**
	 * Default interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_INTERVAL_SECONDS = SettingsService::DEFAULT_INTERVAL_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for scheduling.
	 * @param DirectoryService $directoryService The directory service.
	 * @param IAppConfig $config App config for reading the sync interval.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DirectoryService $directoryService,
		IAppConfig $config,
	) {
		parent::__construct(time: $time);

		// Read interval from IAppConfig, clamped to [MIN_INTERVAL_SECONDS, MAX_INTERVAL_SECONDS].
		// A gewijzigde waarde is direct actief bij de volgende scheduling-tick omdat Nextcloud
		// per tick een nieuwe TimedJob instantieert.
		$configured = (int)$config->getValueInt(
			'opencatalogi',
			'sync_interval_seconds',
			self::DEFAULT_INTERVAL_SECONDS
		);
		$interval = max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, $configured));

		$this->setInterval(seconds: $interval);

		// Delay until low-load time.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the cron sync.
	 *
	 * @param array $argument Arguments passed to the job.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	protected function run($argument): void {
		$this->directoryService->doCronSync();

	}//end run()
}//end class
