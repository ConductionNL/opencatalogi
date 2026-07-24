<?php

declare(strict_types=1);

namespace Unit\Observability;

use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OpenCatalogiMetricsProvider.
 *
 * Verifies the AppHost escape-hatch provider reproduces the pre-adoption
 * MetricsController domain families (names, types, zero-fallback samples) and
 * that its counts are now sourced through OpenRegister object aggregation
 * (SchemaMapper + ObjectService), not raw query builders against OR tables.
 */
class OpenCatalogiMetricsProviderTest extends TestCase
{

    private ContainerInterface|MockObject $container;

    private LoggerInterface|MockObject $logger;

    /**
     * Map of container id → callable returning the resolved service.
     *
     * @var array<string, callable>
     */
    private array $services = [];


    /**
     * Wire an empty OR (no schemas) by default so the provider exercises its
     * zero-fallback paths deterministically.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if (isset($this->services[$id]) === true) {
                    return ($this->services[$id])();
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

    }//end setUp()


    /**
     * Build the provider under test.
     *
     * @return OpenCatalogiMetricsProvider The provider.
     */
    private function provider(): OpenCatalogiMetricsProvider
    {
        return new OpenCatalogiMetricsProvider($this->container, $this->logger);

    }//end provider()


    /**
     * Register a fake OR mapper/service returning a fixed value from findAll /
     * searchObjects.
     *
     * @param string       $id      Container id.
     * @param object       $service The service double.
     *
     * @return void
     */
    private function registerService(string $id, object $service): void
    {
        $this->services[$id] = static fn() => $service;

    }//end registerService()


    /**
     * A schema-mapper double whose findAll() returns the given schema arrays.
     *
     * @param array<int, array<string, mixed>> $schemas Schema field arrays.
     *
     * @return object The double.
     */
    private function schemaMapperReturning(array $schemas): object
    {
        return new class ($schemas) {
            /**
             * @param array<int, array<string, mixed>> $schemas
             */
            public function __construct(private array $schemas)
            {
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function findAll(): array
            {
                return $this->schemas;
            }
        };

    }//end schemaMapperReturning()


    /**
     * A register-mapper double whose findAll() returns the given register arrays.
     *
     * @param array<int, array<string, mixed>> $registers Register field arrays.
     *
     * @return object The double.
     */
    private function registerMapperReturning(array $registers): object
    {
        return new class ($registers) {
            /**
             * @param array<int, array<string, mixed>> $registers
             */
            public function __construct(private array $registers)
            {
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function findAll(): array
            {
                return $this->registers;
            }
        };

    }//end registerMapperReturning()


    /**
     * An object-service double whose searchObjects() returns objects keyed by
     * "register:schema".
     *
     * @param array<string, array<int, array<string, mixed>>> $byPair Map of "reg:sch" → objects.
     *
     * @return object The double.
     */
    private function objectServiceReturning(array $byPair): object
    {
        return new class ($byPair) {
            /**
             * @param array<string, array<int, array<string, mixed>>> $byPair
             */
            public function __construct(private array $byPair)
            {
            }

            /**
             * @param array<string, mixed> $query
             *
             * @return array<int, array<string, mixed>>
             */
            public function searchObjects(array $query=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                $register = ($query['@self']['register'] ?? '');
                $schema   = ($query['@self']['schema'] ?? '');
                $key      = $register.':'.$schema;
                return ($this->byPair[$key] ?? []);
            }
        };

    }//end objectServiceReturning()


    /**
     * The provider returns the six domain metric families in the contract order.
     *
     * @return void
     */
    public function testReturnsExpectedFamilies(): void
    {
        $this->registerService('OCA\OpenRegister\Db\SchemaMapper', $this->schemaMapperReturning([]));

        $samples = $this->provider()->metrics();

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
        $this->registerService('OCA\OpenRegister\Db\SchemaMapper', $this->schemaMapperReturning([]));

        $byName = [];
        foreach ($this->provider()->metrics() as $sample) {
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
        $this->registerService('OCA\OpenRegister\Db\SchemaMapper', $this->schemaMapperReturning([]));

        $byName = [];
        foreach ($this->provider()->metrics() as $sample) {
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


    /**
     * With OR data present, the provider aggregates publications by status+catalog
     * and usage counters by catalog+kind through ObjectService — proving the counts
     * are sourced from OR object aggregation.
     *
     * @return void
     */
    public function testAggregatesPublicationsAndUsageFromOr(): void
    {
        // Schemas: publication (id 11), usageCounter (id 12).
        $this->registerService(
            'OCA\OpenRegister\Db\SchemaMapper',
            $this->schemaMapperReturning(
                [
                    ['id' => 11, 'title' => 'Publication'],
                    ['id' => 12, 'title' => 'usageCounter'],
                ]
            )
        );

        // Register 1 contains both schemas.
        $this->registerService(
            'OCA\OpenRegister\Db\RegisterMapper',
            $this->registerMapperReturning(
                [
                    ['id' => 1, 'schemas' => [11, 12]],
                ]
            )
        );

        $this->registerService(
            'OCA\OpenRegister\Service\ObjectService',
            $this->objectServiceReturning(
                [
                    // Publications in register 1 / schema 11.
                    '1:11' => [
                        ['status' => 'published', 'catalog' => 'woo'],
                        ['status' => 'published', 'catalog' => 'woo'],
                        ['status' => 'concept', 'catalog' => 'woo'],
                    ],
                    // Usage counters in register 1 / schema 12.
                    '1:12' => [
                        ['kind' => 'view', 'catalog' => 'woo', 'count' => 5],
                        ['kind' => 'view', 'catalog' => 'woo', 'count' => 3],
                        ['kind' => 'download', 'catalog' => 'woo', 'count' => 2],
                    ],
                ]
            )
        );

        $byName = [];
        foreach ($this->provider()->metrics() as $sample) {
            $byName[$sample->name] = $sample;
        }

        // publications_total: 2 published + 1 concept, all catalog "woo".
        $this->assertEqualsCanonicalizing(
            [
                ['labels' => ['status' => 'published', 'catalog' => 'woo'], 'value' => 2],
                ['labels' => ['status' => 'concept', 'catalog' => 'woo'], 'value' => 1],
            ],
            $byName['publications_total']->samples
        );

        // publication_views_total: 5 + 3 = 8 for catalog "woo".
        $this->assertSame(
            [['labels' => ['catalog' => 'woo'], 'value' => 8]],
            $byName['publication_views_total']->samples
        );

        // file_downloads_total: 2 for catalog "woo".
        $this->assertSame(
            [['labels' => ['catalog' => 'woo'], 'value' => 2]],
            $byName['file_downloads_total']->samples
        );

    }//end testAggregatesPublicationsAndUsageFromOr()


}//end class
