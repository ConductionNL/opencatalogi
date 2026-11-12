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

use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

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
	public const MIN_INTERVAL_SECONDS = SettingsServiceInterface::MIN_INTERVAL_SECONDS;

	/**
	 * Maximum allowed interval in seconds (24 hours).
	 *
	 * @var integer
	 */
	public const MAX_INTERVAL_SECONDS = SettingsServiceInterface::MAX_INTERVAL_SECONDS;

	/**
	 * Default interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_INTERVAL_SECONDS = SettingsServiceInterface::DEFAULT_INTERVAL_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for scheduling.
	 * @param DirectoryServiceInterface $directoryService The directory service.
	 * @param IAppConfig $config App config for reading the sync interval.
	 * @param ContainerInterface|null $container Resolves OpenRegister's ObjectService for the system context.
	 * @param IAppManager|null $appManager Tells whether OpenRegister is installed.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DirectoryServiceInterface $directoryService,
		IAppConfig $config,
		// Nullable and last so existing construction sites keep working; without
		// them the job runs the sync as before (no user), which OpenRegister refuses
		// to let write.
		private readonly ?ContainerInterface $container = null,
		private readonly ?IAppManager $appManager = null,
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
	 * Run the cron sync, with OpenRegister's system context for the listing writes only.
	 *
	 * A cron run has no user session, and OpenRegister refuses anonymous writes
	 * unless a schema grants them, so without the system context every listing
	 * create/update of the periodic sync failed. The job is trusted to write
	 * because Nextcloud's cron starts it, not a request, and the trust is scoped
	 * to what needs it (aud-f1):
	 * - it only crawls directories an administrator added plus the default
	 *   directory, never a directory URL learned from peer data;
	 * - the system context wraps only listing register operations: the lookup,
	 *   the save and recording a sync failure on stored listings
	 *   (DirectoryService::withListingWriteRights()); fetching and parsing peer
	 *   data, broadcasts and reporting run without it;
	 * - every fetch passes the SSRF guard, and a peer cannot take over another
	 *   directory's listing (see DirectoryService::newListingUuid()).
	 * Anonymous directory requests (POST /api/directory) do not come through here
	 * and still cannot write listings.
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
		$objectService = $this->systemObjectService();
		if ($objectService === null) {
			$this->directoryService->doCronSync();
			return;
		}

		// System context (rbac-bypass): listing writes of the cron-started sync of admin-added directories; the docblock says why.
		$this->directoryService->doCronSync(
			listingWriteContext: static fn (callable $operation): mixed => $objectService->runAsSystem($operation)
		);

	}//end run()

	/**
	 * OpenRegister's ObjectService when it offers a system context, otherwise null.
	 *
	 * @return object|null The ObjectService.
	 *
	 * @spec exclude Lazy dependency lookup for the cron job; no domain behaviour.
	 */
	private function systemObjectService(): ?object {
		if ($this->container === null || $this->appManager === null
			|| in_array('openregister', $this->appManager->getInstalledApps(), true) === false
		) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

		if (is_object($objectService) === false || method_exists($objectService, 'runAsSystem') === false) {
			return null;
		}

		return $objectService;

	}//end systemObjectService()
}//end class
