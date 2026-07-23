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

use DOMDocument;
use OCP\Http\Client\IClientService;
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
class WooReadinessService
{

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
     * Constructor for WooReadinessService.
     *
     * @param IClientService   $clientService    The Nextcloud HTTP client factory used for outbound checks.
     * @param DirectoryService $directoryService The directory service, reused for its SSRF outbound-URL guard.
     * @param SitemapService   $sitemapService   The sitemap service, reused for DIWOO validation (D1).
     * @param SettingsService  $settingsService  The settings service, used to resolve WOO-enabled catalogs.
     * @param IAppConfig       $config           App configuration (report persistence + registration status).
     * @param IURLGenerator    $urlGenerator     The Nextcloud URL generator (public base URL).
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly DirectoryService $directoryService,
        private readonly SitemapService $sitemapService,
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
    public function hasWooEnabledCatalogs(): bool
    {
        return empty($this->getWooEnabledCatalogs()) === false;

    }//end hasWooEnabledCatalogs()

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
    public function runCheck(): array
    {
        $this->requestCount = 0;

        $catalogs = $this->getWooEnabledCatalogs();
        $baseUrl  = rtrim($this->urlGenerator->getBaseUrl(), '/');

        $checks   = [];
        $robotsOk = $this->checkRobotsTxt(baseUrl: $baseUrl, checks: $checks);

        foreach ($catalogs as $catalog) {
            $slug = $catalog->getSlug();
            if (is_string($slug) === false || $slug === '') {
                continue;
            }

            if ($robotsOk === false) {
                $this->skipCatalogChecks(slug: $slug, checks: $checks, reason: 'robots-txt-unreachable');
                continue;
            }

            $this->checkCatalog(baseUrl: $baseUrl, slug: $slug, checks: $checks);
        }

        $registration = $this->getRegistrationConfig();
        $checks[]     = $this->checkRegistration(registration: $registration, baseUrl: $baseUrl);

        $report = [
            'verdict'      => $this->computeVerdict($checks),
            'checkedAt'    => (new \DateTime())->format(DATE_ATOM),
            'baseUrl'      => $baseUrl,
            'checks'       => $checks,
            'registration' => $registration,
        ];

        $this->persistReport($report);

        return $report;

    }//end runCheck()

