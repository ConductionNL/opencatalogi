<?php
/**
 * Unit tests for SchemaOrgService.
 *
 * Covers schema.org JSON-LD rendering of publications and catalogs from the
 * `x-schema-org` markers: marker-typed default, elected `Dataset` shape with
 * `DataDownload` distributions + `includedInDataCatalog`, and the catalog
 * `DataCatalog` node listing publicly visible publications as `dataset` refs.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SchemaOrgService.
 */
class SchemaOrgServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;

    private DcatService|MockObject $dcatService;

    private IURLGenerator|MockObject $urlGenerator;

    private LoggerInterface|MockObject $logger;

    /**
     * Map of container id → resolved service double.
     *
     * @var array<string, object>
     */
    private array $services = [];


    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->dcatService  = $this->createMock(DcatService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->urlGenerator->method('getBaseUrl')->willReturn('https://example.test');

        $this->dcatService->method('datasetIri')->willReturnCallback(
            static fn(string $slug, string $uuid): string => "https://example.test/apps/opencatalogi/api/$slug/$uuid"
        );
        $this->dcatService->method('resolveDefaults')->willReturn(
            [
                'publisherName' => 'Gemeente Test',
                'publisherUri'  => 'https://example.test/org',
                'license'       => 'http://creativecommons.org/publicdomain/zero/1.0/',
                'contactPoint'  => '',
                'organisation'  => null,
            ]
        );

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if (isset($this->services[$id]) === true) {
                    return $this->services[$id];
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

    }//end setUp()


    /**
     * Build the service under test.
     *
     * @return SchemaOrgService The service.
     */
    private function service(): SchemaOrgService
    {
        return new SchemaOrgService($this->container, $this->dcatService, $this->urlGenerator, $this->logger);

    }//end service()


    /**
     * A schema-mapper double whose find() returns a schema with the given marker.
     *
     * @param string $marker The x-schema-org CURIE.
     *
     * @return object The double.
     */
    private function schemaMapperWithMarker(string $marker): object
    {
        return new class ($marker) {
            /**
             * @param string $marker
             */
            public function __construct(private string $marker)
            {
            }

            public function find(int|string $id): object
            {
                return new class ($this->marker) implements \JsonSerializable {
                    /**
                     * @param string $marker
                     */
                    public function __construct(private string $marker)
                    {
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return ['id' => 11, 'title' => 'Publication', 'x-schema-org' => $this->marker];
                    }
                };
            }
        };

    }//end schemaMapperWithMarker()


    /**
     * A file-service double returning the given formatFiles results.
     *
     * @param array<int, array<string, mixed>> $files The formatted files.
     *
     * @return object The double.
     */
    private function fileServiceReturning(array $files): object
    {
        return new class ($files) {
            /**
             * @param array<int, array<string, mixed>> $files
             */
            public function __construct(private array $files)
            {
            }

            /**
             * @return array<int, mixed>
             */
            public function getFiles(mixed $object, ?bool $sharedFilesOnly=false): array
            {
                return $this->files;
            }

            /**
             * @param array<int, mixed> $files
             *
             * @return array<string, mixed>
             */
            public function formatFiles(array $files, ?array $requestParams=[]): array
            {
                return ['results' => $files];
            }
        };

    }//end fileServiceReturning()


    /**
     * An object-service double whose searchObjectsPaginated returns the given results.
     *
     * @param array<int, array<string, mixed>> $results The publication rows.
     *
     * @return object The double.
     */
    private function objectServiceReturning(array $results): object
    {
        return new class ($results) {
            /**
             * @param array<int, array<string, mixed>> $results
             */
            public function __construct(private array $results)
            {
            }

            /**
             * @param array<string, mixed> $query
             *
             * @return array<string, mixed>
             */
            public function searchObjectsPaginated(array $query=[], bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false): array
            {
                return ['results' => $this->results, 'total' => count($this->results)];
            }
        };

    }//end objectServiceReturning()


    /**
     * The marker `schema:CreativeWork` resolves to the bare type `CreativeWork`.
     *
     * @return void
     */
    public function testMarkerTypeStripsCurie(): void
    {
        $this->services['OCA\OpenRegister\Db\SchemaMapper'] = $this->schemaMapperWithMarker('schema:CreativeWork');

        $this->assertSame('CreativeWork', $this->service()->markerTypeForSchema(11));

    }//end testMarkerTypeStripsCurie()


    /**
     * A non-elected catalog renders the publication with its marker type and no
     * dataset-only fields.
     *
     * @return void
     */
    public function testNonElectedPublicationKeepsMarkerType(): void
    {
        $this->services['OCA\OpenRegister\Db\SchemaMapper'] = $this->schemaMapperWithMarker('schema:CreativeWork');

        $object = [
            'id'          => 'uuid-1',
            '@self'       => ['uuid' => 'uuid-1', 'schema' => 11, 'updated' => '2024-01-15T10:00:00+00:00'],
            'title'       => 'Besluit X',
            'description' => 'Een openbaar besluit',
        ];
        $catalog = ['title' => 'WOO', 'schemaOrgDataset' => false];

        $node = $this->service()->buildPublicationNode($object, $catalog, 'woo');

        $this->assertSame('https://schema.org', $node['@context']);
        $this->assertSame('CreativeWork', $node['@type']);
        $this->assertSame('Besluit X', $node['name']);
        $this->assertSame('Een openbaar besluit', $node['description']);
        $this->assertSame('https://example.test/apps/opencatalogi/api/woo/uuid-1', $node['url']);
        $this->assertArrayHasKey('dateModified', $node);
        $this->assertArrayNotHasKey('distribution', $node);
        $this->assertArrayNotHasKey('includedInDataCatalog', $node);

    }//end testNonElectedPublicationKeepsMarkerType()


    /**
     * An elected catalog renders the publication as `Dataset` with `DataDownload`
     * distributions and an `includedInDataCatalog` backlink.
     *
     * @return void
     */
    public function testElectedPublicationCarriesDatasetShape(): void
    {
        $this->services['OCA\OpenRegister\Db\SchemaMapper'] = $this->schemaMapperWithMarker('schema:CreativeWork');
        $this->services['OCA\OpenRegister\Service\FileService'] = $this->fileServiceReturning(
            [
                ['downloadUrl' => 'https://example.test/f/a.pdf', 'mimetype' => 'application/pdf', 'name' => 'a.pdf', 'size' => 100],
                ['downloadUrl' => 'https://example.test/f/b.csv', 'mimetype' => 'text/csv', 'name' => 'b.csv'],
            ]
        );

        $object = [
            'id'    => 'uuid-2',
            '@self' => ['uuid' => 'uuid-2', 'schema' => 11, 'updated' => '2024-02-01T00:00:00+00:00'],
            'title' => 'Dataset Y',
        ];
        $catalog = ['title' => 'Open Data', 'schemaOrgDataset' => true];

        $node = $this->service()->buildPublicationNode($object, $catalog, 'opendata');

        $this->assertSame('Dataset', $node['@type']);
        $this->assertCount(2, $node['distribution']);
        $this->assertSame('DataDownload', $node['distribution'][0]['@type']);
        $this->assertSame('https://example.test/f/a.pdf', $node['distribution'][0]['contentUrl']);
        $this->assertSame('application/pdf', $node['distribution'][0]['encodingFormat']);
        $this->assertSame('DataCatalog', $node['includedInDataCatalog']['@type']);
        $this->assertSame('Open Data', $node['includedInDataCatalog']['name']);

    }//end testElectedPublicationCarriesDatasetShape()


    /**
     * The catalog node lists exactly the publicly visible publications as `dataset`
     * refs by their canonical URLs.
     *
     * @return void
     */
    public function testCatalogNodeListsVisiblePublications(): void
    {
        $this->services['OCA\OpenRegister\Service\ObjectService'] = $this->objectServiceReturning(
            [
                ['id' => 'p1', '@self' => ['uuid' => 'p1']],
                ['id' => 'p2', '@self' => ['uuid' => 'p2']],
                ['id' => 'p3', '@self' => ['uuid' => 'p3']],
            ]
        );

        $catalog = [
            'title'     => 'WOO',
            'registers' => [1],
            'schemas'   => [11],
        ];

        $node = $this->service()->buildCatalogNode($catalog, 'woo');

        $this->assertSame('DataCatalog', $node['@type']);
        $this->assertSame('https://schema.org', $node['@context']);
        $this->assertCount(3, $node['dataset']);
        $this->assertSame('https://example.test/apps/opencatalogi/api/woo/p1', $node['dataset'][0]['@id']);

    }//end testCatalogNodeListsVisiblePublications()


    /**
     * The dataset election defaults to off.
     *
     * @return void
     */
    public function testDatasetElectionDefaultsOff(): void
    {
        $this->assertFalse($this->service()->isDatasetElected([]));
        $this->assertTrue($this->service()->isDatasetElected(['schemaOrgDataset' => true]));

    }//end testDatasetElectionDefaultsOff()
}//end class
