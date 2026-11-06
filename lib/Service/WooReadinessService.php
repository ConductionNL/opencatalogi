<?php

/**
 * Service for the Woo-index harvester-readiness self-check.
 *
 * Performs an outside-in validation of everything the KOOP Woo-harvester needs
 * to actually ingest this instance: public reachability of robots.txt, the
 * DIWOO sitemapindex and per-category sitemaps, DIWOO metadata validity, and a
 * sampled publication URL. Every outbound request goes through the existing
 * SSRF-hardened outbound URL guard ({@see DirectoryService::validateOutboundUrl()})
 * and the DIWOO validation reuses the existing admin DIWOO validator
 * ({@see SitemapService::validateDiwooOutput()}) rather than duplicating it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use DOMDocument;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Runs and persists the Woo-index harvester-readiness self-check.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */
class WooReadinessService implements HarvesterReadinessServiceInterface {

	/**
	 * The app id used for IAppConfig reads/writes.
	 *
	 * @var string
	 */
	private const APP_NAME = 'opencatalogi';

	/**
	 * App-config key the persisted readiness report is stored under (D2).
	 *
	 * @var string
	 */
	private const CONFIG_REPORT_KEY = 'woo_readiness_report';

	/**
	 * Hard cap on outbound requests per self-check run (D5 / ADR-058 spirit).
	 *
	 * @var int
	 */
	private const MAX_REQUESTS = 25;

	/**
	 * Per-request outbound timeout in seconds (D5).
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Why the checks that depend on the sitemapindex are skipped, per sitemapindex failure other than a failed fetch.
	 *
	 * @var array<string, string>
	 */
	private const SITEMAPINDEX_SKIP_REASONS = ['invalid-xml' => 'sitemapindex-invalid'];

	/**
	 * The options of every outbound request: the timeout, no exception on an error status, no redirects.
	 *
	 * @var array<string, int|bool>
	 */
	private const FETCH_OPTIONS = [
		'timeout' => self::TIMEOUT_SECONDS,
		'http_errors' => false,
		'allow_redirects' => false,
	];

	/**
	 * Maximum number of category-sitemap pages sampled per catalog (D5).
	 *
	 * @var int
	 */
	private const MAX_SAMPLED_PAGES = 3;

	/**
	 * Running outbound-request counter for the current run, bounded by MAX_REQUESTS.
	 *
	 * @var integer
	 */
	private int $requestCount = 0;

	/**
	 * The DiWoo version comparison for the current run, resolved once per run.
	 *
	 * @var array{declared: string, published: string|null, status: string}|null
	 */
	private ?array $diwooVersion = null;

	/**
	 * Constructor for WooReadinessService.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory used for outbound checks.
	 * @param DirectoryService $directoryService The directory service, reused for its SSRF outbound-URL guard.
	 * @param SitemapStandards $sitemaps The sitemap files, their DIWOO validation (D1) and the published DiWoo version.
	 * @param SettingsService $settingsService The settings service, used to resolve WOO-enabled catalogs.
	 * @param IAppConfig $config App configuration (report persistence + registration status).
	 * @param IURLGenerator $urlGenerator The Nextcloud URL generator (public base URL).
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly DirectoryService $directoryService,
		private readonly SitemapStandards $sitemaps,
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $config,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Whether at least one WOO-enabled catalog is configured.
	 *
	 * Zero outbound requests. Used by the controller to fail closed (WOO-HR-004)
	 * BEFORE any check runs, guaranteeing an unconfigured instance never performs
	 * an outbound request.
	 *
	 * @return boolean True when at least one catalog has `hasWooSitemap: true`.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function hasWooEnabledCatalogs(): bool {
		return empty($this->getWooEnabledCatalogs()) === false;
	}//end hasWooEnabledCatalogs()

	/**
	 * Run the check only while at least one catalogue is Woo-enabled.
	 *
	 * With none, no outbound request is made and the stored report stays as
	 * it is (WOO-HR-004). Used by the daily job and by the one queued when a
	 * catalogue is switched on.
	 *
	 * @return array<string, mixed>|null The report, or null when nothing is Woo-enabled.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
	 */
	public function runWhenEnabled(): ?array {
		if ($this->hasWooEnabledCatalogs() === false) {
			return null;
		}

		return $this->runCheck();
	}//end runWhenEnabled()