    /**
     * Return the persisted readiness report without performing any outbound request.
     *
     * @return array<string, mixed>|null The last persisted report, or null when no run has completed yet.
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    public function getPersistedReport(): ?array
    {
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
    public function getRegistrationConfig(): array
    {
        return [
            'status'        => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_status', 'not_registered'),
            'registeredUrl' => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_url', ''),
            'registeredAt'  => $this->config->getValueString(self::APP_NAME, 'woo_index_registration_at', ''),
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
    private function getWooEnabledCatalogs(): array
    {
        try {
            $settings = $this->settingsService->getSettings();
        } catch (\Throwable $e) {
            return [];
        }

        $registerId = ($settings['configuration']['catalog_register'] ?? '');
        $schemaId   = ($settings['configuration']['catalog_schema'] ?? '');
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        try {
            $objectService = $this->settingsService->getObjectService();
        } catch (\Throwable $e) {
            return [];
        }

        $searchQuery = [
            '@self'         => [
                'register' => $registerId,
                'schema'   => $schemaId,
            ],
            'hasWooSitemap' => true,
        ];

        try {
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false,
                deleted: false
            );
        } catch (\Throwable $e) {
            return [];
        }

        return ($result['results'] ?? []);

    }//end getWooEnabledCatalogs()

    /**
     * Check 1: robots.txt is publicly reachable and references at least one DIWOO sitemap.
     *
     * Fetched at the domain root (`{baseUrl}/robots.txt`) — the location a real crawler
     * requests, per the operator-level rewrite documented in SITEMAP.md — not the app's
     * internal `/apps/opencatalogi/api/robots.txt` route.
     *
     * @param string                           $baseUrl The instance's public base URL.
     * @param array<int, array<string, mixed>> $checks  The checks list (by reference).
     *
     * @return boolean True when robots.txt is reachable and references a sitemap.
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkRobotsTxt(string $baseUrl, array &$checks): bool
    {
        $response = $this->safeFetch("$baseUrl/robots.txt");

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
     * @param string                           $baseUrl The instance's public base URL.
     * @param string                           $slug    The catalog slug.
     * @param array<int, array<string, mixed>> $checks  The checks list (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkCatalog(string $baseUrl, string $slug, array &$checks): void
    {
        $categoryCode    = (string) array_key_first(SitemapService::INFO_CAT);
        $sitemapIndexUrl = "$baseUrl/apps/opencatalogi/api/$slug/sitemaps/$categoryCode";
        $checkId         = "sitemapindex:$slug";

        $response = $this->safeFetch($sitemapIndexUrl);
        if ($response['ok'] === false) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: $response['reason'], catalogSlug: $slug);
            $this->skipDependentChecks(slug: $slug, checks: $checks, reason: 'sitemapindex-unreachable');
            return;
        }

        if ($this->isWellFormedXml($response['body']) === false) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: 'invalid-xml', catalogSlug: $slug);
            $this->skipDependentChecks(slug: $slug, checks: $checks, reason: 'sitemapindex-invalid');
            return;
        }

        $checks[] = $this->buildCheck(id: $checkId, status: 'pass', catalogSlug: $slug);

        $pageUrls = array_slice($this->extractLocs($response['body']), 0, self::MAX_SAMPLED_PAGES);
        if (empty($pageUrls) === true) {
            $this->skipDependentChecks(slug: $slug, checks: $checks, reason: 'no-sitemap-pages');
            return;
        }

        $firstDocumentUrl = $this->checkCategorySitemapPages(slug: $slug, pageUrls: $pageUrls, checks: $checks);
        $this->checkDiwooXsd(slug: $slug, categoryCode: $categoryCode, checks: $checks);
        $this->checkPublicationSample(slug: $slug, publicationUrl: $firstDocumentUrl, checks: $checks);

    }//end checkCatalog()

    /**
     * Check 3: fetch up to MAX_SAMPLED_PAGES category-sitemap pages, verifying reachability,
     * well-formedness, and the presence of `diwoo:` metadata extension elements.
     *
     * @param string                           $slug     The catalog slug.
     * @param array<int, string>               $pageUrls The sampled page URLs (from the sitemapindex).
     * @param array<int, array<string, mixed>> $checks   The checks list (by reference).
     *
     * @return string|null The first publication document URL found, or null when none was found.
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkCategorySitemapPages(string $slug, array $pageUrls, array &$checks): ?string
    {
        $firstDocumentUrl = null;

        foreach ($pageUrls as $index => $pageUrl) {
            $pageCheckId = 'category-sitemap:'.$slug.':'.($index + 1);
            $response    = $this->safeFetch($pageUrl);

            if ($response['ok'] === false) {
                $checks[] = $this->buildCheck(id: $pageCheckId, status: 'fail', reason: $response['reason'], catalogSlug: $slug);
                continue;
            }

            if ($this->isWellFormedXml($response['body']) === false) {
                $checks[] = $this->buildCheck(id: $pageCheckId, status: 'fail', reason: 'invalid-xml', catalogSlug: $slug);
                continue;
            }

            if (str_contains($response['body'], '<diwoo:Document') === false) {
                $checks[] = $this->buildCheck(id: $pageCheckId, status: 'fail', reason: 'no-diwoo-elements', catalogSlug: $slug);
                continue;
            }

            $checks[] = $this->buildCheck(id: $pageCheckId, status: 'pass', catalogSlug: $slug);

            if ($firstDocumentUrl === null) {
                $locs = $this->extractLocs($response['body']);
                $firstDocumentUrl = ($locs[0] ?? null);
            }
        }//end foreach

        return $firstDocumentUrl;

    }//end checkCategorySitemapPages()

    /**
     * Check 4: DIWOO metadata validation, reusing the existing admin DIWOO validator (D1)
     * instead of re-implementing XSD validation.
     *
     * @param string                           $slug         The catalog slug.
     * @param string                           $categoryCode The DIWOO category code that was sampled.
     * @param array<int, array<string, mixed>> $checks       The checks list (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkDiwooXsd(string $slug, string $categoryCode, array &$checks): void
    {
        $checkId = "diwoo-xsd:$slug";

        try {
            $report = $this->sitemapService->validateDiwooOutput(
                catalogSlug: $slug,
                categoryCode: $categoryCode,
                page: 1
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: 'diwoo-validation-error', catalogSlug: $slug);
            return;
        }

        if (isset($report['error']) === true) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: 'diwoo-validation-error', catalogSlug: $slug);
            return;
        }

        if (($report['valid'] ?? false) === true) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'pass', catalogSlug: $slug);
            return;
        }

        $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: 'diwoo-xsd-invalid', catalogSlug: $slug);

    }//end checkDiwooXsd()

    /**
     * Check 5: fetch a single sampled publication URL and verify it resolves publicly with HTTP 200.
     *
     * @param string                           $slug           The catalog slug.
     * @param string|null                      $publicationUrl The sampled publication URL, or null when none was found.
     * @param array<int, array<string, mixed>> $checks         The checks list (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkPublicationSample(string $slug, ?string $publicationUrl, array &$checks): void
    {
        $checkId = "publication-sample:$slug";

        if ($publicationUrl === null) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'skipped', reason: 'no-publications-found', catalogSlug: $slug);
            return;
        }

        $response = $this->safeFetch($publicationUrl);
        if ($response['ok'] === false) {
            $checks[] = $this->buildCheck(id: $checkId, status: 'fail', reason: $response['reason'], catalogSlug: $slug);
            return;
        }

        $checks[] = $this->buildCheck(id: $checkId, status: 'pass', catalogSlug: $slug);

    }//end checkPublicationSample()

    /**
     * Append `skipped` entries for the sitemapindex + all its dependent checks (used when
     * robots.txt itself failed, so no per-catalog check even attempted a fetch).
     *
     * @param string                           $slug   The catalog slug.
     * @param array<int, array<string, mixed>> $checks The checks list (by reference).
     * @param string                           $reason The skip reason shared by every entry.
     *
     * @return void
     *
     * @spec exclude Skip-bookkeeping helper — prerequisite-failed checks must report `skipped`,
     *       never `pass`, per WOO-HR-001; no independent domain behavior of its own.
     */
    private function skipCatalogChecks(string $slug, array &$checks, string $reason): void
    {
        $checks[] = $this->buildCheck(id: "sitemapindex:$slug", status: 'skipped', reason: $reason, catalogSlug: $slug);
        $this->skipDependentChecks(slug: $slug, checks: $checks, reason: $reason);

    }//end skipCatalogChecks()

