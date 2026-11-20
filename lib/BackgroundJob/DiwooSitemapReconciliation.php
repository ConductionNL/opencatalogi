<?php

/**
 * Daily reconciliation of the DiWoo sitemaps against the register.
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

use DOMDocument;
use DOMXPath;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Per catalogue with `hasWooSitemap` and per category, compares the public documents
 * the register holds with the document locations every sitemap page of that
 * category lists, and stores `{catalog, category, expected, listed, missing, extra,
 * checkedAt, complete}`. A missing or extra entry raises a pipeline incident. A read
 * that fails makes the result incomplete with the reason, never a clean bill.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-sitemaps-are-reconciled-against-the-register-daily-req-wno-003
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A job wiring the sitemap, the readiness catalogues, the store and the incidents.
 */
class DiwooSitemapReconciliation extends TimedJob {

	/**
	 * The app config key that holds the last results.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'diwoo_sitemap_reconciliation';

	/**
	 * The incident kind of a reconciliation gap.
	 *
	 * @var string
	 */
	public const INCIDENT_KIND = 'sitemap-reconciliation';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time      The clock.
	 * @param WooReadinessService $readiness Lists the catalogues with a Woo sitemap.
	 * @param SitemapService      $sitemaps  Reads the register and renders the sitemap pages.
	 * @param IAppConfig          $config    Stores the results.
	 * @param PipelineIncidents   $incidents Tells the incident group about a gap.
	 * @param LoggerInterface     $logger    The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WooReadinessService $readiness,
		private readonly SitemapService $sitemaps,
		private readonly IAppConfig $config,
		private readonly PipelineIncidents $incidents,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Reconcile every category of every Woo catalogue and raise an incident per gap.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-sitemaps-are-reconciled-against-the-register-daily-req-wno-003
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job signature; the job takes no argument.
	 */
	protected function run($argument): void {
		foreach ($this->reconcile() as $result) {
			if ($result['complete'] === false) {
				$subject = $result['catalog'] . '/' . $result['category'];
				$this->logger->warning('[DiwooSitemapReconciliation] ' . $subject . ' incomplete: ' . (string)$result['reason']);
			}

			if ($result['missing'] === [] && $result['extra'] === []) {
				continue;
			}

			$this->incidents->raise(
				kind: self::INCIDENT_KIND,
				subject: $result['catalog'] . '/' . $result['category'],
				message: count($result['missing']) . ' public document(s) missing from the sitemap, '
					. count($result['extra']) . ' listed that the register does not hold publicly'
			);
		}
	}//end run()

	/**
	 * Reconcile every category of every Woo catalogue and store the results.
	 *
	 * @return list<array<string, mixed>> One result per catalogue and category.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-sitemaps-are-reconciled-against-the-register-daily-req-wno-003
	 */
	public function reconcile(): array {
		$results = [];
		foreach ($this->readiness->getWooEnabledCatalogs() as $catalog) {
			$slug = self::slugOf(catalog: $catalog);
			if ($slug === '') {
				continue;
			}

			foreach (array_keys($this->sitemaps->sitemapFiles()) as $category) {
				$results[] = $this->reconcileCategory(catalog: $slug, category: (string)$category);
			}
		}

		$this->config->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($results));