	/**
	 * Run the full outside-in harvester-readiness self-check and persist the report.
	 *
	 * Caller MUST have already verified {@see hasWooEnabledCatalogs()} (WOO-HR-004) —
	 * this method does not itself fail closed on an empty catalog set (it would
	 * simply produce an empty, vacuously "ready" report), so the fail-closed
	 * contract lives in the controller.
	 *
	 * @return array<string, mixed> The report (verdict, checks, registration, baseUrl, checkedAt).
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function runCheck(): array {
		$this->requestCount = 0;
		$this->diwooVersion = null;

		$catalogs = $this->getWooEnabledCatalogs();
		$baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');
		$checks = $this->instanceChecks(catalogs: $catalogs, baseUrl: $baseUrl);

		$registration = $this->getRegistrationConfig();
		$checks[] = $this->checkRegistration(registration: $registration, baseUrl: $baseUrl);

		$report = [
			'verdict' => $this->computeVerdict(checks: $checks),
			'checkedAt' => (new DateTime())->format(DATE_ATOM),
			'baseUrl' => $baseUrl,
			'checks' => $checks,
			'registration' => $registration,
		];

		$this->persistReport(report: $report);

		return $report;
	}//end runCheck()

	/**
	 * The standard-version check, the robots.txt check and the checks of every catalog with a slug.
	 *
	 * When robots.txt fails, no catalog check fetches anything: they all report skipped.
	 *
	 * @param array<int, mixed> $catalogs The WOO-enabled catalogs.
	 * @param string $baseUrl The instance's public base URL.
	 *
	 * @return array<int, array<string, mixed>> The checks, in order.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function instanceChecks(array $catalogs, string $baseUrl): array {
		$checks = [$this->checkDiwooStandardVersion()];
		$reachableBaseUrl = null;
		if ($this->checkRobotsTxt(baseUrl: $baseUrl, checks: $checks) === true) {
			$reachableBaseUrl = $baseUrl;
		}

		foreach ($catalogs as $catalog) {
			array_push($checks, ...$this->catalogCheckList(catalog: $catalog, reachableBaseUrl: $reachableBaseUrl));
		}

		return $checks;
	}//end instanceChecks()

	/**
	 * The checks of one catalog: none without a slug, all skipped when robots.txt failed.
	 *
	 * @param mixed $catalog The catalog object.
	 * @param string|null $reachableBaseUrl The base URL when robots.txt passed, null when it failed.
	 *
	 * @return array<int, array<string, string>> The catalog's checks.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function catalogCheckList(mixed $catalog, ?string $reachableBaseUrl): array {
		$slug = $catalog->getSlug();
		if (is_string($slug) === false || $slug === '') {
			return [];
		}

		$catalogChecks = new CatalogReadinessChecks(slug: $slug);
		if ($reachableBaseUrl === null) {
			$catalogChecks->skipAll(reason: 'robots-txt-unreachable');
			return $catalogChecks->all();
		}

		$this->checkCatalog(baseUrl: $reachableBaseUrl, catalog: $catalogChecks);
		return $catalogChecks->all();
	}//end catalogCheckList()

	/**
	 * Return the persisted readiness report without performing any outbound request.
	 *
	 * @return array<string, mixed>|null The last persisted report, or null when no run has completed yet.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function getPersistedReport(): ?array {
		$raw = $this->config->getValueString(self::APP_NAME, self::CONFIG_REPORT_KEY, '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end getPersistedReport()

	/**
	 * Read the tracked Woo-index registration status configuration (WOO-HR-003).
	 *
	 * @return array{status: string, registeredUrl: string, registeredAt: string} The registration config.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function getRegistrationConfig(): array {
		return [
			'status' => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_status', 'not_registered'),
			'registeredUrl' => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_url', ''),
			'registeredAt' => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_at', ''),
		];

	}//end getRegistrationConfig()

	/**
	 * Resolve the WOO-enabled catalogs (`hasWooSitemap: true`) for the configured catalog schema.
	 *
	 * Zero outbound requests — this is a local OpenRegister query, not an HTTP fetch.
	 *
	 * @return array<int, mixed> The catalog objects (OpenRegister entities), possibly empty.
	 *
	 * @spec exclude Local catalog-lookup plumbing shared by hasWooEnabledCatalogs() and runCheck(); no outbound behavior.
	 */
	public function getWooEnabledCatalogs(): array {
		$searchQuery = $this->wooCatalogQuery();
		if ($searchQuery === null) {
			return [];
		}

		try {
			$objectService = $this->settingsService->getObjectService();
			$result = $objectService->searchObjectsPaginated(query: $searchQuery, _rbac: true, _multitenancy: false, deleted: false);
		} catch (\Throwable $e) {
			return [];
		}

		return ($result['results'] ?? []);
	}//end getWooEnabledCatalogs()

