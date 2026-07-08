<?php
/**
 * Unit tests for DcatMappingService.
 *
 * Pure mapping-layer tests: dataset←publication, distribution←attachment,
 * catalog/publisher metadata, mapping-annotation resolution, opt-out, and the
 * mandatory-property completion chain. No Nextcloud bootstrap required.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatMappingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DcatMappingService.
 */
class DcatMappingServiceTest extends TestCase
{

    private DcatMappingService $service;

    protected function setUp(): void
    {
        $this->service = new DcatMappingService(new \OCA\OpenCatalogi\Service\DcatVocabularyService());
    }

    public function testResolveMappingFallsBackToDefaultWhenUnannotated(): void
    {
        $mapping = $this->service->resolveMapping(['title' => 'x']);
        $this->assertSame(DcatMappingService::DEFAULT_MAPPING, $mapping);
    }

    public function testResolveMappingUsesAnnotation(): void
    {
        $schema  = ['x-dcat' => ['mapping' => ['dct:title' => 'naam', 'dcat:keyword' => 'tags[]']]];
        $mapping = $this->service->resolveMapping($schema);
        $this->assertSame(['dct:title' => 'naam', 'dcat:keyword' => 'tags[]'], $mapping);
    }

    public function testResolveMappingReturnsNullWhenOptedOut(): void
    {
        $this->assertNull($this->service->resolveMapping(['x-dcat' => false]));
        $this->assertSame(DcatMappingService::DEFAULT_MAPPING, $this->service->resolveMapping(['title' => 'x']));
    }

    public function testMapDatasetAppliesAnnotatedMapping(): void
    {
        $publication = [
            'id'    => 'uuid-1',
            'naam'  => 'Annual report 2025',
            'tags'  => ['finance', 'budget'],
            '@self' => ['uuid' => 'uuid-1', 'updated' => '2025-01-02T10:00:00+00:00'],
        ];
        $mapping = ['dct:title' => 'naam', 'dcat:keyword' => 'tags[]'];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: $mapping,
            files: [],
            datasetIri: 'https://host/api/cat/uuid-1',
            defaults: []
        );

