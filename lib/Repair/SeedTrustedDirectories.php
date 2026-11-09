<?php

/**
 * One-time repair step that keeps the cron sync crawling what it crawled before.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Seed `trusted_directory_urls` once with the directories the cron sync crawled before.
 *
 * The cron sync used to crawl the `directory` of every stored listing; it now
 * crawls only the directories an administrator added (trusted) plus the default
 * directory. Nothing stored tells an admin-added directory from one learned from
 * a peer, so on the first upgrade every directory the instance already crawled is
 * trusted: on day one the instance crawls exactly what it crawled before. From
 * then on, directories learned from peer data are no longer crawled.
 *
 * The lookup runs inside OpenRegister's system context and across organisations,
 * so a web-started upgrade sees every organisation's listings. The flag is set
 * only after a lookup that succeeded; a failed one is logged and retried on the
 * next upgrade or repair run. A directory that fails its checks now (for
 * instance its name does not resolve) is parked in a pending list, and the next
 * cron sync retries it once (DirectoryService::retryPendingTrust()).
 *
 * Runs once, guarded by an app-config flag. A fresh install runs it too (see
 * appinfo/info.xml) and seeds nothing, so a later upgrade never grandfathers
 * directories learned after the install. On a hub whose default directory is
 * itself, its own URL is excluded and its known peers are seeded.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
 */
class SeedTrustedDirectories implements IRepairStep {
	/**
	 * The app-config flag that marks the seed as done.
	 *
	 * @var string
	 */
	public const DONE_FLAG = 'trusted_directories_seeded';

	/**
	 * Constructor.
	 *
	 * @param DirectoryServiceInterface $directoryService Reads the known directories and trusts them.
	 * @param IAppConfig                $config           App configuration (the run-once flag).
	 * @param LoggerInterface           $logger           Logs the seeded list once.
	 * @param ContainerInterface        $container        Resolves OpenRegister's ObjectService for the system context.
	 * @param IAppManager               $appManager       Tells whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly DirectoryServiceInterface $directoryService,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Repair-step label shown by occ; no domain behaviour.
	 */
	public function getName(): string {
		return 'Trust the directories the OpenCatalogi directory sync already crawled';
	}//end getName()

	/**
	 * Seed the trusted directories, once.
	 *
	 * Each known directory passes the same checks as an admin-added one
	 * (syncable URL, SSRF guard) through trustDirectory(); one that fails is
	 * skipped and logged.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	public function run(IOutput $output): void {
		if ($this->config->getValueString('opencatalogi', self::DONE_FLAG, '') === '1') {
			return;
		}

		try {
			$known = $this->lookupKnownDirectories();
		} catch (\Throwable $e) {
			// Not marked done: "could not read" is not "nothing to seed", so the next
			// upgrade or repair run tries again.
			$this->logger->warning(
				'[OpenCatalogi] Could not read the directories to keep crawling; the seed is retried on the next repair run',
				['error' => $e->getMessage()]
			);
			$output->warning('Could not seed the trusted directories, retried on the next repair run: '.$e->getMessage());
			return;
		}

		$seeded  = [];
		$pending = [];
		foreach ($known as $directoryUrl) {
			try {
				$this->directoryService->trustDirectory($directoryUrl);
				$seeded[] = $directoryUrl;
			} catch (\Throwable $e) {
				$pending[$directoryUrl] = $e->getMessage();
			}
		}

		$this->config->setValueString(
			'opencatalogi',
			DirectoryServiceInterface::CONFIG_PENDING_TRUST,
			(string)json_encode(array_keys($pending))
		);
		$this->config->setValueString('opencatalogi', self::DONE_FLAG, '1');

		$this->logger->info(
			'[OpenCatalogi] Directory sync now crawls trusted directories only; seeded the directories it crawled before',
			['seeded' => $seeded]
		);
		if ($pending !== []) {
			$this->logger->warning(
				'[OpenCatalogi] Directories that failed their checks during the seed; the next directory sync retries them once',
				['pending' => $pending]
			);
		}

		$output->info('Trusted '.count($seeded).' previously crawled directories ('.count($pending).' pending a retry)');

	}//end run()

	/**
	 * The known directories, read in OpenRegister's system context when it offers one.
	 *
	 * @return array<string> The directory URLs.
	 *
	 * @throws \Throwable When they cannot be read.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	private function lookupKnownDirectories(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new \RuntimeException('OpenRegister is not installed');
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		if (is_object($objectService) === true && method_exists($objectService, 'runAsSystem') === true) {
			// System context (rbac-bypass): one-time upgrade seed reading the directory URLs of all stored listings.
			return $objectService->runAsSystem(fn (): array => $this->directoryService->readAllKnownDirectoryUrls());
		}

		return $this->directoryService->readAllKnownDirectoryUrls();

	}//end lookupKnownDirectories()
}//end class
