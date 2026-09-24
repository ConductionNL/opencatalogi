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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
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
class DcatServiceTest extends TestCase {

	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private IURLGenerator|MockObject $urlGenerator;
	private IAppConfig|MockObject $appConfig;
	private DcatService $service;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

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

	public function testIsDcatEnabled(): void {
		$this->assertTrue($this->service->isDcatEnabled(['hasDcat' => true]));
		$this->assertTrue($this->service->isDcatEnabled(['hasDcat' => 'true']));
		$this->assertFalse($this->service->isDcatEnabled(['hasDcat' => false]));
		$this->assertFalse($this->service->isDcatEnabled([]));
	}

	public function testCatalogEndpointUrlIsAbsoluteAndStable(): void {
		$a = $this->service->catalogEndpointUrl('woo-besluiten');
		$b = $this->service->catalogEndpointUrl('woo-besluiten');
		$this->assertSame('https://host/apps/opencatalogi/api/catalogs/woo-besluiten/dcat', $a);
		$this->assertSame($a, $b);
	}

	public function testDatasetIriIsStable(): void {
		$a = $this->service->datasetIri('woo', 'uuid-1');
		$b = $this->service->datasetIri('woo', 'uuid-1');
		$this->assertSame('https://host/apps/opencatalogi/api/woo/uuid-1', $a);
		$this->assertSame($a, $b);
	}

	public function testResolveDefaultsPrefersCatalogOverrideOverAppConfig(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function ($app, $key, $default = '') {
				return $default;
			}
		);

		$defaults = $this->service->resolveDefaults(
			[
				'dcatPublisherName' => 'Gemeente Tilburg',
				'dcatLicense' => 'https://custom-license',
			]
		);

		$this->assertSame('Gemeente Tilburg', $defaults['publisherName']);
		$this->assertSame('https://custom-license', $defaults['license']);
	}

	public function testResolveDefaultsFallsBackToAppConfig(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function ($app, $key, $default = '') {
				$map = [
					'dcat_publisher_name' => 'Instance Org',
					'dcat_default_license' => 'https://cc0',
				];
				return ($map[$key] ?? $default);
			}
		);

		$defaults = $this->service->resolveDefaults([]);
		$this->assertSame('Instance Org', $defaults['publisherName']);
		$this->assertSame('https://cc0', $defaults['license']);
	}

	public function testMandatoryViolationsDetectsMissingPublisher(): void {
		$node = [
			'@id' => 'https://host/api/woo/u1',
			'@type' => 'dcat:Dataset',
			'dct:title' => 'Has a title',
			'dcat:landingPage' => ['@id' => 'https://host/api/woo/u1'],
		];
		$this->assertSame(['dct:publisher'], $this->service->mandatoryViolations($node));
	}

	public function testMandatoryViolationsEmptyForCompliantDataset(): void {
		$node = [
			'@id' => 'https://host/api/woo/u1',
			'@type' => 'dcat:Dataset',
			'dct:title' => 'Title',
			'dct:publisher' => ['@type' => 'foaf:Agent', 'foaf:name' => 'Org'],
			'dcat:landingPage' => ['@id' => 'https://host/api/woo/u1'],
		];
		$this->assertSame([], $this->service->mandatoryViolations($node));
	}

	/**
	 * Wire OpenRegister's ObjectService, FileService and SchemaMapper into the
	 * container, and return the ObjectService mock so a test can pin the search.
	 *
	 * @return ObjectService|MockObject The ObjectService mock.
	 */
	private function wireOpenRegister(): ObjectService|MockObject {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn ($app, $key, $default = '') => $default
		);

		$objectService = $this->createMock(ObjectService::class);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturn([]);
		$fileService->method('formatFiles')->willReturn(['results' => []]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('not needed'));

		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				'OCA\\OpenRegister\\Service\\FileService' => $fileService,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
			}
		);

		return $objectService;
	}//end wireOpenRegister()

	/**
	 * WOO-581: a catalog whose schema list is empty — nothing configured, or all
	 * of it dropped by the SCH-PFTS-CAT-002 guard in DcatController — renders a
	 * VALID catalog with zero datasets and never searches. An empty
	 * `@self.schema` must not be read as "every schema in the register".
	 *
	 * @return void
	 */
	public function testBuildCatalogDocumentWithAnEmptySchemaScopeIsAnEmptyCatalogWithoutSearching(): void {
		$objectService = $this->wireOpenRegister();
		$objectService->expects($this->never())->method('searchObjectsPaginated');

		$document = $this->service->buildCatalogDocument(
			catalog: ['id' => 'c1', 'title' => 'WOO', 'registers' => [20], 'schemas' => []],
			catalogSlug: 'woo'
		);

		$this->assertSame(0, $document['_meta']['count']);
		$this->assertFalse($document['_meta']['hasNext']);
		$this->assertSame('dcat:Catalog', $document['@graph'][0]['@type']);
		$this->assertSame([], $document['@graph'][0]['dcat:dataset']);
		$this->assertCount(1, $document['@graph'], 'alleen de catalogus-node, geen datasets');
	}//end testBuildCatalogDocumentWithAnEmptySchemaScopeIsAnEmptyCatalogWithoutSearching()

	/**
	 * Negative control: a non-empty scope still searches exactly those schemas,
	 * with RBAC on, so the fail-closed branch cannot be why a working feed goes
	 * quiet.
	 *
	 * @return void
	 */
	public function testBuildCatalogDocumentSearchesExactlyTheCatalogSchemaScope(): void {
		$objectService = $this->wireOpenRegister();
		$objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(static fn (array $q): bool => $q['@self']['schema'] === [28, 29] && $q['@self']['register'] === 20),
				true
			)
			->willReturn([
				'results' => [['@self' => ['uuid' => 'u1', 'schema' => 28, 'updated' => '2026-01-01T00:00:00+00:00'], 'id' => 'u1', 'title' => 'Besluit']],
				'next' => null,
			]);

		$document = $this->service->buildCatalogDocument(
			catalog: ['id' => 'c1', 'title' => 'WOO', 'registers' => [20], 'schemas' => [28, 29]],
			catalogSlug: 'woo'
		);

		$this->assertSame(1, $document['_meta']['count']);
		$this->assertSame([['@id' => 'https://host/apps/opencatalogi/api/woo/u1']], $document['@graph'][0]['dcat:dataset']);
	}//end testBuildCatalogDocumentSearchesExactlyTheCatalogSchemaScope()
}//end class
