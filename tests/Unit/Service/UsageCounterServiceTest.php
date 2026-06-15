<?php
/**
 * Unit tests for UsageCounterService.
 *
 * Covers: usage-event recording (increment upsert), aggregation/rollup maths,
 * popular-ranking (top-N), crawler filtering, and the privacy invariant that
 * stored counters and the counting path never carry request-derived data.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\UsageCounterService
 */
class UsageCounterServiceTest extends TestCase
{

    private IAppConfig|MockObject $appConfig;

    private IAppManager|MockObject $appManager;

    private ContainerInterface|MockObject $container;

    private LoggerInterface|MockObject $logger;

    private UsageCounterService $service;

    protected function setUp(): void
    {
        $this->appConfig  = $this->createMock(IAppConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        // Default: register/schema configured, crawler list default.
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $default = '') {
                    return match ($key) {
                        'usageCounter_register' => 'publication',
                        'usageCounter_schema'   => 'usageCounter',
                        default                 => $default,
                    };
                }
            );

        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->service = new UsageCounterService(
            $this->appConfig,
            $this->appManager,
            $this->container,
            $this->logger,
        );
    }//end setUp()

    private function withObjectService(ObjectService|MockObject $os): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($os);
    }//end withObjectService()

    // ──────────────────────────────────────────────────────────
    // Crawler filtering (ANA-003)
    // ──────────────────────────────────────────────────────────

    public function testIsCrawlerKnownBot(): void
    {
        $this->assertTrue($this->service->isCrawler('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        $this->assertTrue($this->service->isCrawler('curl/8.0'));
    }//end testIsCrawlerKnownBot()

    public function testIsCrawlerEmptyUaTreatedAsBot(): void
    {
        $this->assertTrue($this->service->isCrawler(''));
        $this->assertTrue($this->service->isCrawler(null));
    }//end testIsCrawlerEmptyUaTreatedAsBot()

    public function testIsCrawlerRealBrowserIsNotBot(): void
    {
        $this->assertFalse(
            $this->service->isCrawler('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Safari/537.36')
        );
    }//end testIsCrawlerRealBrowserIsNotBot()

    // ──────────────────────────────────────────────────────────
    // increment() — recording (ANA-001, ANA-002)
    // ──────────────────────────────────────────────────────────

    public function testIncrementCreatesFirstCounter(): void
    {
        $os = $this->createMock(ObjectService::class);
        // No existing counter for today.
        $os->method('searchObjects')->willReturn([]);

        $captured = null;
        $os->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$captured) {
                    $captured = $object;
                    return new \OCA\OpenRegister\Db\ObjectEntity();
                }
            );

        $this->withObjectService($os);

        $ok = $this->service->increment('pub-1', UsageCounterService::KIND_VIEW, 'Mozilla/5.0', 'woo');
        $this->assertTrue($ok);
        $this->assertSame('pub-1', $captured['publication']);
        $this->assertSame(UsageCounterService::KIND_VIEW, $captured['kind']);
        $this->assertSame(1, $captured['count']);
    }//end testIncrementCreatesFirstCounter()

    public function testIncrementBumpsExistingCounter(): void
    {
        $os       = $this->createMock(ObjectService::class);
        $existing = ['id' => 'c-1', 'publication' => 'pub-1', 'date' => date('Y-m-d'), 'kind' => 'view', 'count' => 9];
        $os->method('searchObjects')->willReturn([$existing]);

        $captured = null;
        $os->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$captured) {
                    $captured = $object;
                    return new \OCA\OpenRegister\Db\ObjectEntity();
                }
            );

        $this->withObjectService($os);

        $ok = $this->service->increment('pub-1', UsageCounterService::KIND_VIEW, 'Mozilla/5.0');
        $this->assertTrue($ok);
        $this->assertSame(10, $captured['count']);
    }//end testIncrementBumpsExistingCounter()

    public function testIncrementSkippedForCrawler(): void
    {
        $os = $this->createMock(ObjectService::class);
        $os->expects($this->never())->method('saveObject');
        $this->withObjectService($os);

        $this->assertFalse($this->service->increment('pub-1', UsageCounterService::KIND_VIEW, 'Googlebot/2.1'));
    }//end testIncrementSkippedForCrawler()

    public function testIncrementRejectsUnknownKind(): void
    {
        $this->assertFalse($this->service->increment('pub-1', 'sneaky', 'Mozilla/5.0'));
    }//end testIncrementRejectsUnknownKind()

    public function testIncrementSwallowsFailure(): void
    {
        $os = $this->createMock(ObjectService::class);
        $os->method('searchObjects')->willThrowException(new \RuntimeException('OR down'));
        $this->withObjectService($os);

        // ANA-001: a counting failure is swallowed, never propagated.
        $this->assertFalse($this->service->increment('pub-1', UsageCounterService::KIND_DOWNLOAD, 'Mozilla/5.0'));
    }//end testIncrementSwallowsFailure()

    public function testIncrementNoOpWhenSchemaUnconfigured(): void
    {
        // Re-create the service with empty register/schema config.
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(fn(string $a, string $k, string $d = '') => $d);

        $service = new UsageCounterService($appConfig, $this->appManager, $this->container, $this->logger);
        $this->assertFalse($service->increment('pub-1', UsageCounterService::KIND_VIEW, 'Mozilla/5.0'));
    }//end testIncrementNoOpWhenSchemaUnconfigured()

    // ──────────────────────────────────────────────────────────
    // Privacy invariant (ANA-002 / ANA-003)
    // ──────────────────────────────────────────────────────────

    public function testStoredCounterContainsNoRequestData(): void
    {
        $os = $this->createMock(ObjectService::class);
        $os->method('searchObjects')->willReturn([]);

        $captured = null;
        $os->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return new \OCA\OpenRegister\Db\ObjectEntity();
            }
        );
        $this->withObjectService($os);

        $this->service->increment(
            'pub-1',
            UsageCounterService::KIND_VIEW,
            'Mozilla/5.0 (secret browser fingerprint 1.2.3.4)',
            'woo'
        );

        // The stored object MUST hold ONLY (publication, catalog, date, kind, count).
        $this->assertSame(
            ['publication', 'catalog', 'date', 'kind', 'count'],
            array_keys($captured)
        );
        // No request-derived attribute leaked into any value.
        $serialized = json_encode($captured);
        $this->assertStringNotContainsStringIgnoringCase('Mozilla', $serialized);
        $this->assertStringNotContainsStringIgnoringCase('fingerprint', $serialized);
        $this->assertStringNotContainsString('1.2.3.4', $serialized);
    }//end testStoredCounterContainsNoRequestData()

    public function testCounterFailureLogDoesNotContainUserAgent(): void
    {
        $os = $this->createMock(ObjectService::class);
        $os->method('searchObjects')->willThrowException(new \RuntimeException('boom'));
        $this->withObjectService($os);

        $logged = [];
        $this->logger->method('warning')->willReturnCallback(
            function (string $msg, array $ctx = []) use (&$logged) {
                $logged[] = [$msg, $ctx];
            }
        );

        $this->service->increment('pub-1', UsageCounterService::KIND_VIEW, 'SecretAgent/9.9');

        foreach ($logged as [$msg, $ctx]) {
            $blob = $msg.json_encode($ctx);
            $this->assertStringNotContainsString('SecretAgent', $blob);
        }
    }//end testCounterFailureLogDoesNotContainUserAgent()

    // ──────────────────────────────────────────────────────────
    // aggregateSeries() — timeseries + totals + counting-start (ANA-004)
    // ──────────────────────────────────────────────────────────

    public function testAggregateSeriesTotalsAndOrder(): void
    {
        $rows = [
            ['publication' => 'p', 'date' => '2026-05-03', 'kind' => 'view', 'count' => 20],
            ['publication' => 'p', 'date' => '2026-05-01', 'kind' => 'view', 'count' => 100],
            ['publication' => 'p', 'date' => '2026-05-01', 'kind' => 'download', 'count' => 40],
            ['publication' => 'p', 'date' => '2026-05-03', 'kind' => 'download', 'count' => 0],
        ];

        $agg = $this->service->aggregateSeries($rows);

        $this->assertSame(120, $agg['views']);
        $this->assertSame(40, $agg['downloads']);
        $this->assertSame('2026-05-01', $agg['countingStart']);
        // Series is chronologically sorted.
        $this->assertSame('2026-05-01', $agg['series'][0]['date']);
        $this->assertSame('2026-05-03', $agg['series'][1]['date']);
        $this->assertSame(100, $agg['series'][0]['views']);
        // Series totals reconcile with the headline totals.
        $sum = array_sum(array_column($agg['series'], 'views'));
        $this->assertSame($agg['views'], $sum);
    }//end testAggregateSeriesTotalsAndOrder()

    public function testAggregateSeriesEmpty(): void
    {
        $agg = $this->service->aggregateSeries([]);
        $this->assertSame(0, $agg['views']);
        $this->assertSame(0, $agg['downloads']);
        $this->assertNull($agg['countingStart']);
        $this->assertSame([], $agg['series']);
    }//end testAggregateSeriesEmpty()

    // ──────────────────────────────────────────────────────────
    // aggregateCatalog() — roll-up + top-N ranking (ANA-005)
    // ──────────────────────────────────────────────────────────

    public function testAggregateCatalogTopNRanking(): void
    {
        $rows = [
            ['publication' => 'a', 'kind' => 'view', 'count' => 10],
            ['publication' => 'b', 'kind' => 'view', 'count' => 50],
            ['publication' => 'c', 'kind' => 'view', 'count' => 30],
            ['publication' => 'a', 'kind' => 'download', 'count' => 99],
            ['publication' => 'b', 'kind' => 'download', 'count' => 1],
        ];

        $agg = $this->service->aggregateCatalog($rows, 2);

        $this->assertSame(90, $agg['views']);
        $this->assertSame(100, $agg['downloads']);
        // Top-2 by views: b (50), c (30).
        $this->assertCount(2, $agg['topViewed']);
        $this->assertSame('b', $agg['topViewed'][0]['publication']);
        $this->assertSame('c', $agg['topViewed'][1]['publication']);
        // Top by downloads: a (99) first.
        $this->assertSame('a', $agg['topDownloaded'][0]['publication']);
        $this->assertSame(99, $agg['topDownloaded'][0]['downloads']);
    }//end testAggregateCatalogTopNRanking()

    public function testAggregateCatalogDefaultTopBounded(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = ['publication' => 'p'.$i, 'kind' => 'view', 'count' => $i];
        }

        $agg = $this->service->aggregateCatalog($rows);
        // Default N=10.
        $this->assertCount(10, $agg['topViewed']);
        // Highest first.
        $this->assertSame('p24', $agg['topViewed'][0]['publication']);
    }//end testAggregateCatalogDefaultTopBounded()

}//end class