	/**
	 * The search for the configured catalog schema's catalogs with `hasWooSitemap: true`.
	 *
	 * @return array<string, mixed>|null The query, or null when the settings are unreadable or name no catalog register and schema.
	 *
	 * @spec exclude Local catalog-lookup plumbing for getWooEnabledCatalogs(); no outbound behavior.
	 */
	private function wooCatalogQuery(): ?array {
		try {
			$settings = $this->settingsService->getSettings();
		} catch (\Throwable $e) {
			return null;
		}

		$registerId = ($settings['configuration']['catalog_register'] ?? '');
		$schemaId = ($settings['configuration']['catalog_schema'] ?? '');
		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		return ['@self' => ['register' => $registerId, 'schema' => $schemaId], 'hasWooSitemap' => true];
	}//end wooCatalogQuery()

	/**
	 * Check 1: robots.txt is publicly reachable and references at least one DIWOO sitemap.
	 *
	 * Fetched at the domain root (`{baseUrl}/robots.txt`) — the location a real crawler
	 * requests, per the operator-level rewrite documented in SITEMAP.md — not the app's
	 * internal `/apps/opencatalogi/api/robots.txt` route.
	 *
	 * @param string $baseUrl The instance's public base URL.
	 * @param array<int, array<string, mixed>> $checks The checks list (by reference).
	 *
	 * @return boolean True when robots.txt is reachable and references a sitemap.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkRobotsTxt(string $baseUrl, array &$checks): bool {
		$response = $this->safeFetch(url: "$baseUrl/robots.txt");

		if ($response['ok'] === false) {
			$checks[] = $this->buildCheck(id: 'robots-txt', status: 'fail', reason: $response['reason']);
			return false;
		}

		if (str_contains($response['body'], '/sitemaps/') === false) {
			$checks[] = $this->buildCheck(id: 'robots-txt', status: 'fail', reason: 'missing-sitemap-reference');
			return false;
		}

		$checks[] = $this->buildCheck(id: 'robots-txt', status: 'pass');
		return true;
	}//end checkRobotsTxt()

	/**
	 * Run the sitemapindex + category-sitemap + DIWOO-validation + publication-sample
	 * checks for a single WOO-enabled catalog (checks 2-5, D5 bounded sampling).
	 *
	 * @param string $baseUrl The instance's public base URL.
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkCatalog(string $baseUrl, CatalogReadinessChecks $catalog): void {
		$categoryCode = (string)array_key_first($this->sitemaps->sitemapFiles());
		$pageUrls = $this->sitemapPageUrls(catalog: $catalog, url: "$baseUrl/apps/opencatalogi/api/{$catalog->slug}/sitemaps/$categoryCode");
		if ($pageUrls === null) {
			return;
		}

		if (empty($pageUrls) === true) {
			$catalog->skipDependents(reason: 'no-sitemap-pages');
			return;
		}

		$firstDocumentUrl = $this->checkCategorySitemapPages(catalog: $catalog, pageUrls: $pageUrls);
		$this->checkDiwooXsd(catalog: $catalog, categoryCode: $categoryCode);
		$this->checkPublicationSample(catalog: $catalog, publicationUrl: $firstDocumentUrl);

	}//end checkCatalog()

	/**
	 * Check 2: the sitemapindex is reachable and well-formed; answer the page URLs to sample.
	 *
	 * When it is not, the check fails and the checks that depend on it are skipped.
	 *
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 * @param string $url The sitemapindex URL.
	 *
	 * @return array<int, string>|null Up to MAX_SAMPLED_PAGES page URLs, or null when the sitemapindex failed.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function sitemapPageUrls(CatalogReadinessChecks $catalog, string $url): ?array {
		$checkId = 'sitemapindex:' . $catalog->slug;
		$response = $this->safeFetch(url: $url);
		$failure = $this->xmlFailure(response: $response);
		if ($failure !== null) {
			$catalog->fail(id: $checkId, reason: $failure);
			$catalog->skipDependents(reason: self::SITEMAPINDEX_SKIP_REASONS[$failure] ?? 'sitemapindex-unreachable');
			return null;
		}

		$catalog->pass(id: $checkId);
		return array_slice($this->extractLocs(xml: $response['body']), 0, self::MAX_SAMPLED_PAGES);
	}//end sitemapPageUrls()

	/**
	 * Why a fetched XML document fails: the fetch failure, or `invalid-xml`.
	 *
	 * @param array{ok: bool, reason?: string, status?: int, body?: string} $response The fetch result.
	 *
	 * @return string|null The failure reason, or null when the body is well-formed XML.
	 *
	 * @spec exclude XML fetch-result helper; no independent domain behavior.
	 */
	private function xmlFailure(array $response): ?string {
		if ($response['ok'] === false) {
			return $response['reason'];
		}

		if ($this->isWellFormedXml(xml: $response['body']) === false) {
			return 'invalid-xml';
		}

		return null;
	}//end xmlFailure()

