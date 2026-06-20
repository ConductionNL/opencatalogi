<?php
/**
 * OpenCatalogi Usage Statistics Controller.
 *
 * Authenticated, officer-facing usage analytics: per-publication timeseries +
 * totals, catalog roll-ups + top-N, and a per-catalog CSV export for WOO annual
 * reporting. All figures are aggregated from the privacy-safe `usageCounter`
 * objects via OpenRegister object search (no bespoke SQL, hydra ADR-022). These
 * surfaces are NOT public in this change.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing authenticated publication usage statistics.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StatsController extends Controller
{
    /**
     * StatsController constructor.
     *
     * @param string              $appName             The app name.
     * @param IRequest            $request             The HTTP request.
     * @param UsageCounterService $usageCounterService Aggregation over counter objects.
     * @param CatalogiService     $catalogiService     Catalog resolution by slug.
     * @param IAppManager         $appManager          Detects OpenRegister availability.
     * @param ContainerInterface  $container           DI container for the OR ObjectService.
     * @param IL10N               $l10n                Localization service.
     * @param IUserSession        $userSession         Current-user session for the auth guard.
     * @param LoggerInterface     $logger              PSR-3 logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly UsageCounterService $usageCounterService,
        private readonly CatalogiService $catalogiService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Return usage statistics for a single publication.
     *
     * Authenticated officer surface (NOT public). Authorization mirrors the
     * publication's own OpenRegister RBAC: the publication is resolved with
     * `_rbac: true`, so a caller who is not permitted on the underlying
     * publication receives a 403 and never the stats — preventing IDOR
     * (ANA-004, gate no-admin-idor).
     *
     * @param string $id The publication UUID.
     *
     * @return JSONResponse Timeseries + totals + counting-start date.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function publication(string $id): JSONResponse
    {
        [$from, $to, $granularity] = $this->readRange();

        // Per-object authorization (no IDOR): require an authenticated user AND
        // confirm the caller may read this publication under its own OR RBAC
        // rule before returning any stats.
        if ($this->requireAuthenticatedUser() === null
            || $this->canReadPublication($id) === false
        ) {
            return new JSONResponse(
                ['error' => $this->l10n->t('You are not allowed to view statistics for this publication.')],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $stats = $this->usageCounterService->getPublicationStats(publicationId: $id, from: $from, to: $to);
            $stats['publication'] = $id;
            $stats['granularity'] = $granularity;
            return new JSONResponse($stats, 200);
        } catch (\Throwable $e) {
            $this->logger->error('[StatsController::publication] failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Could not compute publication statistics.')], 500);
        }

    }//end publication()

    /**
     * Return usage roll-ups and top-N for a catalog.
     *
     * @param string $slug The catalog slug.
     *
     * @return JSONResponse Catalog totals + topViewed/topDownloaded lists.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function catalog(string $slug): JSONResponse
    {
        // Auth guard: catalog usage roll-ups are officer-facing, never anonymous.
        if ($this->requireAuthenticatedUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required.')],
                Http::STATUS_FORBIDDEN
            );
        }

        $catalog = $this->catalogiService->getCatalogBySlug($slug);
        if ($catalog === null) {
            return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
        }

        [$from, $to] = $this->readRange();
        $top         = (int) ($this->request->getParam('top', 10));
        if ($top < 1) {
            $top = 10;
        }

        try {
            $stats            = $this->usageCounterService->getCatalogStats(catalog: $slug, from: $from, to: $to, top: $top);
            $stats['catalog'] = $slug;
            // Period without data still returns zeros + a counting-start marker (ANA-005).
            if (isset($stats['countingStart']) === false) {
                $series = $this->usageCounterService->getCountersForCatalog(catalog: $slug, from: null, to: null);
                $stats['countingStart'] = $this->usageCounterService->aggregateSeries($series)['countingStart'];
            }

            return new JSONResponse($stats, 200);
        } catch (\Throwable $e) {
            $this->logger->error('[StatsController::catalog] failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Could not compute catalog statistics.')], 500);
        }

    }//end catalog()

    /**
     * Export per-catalog usage as a UTF-8 (BOM) CSV for WOO annual reporting.
     *
     * One row per publication with counted usage in the period: Publication,
     * Category, Published date, Views, Downloads. Derived entirely from counter
     * objects (no separate reporting store). Zero-usage publications that are
     * present in the counter set are included with zeros (ANA-007).
     *
     * @param string $slug The catalog slug.
     *
     * @return DataDownloadResponse|JSONResponse The CSV download, or an error.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function export(string $slug): DataDownloadResponse|JSONResponse
    {
        // Auth guard: the CSV export is officer-facing, never anonymous.
        if ($this->requireAuthenticatedUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required.')],
                Http::STATUS_FORBIDDEN
            );
        }

        $catalog = $this->catalogiService->getCatalogBySlug($slug);
        if ($catalog === null) {
            return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
        }

        [$from, $to] = $this->readRange();

        try {
            $rows = $this->usageCounterService->getCountersForCatalog(catalog: $slug, from: $from, to: $to);
            $csv  = $this->buildCsv($rows);
            $name = 'usage-'.preg_replace('/[^a-z0-9-]/i', '-', $slug).'.csv';
            return new DataDownloadResponse($csv, $name, 'text/csv; charset=utf-8');
        } catch (\Throwable $e) {
            $this->logger->error('[StatsController::export] failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Could not export usage statistics.')], 500);
        }

    }//end export()

    /**
     * Build the UTF-8 (BOM) CSV body from counter rows.
     *
     * Exposed (public) so the column/BOM contract is independently testable.
     *
     * @param array<int, array<string, mixed>> $rows Counter rows.
     *
     * @return string The CSV document including a UTF-8 BOM.
     *
     * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
     */
    public function buildCsv(array $rows): string
    {
        // Roll counter rows up to one entry per publication.
        $byPub = [];
        foreach ($rows as $row) {
            $pub = (string) ($row['publication'] ?? '');
            if ($pub === '') {
                continue;
            }

            if (isset($byPub[$pub]) === false) {
                $byPub[$pub] = [
                    'publication' => $pub,
                    'category'    => (string) ($row['category'] ?? ''),
                    'published'   => (string) ($row['published'] ?? ''),
                    'views'       => 0,
                    'downloads'   => 0,
                ];
            }

            $count = max(0, (int) ($row['count'] ?? 0));
            if (($row['kind'] ?? '') === UsageCounterService::KIND_VIEW) {
                $byPub[$pub]['views'] += $count;
            } else if (($row['kind'] ?? '') === UsageCounterService::KIND_DOWNLOAD) {
                $byPub[$pub]['downloads'] += $count;
            }
        }//end foreach

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Publication', 'Category', 'Published date', 'Views', 'Downloads']);
        foreach ($byPub as $entry) {
            fputcsv(
                $handle,
                [
                    $entry['publication'],
                    $entry['category'],
                    $entry['published'],
                    $entry['views'],
                    $entry['downloads'],
                ]
            );
        }

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        // UTF-8 BOM so spreadsheet apps detect the encoding (ANA-007).
        return "\xEF\xBB\xBF".$body;

    }//end buildCsv()

    /**
     * Read and normalise the from/to/granularity query parameters.
     *
     * @return array{0:?string,1:?string,2:string} [from, to, granularity].
     *
     * @spec exclude Request-parameter parsing helper for the stats endpoints; the
     *       range/granularity contract is asserted through publication()/catalog().
     */
    private function readRange(): array
    {
        $from        = $this->request->getParam('from');
        $to          = $this->request->getParam('to');
        $granularity = (string) ($this->request->getParam('granularity', 'day'));
        if (in_array($granularity, ['day', 'week', 'month'], true) === false) {
            $granularity = 'day';
        }

        if (is_string($from) === false || $from === '') {
            $from = null;
        }

        if (is_string($to) === false || $to === '') {
            $to = null;
        }

        return [$from, $to, $granularity];

    }//end readRange()

    /**
     * Require an authenticated user; return the user id, or null when absent.
     *
     * Defence-in-depth alongside the route's `@NoAdminRequired` posture and the
     * OR RBAC on the counter schema — the stats surfaces are officer-facing and
     * never anonymous (ANA-004/ANA-005).
     *
     * @return string|null The authenticated user id, or null.
     *
     * @spec openspec/specs/publication-usage-analytics/spec.md
     */
    private function requireAuthenticatedUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end requireAuthenticatedUser()

    /**
     * Check whether the current caller may read a publication under OR RBAC.
     *
     * Resolves the publication with `_rbac: true`; an empty result means the
     * caller is not permitted on it (or it does not exist), which is treated as
     * "not allowed" — the same authorization rule that governs the publication
     * itself (ANA-004, no-IDOR).
     *
     * @param string $id The publication UUID.
     *
     * @return boolean True when the caller may read the publication.
     *
     * @spec openspec/specs/publication-usage-analytics/spec.md
     */
    private function canReadPublication(string $id): bool
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
            return false;
        }

        try {
            /*
             * OpenRegister object service.
             *
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->searchObjects(
                query: ['@self' => ['uuid' => $id]],
                _rbac: true,
                _multitenancy: false,
            );

            return (is_array($results) === true && empty($results) === false);
        } catch (\Throwable $e) {
            // Fail closed: an authorization-resolution failure denies access
            // (never silently fall open — gate unsafe-auth-resolver).
            $this->logger->warning('[StatsController] publication authorization check failed', ['error' => $e->getMessage()]);
            return false;
        }

    }//end canReadPublication()
}//end class
