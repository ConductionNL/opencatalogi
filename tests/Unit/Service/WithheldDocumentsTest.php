<?php

/**
 * A catalogue that opted in lists the withheld documents of a batch publication, with grounds only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
 */

declare(strict_types=1);

namespace Unit\Service;

require_once __DIR__ . '/WithheldOnThePublicReadTest.php';

use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-PDP-004 (and the batch half of REQ-WDW-003) on the service.
 *
 * @covers \OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication
 * @covers \OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore
 */
class WithheldDocumentsTest extends TestCase {

	private const CONFIG = [
		'publication_register' => 'reg-pub',
		'withheld_document_schema' => 'sch-withheld',
		'catalog_register' => 'reg-pub',
		'catalog_schema' => 'sch-catalog',
		'woo_register' => 'reg-woo',
		'woo_batch_schema' => 'sch-batch',
	];

	private PublicReadObjectService $objects;

	protected function setUp(): void {
		$this->objects = new PublicReadObjectService();
		// A batch of three documents published as pub-1: the second withheld, the third withheld with a public title.
		$this->objects->rows['sch-batch'] = [
			['id' => 'b-other', 'documents' => ['a-9'], 'wooPublication' => ['publication' => 'pub-9']],
			['id' => 'b-1', 'documents' => ['a-1', 'a-2', 'a-3'], 'wooPublication' => ['publication' => 'pub-1']],
		];
		$this->objects->objects = [
			'a-1' => ['id' => 'a-1', 'assessment' => 'openbaar', 'fileName' => 'nota.pdf'],
			'a-2' => [
				'id' => 'a-2',
				'assessment' => 'niet_openbaar',
				'weigeringsgronden' => ['5.1.2.e'],
				'fileName' => 'brief-aan-burger.pdf',
				'documentReference' => 'files/42',
				'anonymizedDocumentHash' => str_repeat('a', 64),
				'redactionInstructions' => 'alles',
			],
			'a-3' => ['id' => 'a-3', 'assessment' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.1.c', '9.9.9'], 'fileName' => 'offerte.pdf', 'titlePublic' => true],
			'a-9' => ['id' => 'a-9', 'assessment' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e'], 'fileName' => 'x.pdf'],
		];
	}//end setUp()

	private function service(): WithheldFromPublication {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new WithheldFromPublication(
			store: new WithheldFromPublicationStore(config: $config, container: $container, appManager: $appManager),
			logger: new NullLogger(),
		);
	}//end service()

	public function testAnOptedInCatalogueListsWithheldDocumentsWithGroundsOnly(): void {
		$response = $this->service()->addForCatalog(response: ['id' => 'pub-1'], catalog: ['showWithheld' => true], publicationId: 'pub-1');

		$this->assertSame(
			[
				['position' => 2, 'grounds' => ['5.1.2.e'], 'groundDetails' => [['code' => '5.1.2.e', 'article' => '', 'label' => '']]],
				[
					'position' => 3,
					'grounds' => ['5.1.1.c', '9.9.9'],
					'groundDetails' => [['code' => '5.1.1.c', 'article' => '', 'label' => ''], ['code' => '9.9.9', 'article' => '', 'label' => '']],
					'title' => 'offerte.pdf',
				],
			],
			$response['withheld']
		);
	}//end testAnOptedInCatalogueListsWithheldDocumentsWithGroundsOnly()

	public function testWithoutOptInThereIsNoWithheldKey(): void {
		$service = $this->service();

		$this->assertArrayNotHasKey('withheld', $service->addForCatalog(response: [], catalog: [], publicationId: 'pub-1'));
		$this->assertArrayNotHasKey('withheld', $service->addForCatalog(response: [], catalog: ['showWithheld' => 'true'], publicationId: 'pub-1'));
		$this->assertArrayNotHasKey('withheld', $service->addForCatalog(response: [], catalog: ['showWithheld' => false], publicationId: 'pub-1'));
	}//end testWithoutOptInThereIsNoWithheldKey()

	public function testAnUnresolvableCatalogueGivesNoKey(): void {
		$service = $this->service();
		$this->objects->rows['sch-catalog'] = [];

		$this->assertArrayNotHasKey('withheld', $service->addForPublication(response: ['id' => 'pub-1', '@self' => ['register' => '1', 'schema' => '1']]));
		$this->assertArrayNotHasKey('withheld', $service->addForPublication(response: ['id' => 'pub-1']));
	}//end testAnUnresolvableCatalogueGivesNoKey()

	public function testNoFileOrHashIsEverReturned(): void {
		$json = json_encode($this->service()->entries(publicationId: 'pub-1'), JSON_THROW_ON_ERROR);

		foreach (['brief-aan-burger', 'files/42', str_repeat('a', 64), 'alles', 'documentReference', 'anonymizedDocument', 'redaction'] as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $json);
		}
	}//end testNoFileOrHashIsEverReturned()

	public function testBatchAndStoredEntriesAreMergedByPosition(): void {
		$this->objects->rows['sch-withheld'] = [
			['id' => 'w-1', 'publication' => 'pub-1', 'position' => 1, 'grounds' => [['code' => '5.2.1', 'article' => 'Artikel 5.2', 'label' => 'Persoonlijke beleidsopvattingen']]],
		];

		$this->assertSame([1, 2, 3], array_column($this->service()->entries(publicationId: 'pub-1'), 'position'));
	}//end testBatchAndStoredEntriesAreMergedByPosition()

	public function testAPublicationWithoutABatchHasOnlyItsStoredEntries(): void {
		$this->assertSame([], $this->service()->entries(publicationId: 'pub-none'));
	}//end testAPublicationWithoutABatchHasOnlyItsStoredEntries()
}//end class