	/**
	 * Check 3: fetch up to MAX_SAMPLED_PAGES category-sitemap pages, verifying reachability,
	 * well-formedness, and the presence of `diwoo:` metadata extension elements.
	 *
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 * @param array<int, string> $pageUrls The sampled page URLs (from the sitemapindex).
	 *
	 * @return string|null The first publication document URL found, or null when none was found.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkCategorySitemapPages(CatalogReadinessChecks $catalog, array $pageUrls): ?string {
		$firstDocumentUrl = null;

		foreach ($pageUrls as $index => $pageUrl) {
			$pageCheckId = 'category-sitemap:' . $catalog->slug . ':' . ($index + 1);
			$response = $this->safeFetch(url: $pageUrl);
			$failure = $this->pageFailure(response: $response);
			if ($failure !== null) {
				$catalog->fail(id: $pageCheckId, reason: $failure);
				continue;
			}

			$catalog->pass(id: $pageCheckId);
			$firstDocumentUrl ??= ($this->extractLocs(xml: $response['body'])[0] ?? null);
		}

		return $firstDocumentUrl;
	}//end checkCategorySitemapPages()

	/**
	 * Why a fetched category-sitemap page fails: the XML failure, or `no-diwoo-elements`.
	 *
	 * @param array{ok: bool, reason?: string, status?: int, body?: string} $response The fetch result.
	 *
	 * @return string|null The failure reason, or null when the page holds `diwoo:Document` elements.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function pageFailure(array $response): ?string {
		$failure = $this->xmlFailure(response: $response);
		if ($failure === null && str_contains($response['body'], '<diwoo:Document') === false) {
			return 'no-diwoo-elements';
		}

		return $failure;
	}//end pageFailure()

	/**
	 * Check 4: DIWOO metadata validation, reusing the existing admin DIWOO validator (D1)
	 * instead of re-implementing XSD validation.
	 *
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 * @param string $categoryCode The DIWOO category code that was sampled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkDiwooXsd(CatalogReadinessChecks $catalog, string $categoryCode): void {
		$checkId = 'diwoo-xsd:' . $catalog->slug;

		// A pass here means "our output conforms to the DiWoo version we emit". That is
		// only worth reporting when the version we emit is the version the publisher
		// publishes. When it is not, or when we could not find out, this check must not
		// report a pass, because the rules it validated against are the wrong ones.
		if ($this->recordedVersionMismatch(catalog: $catalog, checkId: $checkId) === true) {
			return;
		}

		try {
			$report = $this->sitemaps->validateFirstPage(catalogSlug: $catalog->slug, categoryCode: $categoryCode);
		} catch (\Throwable $e) {
			$catalog->fail(id: $checkId, reason: 'diwoo-validation-error');
			return;
		}

		if (isset($report['error']) === true) {
			$catalog->fail(id: $checkId, reason: 'diwoo-validation-error');
			return;
		}

		if ($report['valid'] === true) {
			$catalog->pass(id: $checkId);
			return;
		}

		$catalog->fail(id: $checkId, reason: 'diwoo-xsd-invalid');

	}//end checkDiwooXsd()

	/**
	 * Record the DiWoo check as failed when we emit a superseded version, or as
	 * skipped when the published version could not be read.
	 *
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 * @param string $checkId The DiWoo check id.
	 *
	 * @return bool True when a mismatch was recorded and the check must not run.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function recordedVersionMismatch(CatalogReadinessChecks $catalog, string $checkId): bool {
		$version = $this->resolveDiwooVersion();
		if ($version['status'] === StandardsVersionService::STATUS_BEHIND) {
			$catalog->fail(id: $checkId, reason: 'diwoo-version-behind:' . $version['published']);
			return true;
		}

		if ($version['status'] === StandardsVersionService::STATUS_UNKNOWN) {
			$catalog->skip(id: $checkId, reason: 'diwoo-version-unknown');
			return true;
		}

		return false;
	}//end recordedVersionMismatch()

	/**
	 * Check 0: the DiWoo version this instance emits is the version the publisher publishes.
	 *
	 * This is the check that makes the DIWOO conformance report able to fail. The
	 * expected version comes from the standard's own version index, not from our
	 * renderer, so a superseded release is detected instead of validated against.
	 * It is fail-closed in both directions: unreachable reports `skipped`, never
	 * `pass`, and behind reports `fail` with the published version in the reason.
	 *
	 * @return array<string, mixed> The check entry.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function checkDiwooStandardVersion(): array {
		$version = $this->resolveDiwooVersion();

		if ($version['status'] === StandardsVersionService::STATUS_BEHIND) {
			$reason = 'declared-' . $version['declared'] . '-published-' . $version['published'];
			return $this->buildCheck(id: 'diwoo-standard-version', status: 'fail', reason: $reason);
		}

		if ($version['status'] === StandardsVersionService::STATUS_UNKNOWN) {
			return $this->buildCheck(id: 'diwoo-standard-version', status: 'skipped', reason: 'standard-version-unreadable');
		}

		return $this->buildCheck(id: 'diwoo-standard-version', status: 'pass');
	}//end checkDiwooStandardVersion()

	/**
	 * Resolve the DiWoo version comparison once per run.
	 *
	 * @return array{declared: string, published: string|null, status: string} The comparison.
	 *
	 * @spec exclude Per-run memoisation around StandardsVersionService::diwooVersion().
	 */
	private function resolveDiwooVersion(): array {
		if ($this->diwooVersion === null) {
			$this->diwooVersion = $this->sitemaps->versionComparison();
		}

		return $this->diwooVersion;
	}//end resolveDiwooVersion()

