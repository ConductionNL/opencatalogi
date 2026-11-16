<?php

/**
 * Daily check of how many of our datasets data.overheid.nl holds.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\DonlRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Asks the DONL CKAN API for our dataset count and counts what our feed carries.
 *
 * The feed is counted as an anonymous harvester reads it: the DCAT-enabled
 * catalogues, each through the read-rule guard of the public feed, every page.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
 */
class DonlHarvestCheck extends TimedJob {

	/**
	 * The most pages counted per catalogue.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param DonlRegistrationServiceInterface $donl Asks DONL and stores the answer.
	 * @param DcatServiceInterface $dcat Builds the feed pages that are counted.
	 * @param PublicationQueryServiceInterface $guard The read-rule guard of the public feed.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DonlRegistrationServiceInterface $donl,
		private readonly DcatServiceInterface $dcat,
		private readonly PublicationQueryServiceInterface $guard,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Count the feed, ask DONL, store both.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	protected function run($argument): void {
		$feed = null;
		try {
			$feed = $this->feedDatasetCount();
		} catch (\Throwable $e) {
			$this->logger->warning('[DonlHarvestCheck] the feed could not be counted: ' . $e->getMessage(), ['exception' => $e]);
		}

		$status = $this->donl->check(feedDatasetCount: $feed);
		if ($status['lastError'] !== '') {
			$this->logger->warning('[DonlHarvestCheck] data.overheid.nl gave no count: ' . $status['lastError']);
		}
	}//end run()

	/**
	 * The number of datasets the public feeds carry.
	 *
	 * @return int The count over every DCAT-enabled catalogue.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function feedDatasetCount(): int {
		$total = 0;
		foreach ($this->dcat->getDcatEnabledCatalogs() as $catalog) {
			$slug = (string)($catalog['slug'] ?? '');
			if ($slug !== '') {
				$total += $this->catalogCount(catalog: $this->guard->applyCatalogReadRuleGuard(catalog: $catalog), slug: $slug);
			}
		}

		return $total;
	}//end feedDatasetCount()

	/**
	 * The datasets of one catalogue feed, over its pages.
	 *
	 * @param array<string, mixed> $catalog The guarded catalogue.
	 * @param string $slug Its slug.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private function catalogCount(array $catalog, string $slug): int {
		$count = 0;
		$page = 1;
		do {
			$meta = ($this->dcat->buildCatalogDocument(catalog: $catalog, catalogSlug: $slug, page: $page)['_meta'] ?? []);
			$count += (int)($meta['count'] ?? 0);
			$page++;
		} while (($meta['hasNext'] ?? false) === true && $page <= self::MAX_PAGES);

		return $count;
	}//end catalogCount()
}//end class
