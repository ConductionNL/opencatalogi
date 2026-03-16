<?php

/**
 * OpenCatalogi Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for exposing Prometheus metrics.
 *
 * @psalm-suppress UnusedClass
 */
class MetricsController extends Controller
{

    /**
     * Constructor.
     *
     * @param string          $appName    The application name
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()


    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoCSRFRequired
     *
     * @return TextPlainResponse Prometheus-formatted metrics
     */
    public function index(): TextPlainResponse
    {
        $metrics  = $this->collectMetrics();
        $response = new TextPlainResponse($metrics);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()


    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string Prometheus exposition format text
     */
    private function collectMetrics(): string
    {
        $lines = [];

        // App info gauge.
        $version    = $this->getAppVersion();
        $phpVersion = PHP_VERSION;

        $lines[] = '# HELP opencatalogi_info Application information';
        $lines[] = '# TYPE opencatalogi_info gauge';
        $lines[] = 'opencatalogi_info{version="' . $version . '",php_version="' . $phpVersion . '"} 1';
        $lines[] = '';

        // App up gauge.
        $lines[] = '# HELP opencatalogi_up Whether the application is healthy';
        $lines[] = '# TYPE opencatalogi_up gauge';
        $lines[] = 'opencatalogi_up 1';
        $lines[] = '';

        // Publications total by status and catalog.
        $lines[] = '# HELP opencatalogi_publications_total Total publications by status and catalog';
        $lines[] = '# TYPE opencatalogi_publications_total gauge';
        $pubCounts = $this->getPublicationCounts();
        foreach ($pubCounts as $row) {
            $status  = $this->sanitizeLabel($row['status'] ?? 'unknown');
            $catalog = $this->sanitizeLabel($row['catalog'] ?? 'unknown');
            $count   = (int) $row['cnt'];
            $lines[] = 'opencatalogi_publications_total{status="' . $status . '",catalog="' . $catalog . '"} ' . $count;
        }

        $lines[] = '';

        // Catalogs total.
        $catalogsTotal = $this->countObjectsBySchemaPattern('%atalog%');
        $lines[]       = '# HELP opencatalogi_catalogs_total Total catalogs';
        $lines[]       = '# TYPE opencatalogi_catalogs_total gauge';
        $lines[]       = 'opencatalogi_catalogs_total ' . $catalogsTotal;
        $lines[]       = '';

        // Listings total.
        $lines[] = '# HELP opencatalogi_listings_total Total listings by status';
        $lines[] = '# TYPE opencatalogi_listings_total gauge';
        $listingCounts = $this->getListingCounts();
        foreach ($listingCounts as $row) {
            $status  = $this->sanitizeLabel($row['status'] ?? 'unknown');
            $count   = (int) $row['cnt'];
            $lines[] = 'opencatalogi_listings_total{status="' . $status . '"} ' . $count;
        }

        if (empty($listingCounts) === true) {
            $lines[] = 'opencatalogi_listings_total 0';
        }

        $lines[] = '';

        // Search requests total (from metrics table if available).
        $searchCount = $this->countSearchRequests();
        $lines[]     = '# HELP opencatalogi_search_requests_total Total search requests';
        $lines[]     = '# TYPE opencatalogi_search_requests_total counter';
        $lines[]     = 'opencatalogi_search_requests_total ' . $searchCount;
        $lines[]     = '';

        return implode("\n", $lines) . "\n";
    }//end collectMetrics()


    /**
     * Get publication counts grouped by status and catalog.
     *
     * @return array<array{status: string, catalog: string, cnt: string}> Grouped counts
     */
    private function getPublicationCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.catalog')) AS catalog"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%ublicati%')))
                ->groupBy('status', 'catalog');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get publication counts', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getPublicationCounts()


    /**
     * Count objects matching a schema title pattern.
     *
     * @param string $pattern SQL LIKE pattern for schema title
     *
     * @return int Object count
     */
    private function countObjectsBySchemaPattern(string $pattern): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter($pattern)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to count objects', ['error' => $e->getMessage()]);
            return 0;
        }
    }//end countObjectsBySchemaPattern()


    /**
     * Get listing counts grouped by status.
     *
     * @return array<array{status: string, cnt: string}> Grouped counts
     */
    private function getListingCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%isting%')))
                ->groupBy('status');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get listing counts', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getListingCounts()


    /**
     * Count search requests from the metrics table.
     *
     * @return int Search request count
     */
    private function countSearchRequests(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from('openregister_metrics')
                ->where($qb->expr()->like('metric_type', $qb->createNamedParameter('search_%')));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            // Table may not exist.
            return 0;
        }
    }//end countSearchRequests()


    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion('opencatalogi');
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()


    /**
     * Sanitize a label value for Prometheus format.
     *
     * @param string $value The label value
     *
     * @return string Sanitized label value
     */
    private function sanitizeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }//end sanitizeLabel()


}//end class
