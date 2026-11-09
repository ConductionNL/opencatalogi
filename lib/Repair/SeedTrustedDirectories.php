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
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
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
	 */
	public function __construct(
		private readonly DirectoryServiceInterface $directoryService,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
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
			$known = $this->directoryService->getKnownDirectoryUrls();
		} catch (\Throwable $e) {
			// Without OpenRegister there are no listings, so nothing was crawled
			// beyond the default directory: there is nothing to grandfather.
			$known = [];
		}

		$seeded  = [];
		$skipped = [];
		foreach ($known as $directoryUrl) {
			try {
				$this->directoryService->trustDirectory($directoryUrl);
				$seeded[] = $directoryUrl;
			} catch (\Throwable $e) {
				$skipped[$directoryUrl] = $e->getMessage();
			}
		}

		$this->config->setValueString('opencatalogi', self::DONE_FLAG, '1');

		$this->logger->info(
			'[OpenCatalogi] Directory sync now crawls trusted directories only; seeded the directories it crawled before',
			['seeded' => $seeded, 'skipped' => $skipped]
		);
		$output->info('Trusted '.count($seeded).' previously crawled directories ('.count($skipped).' skipped)');

	}//end run()
}//end class