        $this->assertSame('dcat:Dataset', $dataset['@type']);
        $this->assertSame('https://host/api/cat/uuid-1', $dataset['@id']);
        $this->assertSame('Annual report 2025', $dataset['dct:title']);
        $this->assertSame(['finance', 'budget'], $dataset['dcat:keyword']);
        $this->assertSame(['@id' => 'https://host/api/cat/uuid-1'], $dataset['dcat:landingPage']);
        $this->assertArrayHasKey('dct:modified', $dataset);
    }

    public function testMapDatasetDefaultMappingProducesMandatoryFields(): void
    {
        $publication = [
            'id'          => 'uuid-2',
            'title'       => 'Open dataset',
            'description' => 'A description',
            '@self'       => ['uuid' => 'uuid-2', 'updated' => '2025-03-03T00:00:00+00:00'],
        ];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: DcatMappingService::DEFAULT_MAPPING,
            files: [],
            datasetIri: 'https://host/api/cat/uuid-2',
            defaults: []
        );

        $this->assertSame('Open dataset', $dataset['dct:title']);
        $this->assertSame('A description', $dataset['dct:description']);
        $this->assertArrayHasKey('dct:modified', $dataset);
        $this->assertArrayHasKey('dcat:landingPage', $dataset);
    }

    public function testThemeEmittedAsTooiUriWhenPresent(): void
    {
        $publication = [
            'id'           => 'uuid-3',
            'category'     => 'Finance',
            'tooiThemaUri' => 'https://identifier.overheid.nl/tooi/def/thes/c_123',
            '@self'        => ['uuid' => 'uuid-3'],
        ];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: ['dcat:theme' => 'category'],
            files: [],
            datasetIri: 'https://host/api/cat/uuid-3',
            defaults: []
        );

        $this->assertSame(['@id' => 'https://identifier.overheid.nl/tooi/def/thes/c_123'], $dataset['dcat:theme']);
    }

    public function testUnmappedThemeIsOmittedAndReported(): void
    {
        $publication = [
            'id'       => 'uuid-t',
            'category' => 'some free text theme',
            '@self'    => ['uuid' => 'uuid-t'],
        ];

        $violations = [];
        $dataset    = $this->service->mapDataset(
            publication: $publication,
            mapping: ['dcat:theme' => 'category'],
            files: [],
            datasetIri: 'https://host/api/cat/uuid-t',
            defaults: [],
            hvd: null,
            catalogHvdDefault: null,
            violations: $violations
        );

        // No literal dcat:theme leaked.
        $this->assertArrayNotHasKey('dcat:theme', $dataset);
        $this->assertCount(1, $violations);
        $this->assertSame('dcat:theme', $violations[0]['axis']);

    }//end testUnmappedThemeIsOmittedAndReported()


    public function testMappedThemeBecomesAuthorityUri(): void
    {
        $publication = [
            'id'       => 'uuid-tt',
            'category' => 'transport',
            '@self'    => ['uuid' => 'uuid-tt'],
        ];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: ['dcat:theme' => 'category'],
            files: [],
            datasetIri: 'https://host/api/cat/uuid-tt',
            defaults: []
        );

        $this->assertSame(
            ['@id' => 'http://publications.europa.eu/resource/authority/data-theme/TRAN'],
            $dataset['dcat:theme']
        );

    }//end testMappedThemeBecomesAuthorityUri()


    public function testHvdTriplesEmittedWhenCategoryResolves(): void
    {
        $publication = [
            'id'          => 'uuid-h',
            'hvdCategory' => 'Mobility',
            '@self'       => ['uuid' => 'uuid-h'],
        ];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: DcatMappingService::DEFAULT_MAPPING,
            files: [],
            datasetIri: 'https://host/api/cat/uuid-h',
            defaults: [],
            hvd: ['categoryProperty' => 'hvdCategory']
        );

        $this->assertSame(['@id' => 'http://data.europa.eu/bna/c_b79e35eb'], $dataset['dcatap:hvdCategory']);
        $this->assertSame(
            ['@id' => 'http://data.europa.eu/eli/reg_impl/2023/138/oj'],
            $dataset['dcatap:applicableLegislation']
        );

    }//end testHvdTriplesEmittedWhenCategoryResolves()


    public function testNoHvdTriplesWhenNoCategory(): void
    {
        $publication = [
            'id'    => 'uuid-nh',
            '@self' => ['uuid' => 'uuid-nh'],
        ];

        $dataset = $this->service->mapDataset(
            publication: $publication,
            mapping: DcatMappingService::DEFAULT_MAPPING,
            files: [],
            datasetIri: 'https://host/api/cat/uuid-nh',
            defaults: []
        );

        $this->assertArrayNotHasKey('dcatap:hvdCategory', $dataset);
        $this->assertArrayNotHasKey('dcatap:applicableLegislation', $dataset);

    }//end testNoHvdTriplesWhenNoCategory()


    public function testDistributionsMappedFromAttachments(): void
    {
        $files = [
            ['downloadUrl' => 'https://host/d/1/download', 'accessUrl' => 'https://host/d/1', 'extension' => 'pdf', 'title' => 'doc.pdf', 'size' => 1234],
            ['downloadUrl' => 'https://host/d/2/download', 'extension' => 'csv', 'title' => 'data.csv'],
        ];

        $dataset = $this->service->mapDataset(
            publication: ['id' => 'u', '@self' => ['uuid' => 'u']],
            mapping: DcatMappingService::DEFAULT_MAPPING,
            files: $files,
            datasetIri: 'https://host/api/cat/u',
            defaults: ['license' => 'https://lic']
        );

        $this->assertCount(2, $dataset['dcat:distribution']);
        $pdf = $dataset['dcat:distribution'][0];
        $csv = $dataset['dcat:distribution'][1];
        $this->assertSame('dcat:Distribution', $pdf['@type']);
        $this->assertSame(['@id' => 'https://host/d/1/download'], $pdf['dcat:downloadURL']);
        $this->assertSame('application/pdf', $pdf['dcat:mediaType']);
        $this->assertSame('text/csv', $csv['dcat:mediaType']);
        $this->assertSame(1234, $pdf['dcat:byteSize']);
        $this->assertSame(['@id' => 'https://lic'], $pdf['dct:license']);
    }

    public function testDistributionSkippedWithoutDownloadUrl(): void
    {
        $this->assertNull($this->service->mapDistribution(['extension' => 'pdf'], null));
    }

    public function testDistributionIriIsStableAcrossCalls(): void
    {
        $file = ['downloadUrl' => 'https://host/d/9/download', 'extension' => 'pdf'];
        $a = $this->service->mapDistribution($file, null);
        $b = $this->service->mapDistribution($file, null);
        $this->assertSame($a['@id'], $b['@id']);
        $this->assertSame('https://host/d/9/download', $a['@id']);
    }

    public function testDatasetIriStableAcrossMappingRuns(): void
    {
        $publication = ['id' => 'u', '@self' => ['uuid' => 'u', 'updated' => '2025-01-01T00:00:00+00:00']];
        $a = $this->service->mapDataset($publication, DcatMappingService::DEFAULT_MAPPING, [], 'https://host/api/cat/u', []);
        $b = $this->service->mapDataset($publication, DcatMappingService::DEFAULT_MAPPING, [], 'https://host/api/cat/u', []);
        $this->assertSame($a['@id'], $b['@id']);
        $this->assertSame($a, $b, 'Identical input must produce byte-identical dataset (harvester dedup).');
    }

    public function testPublisherFallbackChainUsesCatalogOrganisation(): void
    {
        $publication = ['id' => 'u', '@self' => ['uuid' => 'u']];
        $defaults    = ['organisation' => ['title' => 'Gemeente Tilburg', 'oin' => '00000001002564440000']];

        $dataset = $this->service->mapDataset($publication, DcatMappingService::DEFAULT_MAPPING, [], 'https://host/api/cat/u', $defaults);

        $this->assertSame('foaf:Agent', $dataset['dct:publisher']['@type']);
        $this->assertSame('Gemeente Tilburg', $dataset['dct:publisher']['foaf:name']);
        $this->assertSame('00000001002564440000', $dataset['dct:publisher']['@id']);
    }

    public function testPublisherFromObjectWinsOverFallback(): void
    {
        $publication = ['id' => 'u', 'publisher' => 'Gemeente Amsterdam', '@self' => ['uuid' => 'u']];
        $defaults    = ['organisation' => ['title' => 'Fallback Org']];

        $dataset = $this->service->mapDataset($publication, DcatMappingService::DEFAULT_MAPPING, [], 'https://host/api/cat/u', $defaults);

        $this->assertSame('Gemeente Amsterdam', $dataset['dct:publisher']['foaf:name']);
    }

    public function testBuildPublisherReturnsNullWhenNothingResolves(): void
    {
        $this->assertNull($this->service->buildPublisher(null, []));
    }

    public function testContextDeclaresDcatApNlProfile(): void
    {
        $context = $this->service->context();
        $this->assertSame('http://www.w3.org/ns/dcat#', $context['dcat']);
        $this->assertArrayHasKey('profile', $context);
    }
}//end class