	/**
	 * Check 5: fetch a single sampled publication URL and verify it resolves publicly with HTTP 200.
	 *
	 * @param CatalogReadinessChecks $catalog The catalog and the checks recorded for it.
	 * @param string|null $publicationUrl The sampled publication URL, or null when none was found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkPublicationSample(CatalogReadinessChecks $catalog, ?string $publicationUrl): void {
		$checkId = 'publication-sample:' . $catalog->slug;

		if ($publicationUrl === null) {
			$catalog->skip(id: $checkId, reason: 'no-publications-found');
			return;
		}

		$response = $this->safeFetch(url: $publicationUrl);
		if ($response['ok'] === false) {
			$catalog->fail(id: $checkId, reason: $response['reason']);
			return;
		}

		$catalog->pass(id: $checkId);

	}//end checkPublicationSample()

	/**
	 * Check the tracked Woo-index registration status against the checked base URL (WOO-HR-003).
	 *
	 * @param array{status: string, registeredUrl: string, registeredAt: string} $registration The registration config.
	 * @param string $baseUrl The checked base URL.
	 *
	 * @return array<string, mixed> The registration check entry.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function checkRegistration(array $registration, string $baseUrl): array {
		$status = $registration['status'];

		if ($status !== 'registered') {
			$reason = 'not-registered';
			if ($status === 'requested') {
				$reason = 'registration-pending';
			}

			return $this->buildCheck(id: 'registration', status: 'skipped', reason: $reason);
		}

		if (rtrim($registration['registeredUrl'], '/') !== rtrim($baseUrl, '/')) {
			return $this->buildCheck(id: 'registration', status: 'fail', reason: 'url-mismatch');
		}

		return $this->buildCheck(id: 'registration', status: 'pass');
	}//end checkRegistration()

	/**
	 * Compute the overall verdict from the individual check results.
	 *
	 * @param array<int, array<string, mixed>> $checks The checks list.
	 *
	 * @return string `ready` when no check reports `fail`, `not-ready` otherwise.
	 *
	 * @spec exclude Pure aggregation over already-computed check results; no independent domain behavior.
	 */
	private function computeVerdict(array $checks): string {
		foreach ($checks as $check) {
			if ($check['status'] === 'fail') {
				return 'not-ready';
			}
		}

		return 'ready';
	}//end computeVerdict()

