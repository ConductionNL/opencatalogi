<?php

declare(strict_types=1);

namespace Unit\Observability;

use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OpenCatalogiMetricsProvider.
 *
 * Verifies the AppHost escape-hatch provider reproduces the pre-adoption
 * MetricsController domain families (names, types, zero-fallback samples)
 * so the /api/metrics contract is preserved on adoption.
 */
class OpenCatalogiMetricsProviderTest extends TestCase
{

    private IDBConnection|MockObject $db;

    private LoggerInterface|MockObject $logger;

    private OpenCatalogiMetricsProvider $provider;


    /**
     * Set up a query builder that returns empty result sets so the provider
     * exercises its zero-fallback paths deterministically.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Use anonymous fluent test doubles instead of mocking IQueryBuilder /
        // IExpressionBuilder — those OCP interfaces reference Doctrine\DBAL classes
        // at class-load, which are absent from the standalone vendor tree. The
        // double returns empty result sets so the provider exercises its
        // zero-fallback paths deterministically.
        $result = new class {
            /**
             * @return array<int, array<string, mixed>>
             */
            public function fetchAll(): array
            {
                return [];
            }

            /**
             * @return array<string, mixed>
             */
            public function fetch(): array
            {
                return ['cnt' => 0];
            }

            public function closeCursor(): void
            {
            }
        };

        $expr = new class {
            public function __call(string $name, array $arguments): string
            {
                return $name;
            }
        };

        $func = new class {
            public function count(mixed ...$args): string
            {
                return 'count';
            }
        };

        $qb = new class ($result, $expr, $func) {
            public function __construct(
                private object $result,
                private object $expr,
                private object $func
            ) {
            }

            public function expr(): object
            {
                return $this->expr;
            }

            public function func(): object
            {
                return $this->func;
            }

            public function executeQuery(): object
            {
                return $this->result;
            }

            public function createFunction(string $call): string
            {
                return $call;
            }

            public function createNamedParameter(mixed $value): string
            {
                return ':p';
            }

            public function __call(string $name, array $arguments): self
            {
                // select / selectAlias / from / innerJoin / where / groupBy ...
                return $this;
            }
        };

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->provider = new OpenCatalogiMetricsProvider($this->db, $this->logger);

    }//end setUp()


    /**
     * The provider returns the six domain metric families in the contract order.
     *
     * @return void
     */
    public function testReturnsExpectedFamilies(): void
    {
        $samples = $this->provider->metrics();

        $this->assertContainsOnlyInstancesOf(MetricSample::class, $samples);

        $names = array_map(static fn(MetricSample $s): string => $s->name, $samples);
        $this->assertSame(
            [
                'publications_total',
                'catalogs_total',
                'listings_total',
                'directory_entries_total',
                'publication_views_total',
                'file_downloads_total',
            ],
            $names
        );

    }//end testReturnsExpectedFamilies()


    /**
     * Counter families keep their `counter` type; gauges stay gauges.
     *
     * @return void
     */
    public function testMetricTypesMatchContract(): void
    {
        $byName = [];
        foreach ($this->provider->metrics() as $sample) {
            $byName[$sample->name] = $sample->type;
        }

        $this->assertSame('gauge', $byName['publications_total']);
        $this->assertSame('gauge', $byName['catalogs_total']);
        $this->assertSame('gauge', $byName['listings_total']);
        $this->assertSame('gauge', $byName['directory_entries_total']);
        $this->assertSame('counter', $byName['publication_views_total']);
        $this->assertSame('counter', $byName['file_downloads_total']);

    }//end testMetricTypesMatchContract()


    /**
     * On an empty dataset the provider still emits the historical zero-fallback
     * samples so the exposition is never missing the listings / usage families.
     *
     * @return void
     */
    public function testEmptyDatasetEmitsZeroFallbacks(): void
    {
        $byName = [];
        foreach ($this->provider->metrics() as $sample) {
            $byName[$sample->name] = $sample;
        }

        // listings_total → single unlabelled 0 sample.
        $this->assertSame([['labels' => [], 'value' => 0]], $byName['listings_total']->samples);

        // view/download → single {catalog=""} 0 sample.
        $this->assertSame(
            [['labels' => ['catalog' => ''], 'value' => 0]],
            $byName['publication_views_total']->samples
        );
        $this->assertSame(
            [['labels' => ['catalog' => ''], 'value' => 0]],
            $byName['file_downloads_total']->samples
        );

        // Scalar gauges → single unlabelled 0.
        $this->assertSame([['labels' => [], 'value' => 0]], $byName['catalogs_total']->samples);
        $this->assertSame([['labels' => [], 'value' => 0]], $byName['directory_entries_total']->samples);

    }//end testEmptyDatasetEmitsZeroFallbacks()


}//end class
