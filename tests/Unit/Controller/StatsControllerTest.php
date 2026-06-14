<?php
/**
 * Unit tests for StatsController.
 *
 * Covers: per-publication stats authorization (no IDOR), anonymous/unauthorized
 * rejection, catalog roll-up + top-N, period-without-data zeros, and the CSV
 * export column/BOM contract.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\StatsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Controller\StatsController
 */
class StatsControllerTest extends TestCase
{

    private IRequest|MockObject $request;

    private UsageCounterService|MockObject $usage;

    private CatalogiService|MockObject $catalogi;

    private IAppManager|MockObject $appManager;

    private ContainerInterface|MockObject $container;

    private IL10N|MockObject $l10n;

    private LoggerInterface|MockObject $logger;

    private StatsController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->usage      = $this->createMock(UsageCounterService::class);
        $this->catalogi   = $this->createMock(CatalogiService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->l10n->method('t')->willReturnCallback(fn(string $t, array $p = []) => $t);
        $this->request->method('getParam')
            ->willReturnCallback(fn(string $k, $d = null) => $d);

        $this->controller = new StatsController(
            'opencatalogi',
            $this->request,
            $this->usage,
            $this->catalogi,
            $this->appManager,
            $this->container,
            $this->l10n,
            $this->logger,
        );
    }//end setUp()

    private function withReadableObject(bool $readable): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $os = $this->createMock(ObjectService::class);
        $os->method('searchObjects')->willReturn($readable ? [['id' => 'x']] : []);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($os);
    }//end withReadableObject()

    // ──────────────────────────────────────────────────────────
    // Per-publication stats authorization (ANA-004 / no-IDOR)
    // ──────────────────────────────────────────────────────────

    public function testPublicationStatsAuthorizedReturnsData(): void
    {
        $this->withReadableObject(true);
        $this->usage->method('getPublicationStats')->willReturn(
            ['views' => 120, 'downloads' => 40, 'series' => [], 'countingStart' => '2026-05-01']
        );

        $resp = $this->controller->publication('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(200, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame(120, $data['views']);
        $this->assertSame('pub-1', $data['publication']);
        $this->assertSame('2026-05-01', $data['countingStart']);
    }//end testPublicationStatsAuthorizedReturnsData()

    public function testPublicationStatsDeniedForUnauthorizedUser(): void
    {
        // Object not readable under the caller's RBAC → 403, no stats (no IDOR).
        $this->withReadableObject(false);
        $this->usage->expects($this->never())->method('getPublicationStats');

        $resp = $this->controller->publication('pub-secret');
        $this->assertSame(403, $resp->getStatus());
    }//end testPublicationStatsDeniedForUnauthorizedUser()

    public function testPublicationStatsDeniedWhenOpenRegisterMissing(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);
        $resp = $this->controller->publication('pub-1');
        $this->assertSame(403, $resp->getStatus());
    }//end testPublicationStatsDeniedWhenOpenRegisterMissing()

    // ──────────────────────────────────────────────────────────
    // Catalog roll-up + top-N (ANA-005)
    // ──────────────────────────────────────────────────────────

    public function testCatalogStatsReturnsRollup(): void
    {
        $this->catalogi->method('getCatalogBySlug')->willReturn(['slug' => 'woo']);
        $this->usage->method('getCatalogStats')->willReturn(
            [
                'views'         => 200,
                'downloads'     => 50,
                'topViewed'     => [['publication' => 'a', 'views' => 100, 'downloads' => 0]],
                'topDownloaded' => [],
                'countingStart' => '2026-01-01',
            ]
        );

        $resp = $this->controller->catalog('woo');
        $this->assertSame(200, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame(200, $data['views']);
        $this->assertSame('woo', $data['catalog']);
        $this->assertSame('a', $data['topViewed'][0]['publication']);
    }//end testCatalogStatsReturnsRollup()

    public function testCatalogStatsUnknownCatalog(): void
    {
        $this->catalogi->method('getCatalogBySlug')->willReturn(null);
        $resp = $this->controller->catalog('nope');
        $this->assertSame(404, $resp->getStatus());
    }//end testCatalogStatsUnknownCatalog()

    // ──────────────────────────────────────────────────────────
    // CSV export (ANA-007)
    // ──────────────────────────────────────────────────────────

    public function testExportProducesCsvDownload(): void
    {
        $this->catalogi->method('getCatalogBySlug')->willReturn(['slug' => 'woo']);
        $this->usage->method('getCountersForCatalog')->willReturn(
            [
                ['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'view', 'count' => 5],
                ['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'download', 'count' => 2],
            ]
        );

        $resp = $this->controller->export('woo');
        $this->assertInstanceOf(DataDownloadResponse::class, $resp);
    }//end testExportProducesCsvDownload()

    public function testBuildCsvBomAndColumns(): void
    {
        $csv = $this->controller->buildCsv(
            [
                ['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'view', 'count' => 5],
                ['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'download', 'count' => 2],
                ['publication' => 'b', 'category' => 'Nota', 'published' => '2026-02-01', 'kind' => 'view', 'count' => 0],
            ]
        );

        // UTF-8 BOM prefix.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Header columns (the "Published date" cell may be quoted by fputcsv).
        $this->assertMatchesRegularExpression(
            '/Publication,Category,"?Published date"?,Views,Downloads/',
            $csv
        );
        // Publication 'a' rolled up to 5 views, 2 downloads.
        $this->assertMatchesRegularExpression('/a,Besluiten,2026-01-01,5,2/', $csv);
        // Zero-usage publication 'b' is still present with zeros (reach reporting).
        $this->assertMatchesRegularExpression('/b,Nota,2026-02-01,0,0/', $csv);
    }//end testBuildCsvBomAndColumns()

}//end class