	/**
	 * Persist the readiness report as a single JSON appconfig value (D2 — atomic replace).
	 *
	 * @param array<string, mixed> $report The report to persist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function persistReport(array $report): void {
		$this->config->setValueString(self::APP_NAME, self::CONFIG_REPORT_KEY, json_encode($report));

	}//end persistReport()

	/**
	 * Build a single check-result entry.
	 *
	 * @param string $id The check id.
	 * @param string $status One of `pass`, `fail`, `skipped`.
	 * @param string|null $reason The machine-readable failure/skip reason, when applicable.
	 * @param string|null $catalogSlug The catalog slug the check belongs to, when applicable.
	 *
	 * @return array<string, mixed> The check entry.
	 *
	 * @spec exclude Data-shape helper; no independent domain behavior.
	 */
	private function buildCheck(string $id, string $status, ?string $reason = null, ?string $catalogSlug = null): array {
		$entry = [
			'id' => $id,
			'status' => $status,
		];

		if ($reason !== null) {
			$entry['reason'] = $reason;
		}

		if ($catalogSlug !== null) {
			$entry['catalogSlug'] = $catalogSlug;
		}

		return $entry;
	}//end buildCheck()

	/**
	 * Fetch a URL through the SSRF guard, the outbound-request cap, and a 10s timeout.
	 *
	 * Every outbound HTTP request the readiness self-check performs goes through this
	 * single choke point, so the SSRF guard and the MAX_REQUESTS cap are enforced
	 * uniformly (WOO-HR-001, D5).
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return array{ok: bool, reason?: string, status?: int, body?: string} The fetch result.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function safeFetch(string $url): array {
		$refusal = $this->fetchRefusal(url: $url);
		if ($refusal !== null) {
			return ['ok' => false, 'reason' => $refusal];
		}

		$this->requestCount++;

		try {
			$response = $this->clientService->newClient()->get($url, self::FETCH_OPTIONS);
		} catch (\Throwable $e) {
			return ['ok' => false, 'reason' => 'network-error'];
		}

		return $this->fetchResult(response: $response);
	}//end safeFetch()

	/**
	 * Why a URL may not be fetched: the outbound-request cap is reached, or the SSRF guard rejects it.
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return string|null The refusal reason, or null when the fetch may go ahead.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	private function fetchRefusal(string $url): ?string {
		if ($this->requestCount >= self::MAX_REQUESTS) {
			return 'request-cap-reached';
		}

		try {
			$this->directoryService->validateOutboundUrl($url);
		} catch (\InvalidArgumentException $e) {
			return 'ssrf-blocked';
		}

		return null;
	}//end fetchRefusal()

	/**
	 * The fetch result of a response: ok with its body on HTTP 200, otherwise the status as reason.
	 *
	 * @param IResponse $response The HTTP response.
	 *
	 * @return array{ok: bool, reason?: string, status: int, body: string} The fetch result.
	 *
	 * @spec exclude Response-shape helper for safeFetch(); no independent domain behavior.
	 */
	private function fetchResult(IResponse $response): array {
		$status = $response->getStatusCode();
		$body = $response->getBody();
		if (is_resource($body) === true) {
			$body = stream_get_contents($body);
		}

		if ($status !== 200) {
			return ['ok' => false, 'reason' => 'http-' . $status, 'status' => $status, 'body' => (string)$body];
		}

		return ['ok' => true, 'status' => $status, 'body' => (string)$body];
	}//end fetchResult()

	/**
	 * Whether a string is well-formed XML.
	 *
	 * @param string $xml The candidate XML string.
	 *
	 * @return boolean True when the string parses as well-formed XML.
	 *
	 * @spec exclude XML well-formedness helper; no independent domain behavior.
	 */
	private function isWellFormedXml(string $xml): bool {
		if (trim($xml) === '') {
			return false;
		}

		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$result = @$document->loadXML($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return ($result === true);
	}//end isWellFormedXml()

	/**
	 * Extract every `<loc>` element's text content from a sitemap-family XML document.
	 *
	 * Used both for a sitemapindex's `<sitemap><loc>` page URLs and a sitemap page's
	 * `<diwoo:Document><loc>` publication URLs — both are unprefixed `loc` elements
	 * under the default `sitemap/0.9` namespace, so a single namespace-agnostic
	 * `getElementsByTagName('loc')` lookup covers both shapes.
	 *
	 * @param string $xml A well-formed XML string (caller MUST have already validated this).
	 *
	 * @return array<int, string> The non-empty `<loc>` values, in document order.
	 *
	 * @spec exclude XML-extraction helper; no independent domain behavior.
	 */
	private function extractLocs(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		@$document->loadXML($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$locs = [];
		foreach ($document->getElementsByTagName('loc') as $node) {
			$value = trim($node->textContent);
			if ($value !== '') {
				$locs[] = $value;
			}
		}

		return $locs;
	}//end extractLocs()
}//end class