    /**
     * Append `skipped` entries for the category-sitemap, DIWOO-validation, and
     * publication-sample checks of one catalog (used when the sitemapindex check itself failed).
     *
     * @param string                           $slug   The catalog slug.
     * @param array<int, array<string, mixed>> $checks The checks list (by reference).
     * @param string                           $reason The skip reason shared by every entry.
     *
     * @return void
     *
     * @spec exclude Skip-bookkeeping helper — prerequisite-failed checks must report `skipped`,
     *       never `pass`, per WOO-HR-001; no independent domain behavior of its own.
     */
    private function skipDependentChecks(string $slug, array &$checks, string $reason): void
    {
        $checks[] = $this->buildCheck(id: "category-sitemap:$slug", status: 'skipped', reason: $reason, catalogSlug: $slug);
        $checks[] = $this->buildCheck(id: "diwoo-xsd:$slug", status: 'skipped', reason: $reason, catalogSlug: $slug);
        $checks[] = $this->buildCheck(id: "publication-sample:$slug", status: 'skipped', reason: $reason, catalogSlug: $slug);

    }//end skipDependentChecks()

    /**
     * Check the tracked Woo-index registration status against the checked base URL (WOO-HR-003).
     *
     * @param array{status: string, registeredUrl: string, registeredAt: string} $registration The registration config.
     * @param string                                                             $baseUrl      The checked base URL.
     *
     * @return array<string, mixed> The registration check entry.
     *
     * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
     */
    private function checkRegistration(array $registration, string $baseUrl): array
    {
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
    private function computeVerdict(array $checks): string
    {
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
    private function persistReport(array $report): void
    {
        $this->config->setValueString(self::APP_NAME, self::CONFIG_REPORT_KEY, json_encode($report));

    }//end persistReport()

    /**
     * Build a single check-result entry.
     *
     * @param string      $id          The check id.
     * @param string      $status      One of `pass`, `fail`, `skipped`.
     * @param string|null $reason      The machine-readable failure/skip reason, when applicable.
     * @param string|null $catalogSlug The catalog slug the check belongs to, when applicable.
     *
     * @return array<string, mixed> The check entry.
     *
     * @spec exclude Data-shape helper; no independent domain behavior.
     */
    private function buildCheck(string $id, string $status, ?string $reason=null, ?string $catalogSlug=null): array
    {
        $entry = [
            'id'     => $id,
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
    private function safeFetch(string $url): array
    {
        if ($this->requestCount >= self::MAX_REQUESTS) {
            return [
                'ok'     => false,
                'reason' => 'request-cap-reached',
            ];
        }

        try {
            $this->directoryService->validateOutboundUrl($url);
        } catch (\InvalidArgumentException $e) {
            return [
                'ok'     => false,
                'reason' => 'ssrf-blocked',
            ];
        }

        $this->requestCount++;

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $url,
                [
                    'timeout'         => self::TIMEOUT_SECONDS,
                    'http_errors'     => false,
                    'allow_redirects' => false,
                ]
            );
        } catch (\Throwable $e) {
            return [
                'ok'     => false,
                'reason' => 'network-error',
            ];
        }

        $status = $response->getStatusCode();
        $body   = $response->getBody();
        if (is_resource($body) === true) {
            $body = stream_get_contents($body);
        }

        $body = (string) $body;

        if ($status !== 200) {
            return [
                'ok'     => false,
                'reason' => 'http-'.$status,
                'status' => $status,
                'body'   => $body,
            ];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'body'   => $body,
        ];

    }//end safeFetch()

    /**
     * Whether a string is well-formed XML.
     *
     * @param string $xml The candidate XML string.
     *
     * @return boolean True when the string parses as well-formed XML.
     *
     * @spec exclude XML well-formedness helper; no independent domain behavior.
     */
    private function isWellFormedXml(string $xml): bool
    {
        if (trim($xml) === '') {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $result   = @$document->loadXML($xml);
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
    private function extractLocs(string $xml): array
    {
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