		return $results;
	}//end reconcile()

	/**
	 * The stored results of the last run, or null when it has not run.
	 *
	 * @param IAppConfig $config The app config.
	 *
	 * @return list<array<string, mixed>>|null The results.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-sitemaps-are-reconciled-against-the-register-daily-req-wno-003
	 */
	public static function lastResults(IAppConfig $config): ?array {
		$decoded = json_decode($config->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return array_values($decoded);
	}//end lastResults()

	/**
	 * Compare one category's public documents with what its sitemap pages list.
	 *
	 * @param string $catalog  The catalogue slug.
	 * @param string $category The sitemap file of the category.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function reconcileCategory(string $catalog, string $category): array {
		$result = [
			'catalog' => $catalog,
			'category' => $category,
			'publications' => 0,
			'expected' => 0,
			'listed' => 0,
			'missing' => [],
			'extra' => [],
			'checkedAt' => $this->time->getTime(),
			'complete' => false,
			'reason' => null,
		];

		try {
			$register = $this->sitemaps->publicDocumentLocations(catalogSlug: $catalog, categoryCode: $category);
			$listed = $this->listedLocations(catalog: $catalog, category: $category);
		} catch (\Throwable $e) {
			$result['reason'] = $e->getMessage();
			return $result;
		}

		$result['publications'] = $register['publications'];
		$result['expected'] = count($register['locations']);
		$result['listed'] = count($listed);
		$result['missing'] = array_values(array_diff($register['locations'], $listed));
		$result['extra'] = array_values(array_diff($listed, $register['locations']));
		$result['complete'] = true;

		return $result;
	}//end reconcileCategory()

	/**
	 * The document locations of every page the category's sitemap index names.
	 *
	 * @param string $catalog  The catalogue slug.
	 * @param string $category The sitemap file of the category.
	 *
	 * @return list<string> The locations.
	 *
	 * @throws RuntimeException When the index or a page cannot be built or read.
	 */
	private function listedLocations(string $catalog, string $category): array {
		$index = $this->readXml(response: $this->sitemaps->buildSitemapIndex(catalogSlug: $catalog, categoryCode: $category), what: 'the sitemap index');
		$listed = [];
		foreach (self::locs(xpath: $index, path: '/s:sitemapindex/s:sitemap/s:loc') as $pageUrl) {
			$query = [];
			parse_str((string)parse_url($pageUrl, PHP_URL_QUERY), $query);
			$page = (int)($query['page'] ?? 0);
			if ($page < 1) {
				throw new RuntimeException('the sitemap index names a page without a number: ' . $pageUrl);
			}

			$response = $this->sitemaps->buildSitemap(catalogSlug: $catalog, categoryCode: $category, page: $page);
			$xpath = $this->readXml(response: $response, what: 'sitemap page ' . $page);
			array_push($listed, ...self::locs(xpath: $xpath, path: '/s:urlset/s:url/s:loc'));
		}

		return array_values(array_unique($listed));
	}//end listedLocations()

	/**
	 * A rendered sitemap response as an XPath, refusing an error status or unreadable XML.
	 *
	 * @param XMLResponse $response The response.
	 * @param string                             $what     What it is, for the reason.
	 *
	 * @return DOMXPath The XPath, with `s` for the sitemap namespace.
	 *
	 * @throws RuntimeException When the response is an error or not XML.
	 */
	private function readXml(XMLResponse $response, string $what): DOMXPath {
		if ($response->getStatus() !== 200) {
			throw new RuntimeException($what . ' answered HTTP ' . $response->getStatus());
		}

		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$parsed = $dom->loadXML($response->render());
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($parsed === false) {
			throw new RuntimeException($what . ' is not readable XML');
		}

		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('s', StandardsVersionService::SITEMAP_NAMESPACE);

		return $xpath;
	}//end readXml()

	/**
	 * The trimmed text of every node a path selects.
	 *
	 * @param DOMXPath $xpath The XPath.
	 * @param string    $path  The path.
	 *
	 * @return list<string> The texts.
	 */
	private static function locs(DOMXPath $xpath, string $path): array {
		$locs = [];
		$nodes = $xpath->query($path);
		if ($nodes === false) {
			return [];
		}

		foreach ($nodes as $node) {
			$locs[] = trim((string)$node->textContent);
		}

		return $locs;
	}//end locs()

	/**
	 * The slug of a catalogue row, an entity or an array.
	 *
	 * @param mixed $catalog The catalogue.
	 *
	 * @return string The slug, or '' when it has none.
	 */
	private static function slugOf(mixed $catalog): string {
		if (is_array($catalog) === true) {
			return (string)($catalog['slug'] ?? '');
		}

		if (is_object($catalog) === true) {
			$slug = $catalog->getSlug();
			if (is_string($slug) === true) {
				return $slug;
			}
		}

		return '';
	}//end slugOf()
}//end class
