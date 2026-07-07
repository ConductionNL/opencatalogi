<?php
/**
 * Unit tests for DcatService (the methods that do not require OpenRegister).
 *
 * Covers DCAT enablement, default resolution (catalog override → app-config),
 * stable IRI/endpoint construction (harvester dedup), and the mandatory-property
 * validation checklist. The OR-backed document builders are exercised through the
 * Newman API collection against a live instance.
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
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DcatService.
 */
class DcatServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private IURLGenerator|MockObject $urlGenerator;
    private IAppConfig|MockObject $appConfig;
    private DcatService $service;

    protected function setUp(): void
    {
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);

        $this->urlGenerator->method('getBaseUrl')->willReturn('https://host');

        $this->service = new DcatService(
            $this->container,
            $this->appManager,
            new DcatMappingService(new \OCA\OpenCatalogi\Service\DcatVocabularyService()),
            new DcatSerializer(),
            $this->urlGenerator,
            $this->appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testIsDcatEnabled(): void
    {
        $this->assertTrue($this->service->isDcatEnabled(['hasDcat' => true]));
        $this->assertTrue($this->service->isDcatEnabled(['hasDcat' => 'true']));
        $this->assertFalse($this->service->isDcatEnabled(['hasDcat' => false]));
        $this->assertFalse($this->service->isDcatEnabled([]));
    }

    public function testCatalogEndpointUrlIsAbsoluteAndStable(): void
    {
        $a = $this->service->catalogEndpointUrl('woo-besluiten');
        $b = $this->service->catalogEndpointUrl('woo-besluiten');
        $this->assertSame('https://host/apps/opencatalogi/api/catalogs/woo-besluiten/dcat', $a);
        $this->assertSame($a, $b);
    }

    public function testDatasetIriIsStable(): void
    {
        $a = $this->service->datasetIri('woo', 'uuid-1');
        $b = $this->service->datasetIri('woo', 'uuid-1');
        $this->assertSame('https://host/apps/opencatalogi/api/woo/uuid-1', $a);
        $this->assertSame($a, $b);
    }

    public function testResolveDefaultsPrefersCatalogOverrideOverAppConfig(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function ($app, $key, $default='') {
                return $default;
            }
        );

        $defaults = $this->service->resolveDefaults(
            [
                'dcatPublisherName' => 'Gemeente Tilburg',
                'dcatLicense'       => 'https://custom-license',
            ]
        );

        $this->assertSame('Gemeente Tilburg', $defaults['publisherName']);
        $this->assertSame('https://custom-license', $defaults['license']);
    }

    public function testResolveDefaultsFallsBackToAppConfig(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function ($app, $key, $default='') {
                $map = [
                    'dcat_publisher_name'  => 'Instance Org',
                    'dcat_default_license' => 'https://cc0',
                ];
                return ($map[$key] ?? $default);
            }
        );

        $defaults = $this->service->resolveDefaults([]);
        $this->assertSame('Instance Org', $defaults['publisherName']);
        $this->assertSame('https://cc0', $defaults['license']);
    }

    public function testMandatoryViolationsDetectsMissingPublisher(): void
    {
        $node = [
            '@id'             => 'https://host/api/woo/u1',
            '@type'           => 'dcat:Dataset',
            'dct:title'       => 'Has a title',
            'dcat:landingPage' => ['@id' => 'https://host/api/woo/u1'],
        ];
        $this->assertSame(['dct:publisher'], $this->service->mandatoryViolations($node));
    }

    public function testMandatoryViolationsEmptyForCompliantDataset(): void
    {
        $node = [
            '@id'             => 'https://host/api/woo/u1',
            '@type'           => 'dcat:Dataset',
            'dct:title'       => 'Title',
            'dct:publisher'   => ['@type' => 'foaf:Agent', 'foaf:name' => 'Org'],
            'dcat:landingPage' => ['@id' => 'https://host/api/woo/u1'],
        ];
        $this->assertSame([], $this->service->mandatoryViolations($node));
    }
}//end class
