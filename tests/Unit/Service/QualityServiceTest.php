<?php
/**
 * Unit tests for QualityService.
 *
 * Covers the five MQA dimension sub-scores, the weighted 0–100 total, the
 * missing-property breakdown, the catalog roll-up, and the DQV measurement nodes.
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

use OCA\OpenCatalogi\Service\QualityService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QualityService.
 */
class QualityServiceTest extends TestCase
{

    private IAppConfig|MockObject $appConfig;

    private QualityService $service;


    /**
     * Set up the service with default (EU MQA) weights.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        // Return the passed default for every weight key.
        $this->appConfig->method('getValueInt')->willReturnCallback(
            static fn(string $app, string $key, int $default = 0): int => $default
        );

        $this->service = new QualityService($this->appConfig);

    }//end setUp()


    /**
     * Build a fully-populated dcat:Dataset node.
     *
     * @return array<string, mixed> The node.
     */
    private function fullNode(): array
    {
        return [
            '@id'             => 'https://host/api/woo/u1',
            '@type'           => 'dcat:Dataset',
            'dct:title'       => 'A dataset',
            'dcat:keyword'    => ['open', 'data'],
            'dcat:theme'      => ['@id' => 'http://publications.europa.eu/resource/authority/data-theme/TRAN'],
            'dct:license'     => ['@id' => 'http://creativecommons.org/publicdomain/zero/1.0/'],
            'dct:publisher'   => ['@type' => 'foaf:Agent', 'foaf:name' => 'Gemeente'],
            'dct:modified'    => '2024-01-01T00:00:00+00:00',
            'dct:identifier'  => 'u1',
            'dcat:landingPage' => ['@id' => 'https://host/api/woo/u1'],
            'dcat:distribution' => [
                [
                    '@type'            => 'dcat:Distribution',
                    'dcat:downloadURL' => ['@id' => 'https://host/f/a.csv'],
                    'dcat:mediaType'   => 'text/csv',
                    'dct:format'       => ['@id' => 'http://publications.europa.eu/resource/authority/file-type/CSV'],
                ],
            ],
        ];

    }//end fullNode()


    /**
     * A fully-populated dataset scores in the high band with all dimensions non-zero.
     *
     * @return void
     */
    public function testFullyPopulatedScoresHigh(): void
    {
        $score = $this->service->scoreDataset($this->fullNode());

        foreach (QualityService::DIMENSIONS as $dimension) {
            $this->assertGreaterThan(0, $score['dimensions'][$dimension], "dimension $dimension should be non-zero");
        }

        $this->assertGreaterThanOrEqual(76, $score['total']);
        $this->assertLessThanOrEqual(100, $score['total']);
        $this->assertSame([], $score['missing']);

    }//end testFullyPopulatedScoresHigh()


    /**
     * Missing license + theme reduce reusability + findability and are named.
     *
     * @return void
     */
    public function testMissingLicenseAndThemePenaliseRightDimensions(): void
    {
        $node = $this->fullNode();
        unset($node['dct:license'], $node['dcat:theme']);

        $full     = $this->service->scoreDataset($this->fullNode());
        $penalised = $this->service->scoreDataset($node);

        $this->assertLessThan($full['dimensions']['reusability'], $penalised['dimensions']['reusability']);
        $this->assertLessThan($full['dimensions']['findability'], $penalised['dimensions']['findability']);
        $this->assertContains('dct:license', $penalised['missing']);
        $this->assertContains('dcat:theme', $penalised['missing']);

    }//end testMissingLicenseAndThemePenaliseRightDimensions()


    /**
     * An empty dataset scores zero and names the core missing properties.
     *
     * @return void
     */
    public function testEmptyDatasetScoresZero(): void
    {
        $score = $this->service->scoreDataset(['@id' => 'x', '@type' => 'dcat:Dataset']);

        $this->assertSame(0, $score['total']);
        $this->assertContains('dct:license', $score['missing']);
        $this->assertContains('dcat:distribution', $score['missing']);

    }//end testEmptyDatasetScoresZero()


    /**
     * The roll-up returns the average and the worst-N ascending.
     *
     * @return void
     */
    public function testRollupAverageAndWorst(): void
    {
        $good = $this->fullNode();
        $bad  = ['@id' => 'https://host/api/woo/u2', '@type' => 'dcat:Dataset', 'dct:title' => 'poor'];

        $rollup = $this->service->rollup([$good, $bad], 1);

        $this->assertSame(2, $rollup['count']);
        $this->assertGreaterThan(0, $rollup['average']);
        $this->assertCount(1, $rollup['worst']);
        // Worst entry is the low-scoring one.
        $this->assertSame('https://host/api/woo/u2', $rollup['worst'][0]['iri']);
        $this->assertArrayHasKey('dct:license', $rollup['missingBreakdown']);

    }//end testRollupAverageAndWorst()


    /**
     * DQV produces one measurement per dimension plus the total.
     *
     * @return void
     */
    public function testDqvMeasurements(): void
    {
        $score        = $this->service->scoreDataset($this->fullNode());
        $measurements = $this->service->dqvMeasurements($score);

        // 5 dimensions + total.
        $this->assertCount(6, $measurements);
        foreach ($measurements as $measurement) {
            $this->assertSame('dqv:QualityMeasurement', $measurement['@type']);
            $this->assertArrayHasKey('dqv:value', $measurement);
        }

    }//end testDqvMeasurements()
}//end class
