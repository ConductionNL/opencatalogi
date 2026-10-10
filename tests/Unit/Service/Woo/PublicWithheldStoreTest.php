<?php

/**
 * The OpenRegister reads behind the public withheld list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

require_once __DIR__ . '/../WithheldOnThePublicReadTest.php';

use OCA\OpenCatalogi\Service\Woo\PublicWithheldStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Unit\Service\PublicReadObjectService;

/**
 * One publication's rows, its batch's assessments in order, and the catalogues holding it.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\PublicWithheldStore
 */
class PublicWithheldStoreTest extends TestCase {

	private PublicReadObjectService $objects;

	protected function setUp(): void {
		$this->objects = new PublicReadObjectService();
	}//end setUp()

	/**
	 * @param array<string, string> $config     The app config.
	 * @param bool                  $installed  Whether OpenRegister is installed.
	 */
	private function store(array $config = ['publication_register' => 'r', 'withheld_document_schema' => 'w', 'catalog_register' => 'r', 'catalog_schema' => 'c', 'woo_register' => 'wr', 'woo_batch_schema' => 'b'], bool $installed = true): PublicWithheldStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		return new PublicWithheldStore(config: $appConfig, container: $container, appManager: $appManager);
	}//end store()

	public function testStoredEntriesAreThisPublicationsOnly(): void {
		$this->objects->rows['w'] = [['id' => '1', 'publication' => 'pub-1'], ['id' => '2', 'publication' => 'pub-2']];

		$this->assertSame(['1'], array_column($this->store()->storedEntries(publicationId: 'pub-1'), 'id'));
	}//end testStoredEntriesAreThisPublicationsOnly()

	public function testBatchAssessmentsKeepTheBatchOrderAndAnUnreadableOneKeepsItsPlace(): void {
		$this->objects->rows['b'] = [['id' => 'b-1', 'documents' => ['a-2', 'gone', 'a-1'], 'wooPublication' => ['publication' => 'pub-1']]];
		$this->objects->objects = ['a-1' => ['id' => 'a-1'], 'a-2' => ['id' => 'a-2']];

		$this->assertSame(['a-2', null, 'a-1'], array_map(static fn (array $a): mixed => $a['id'] ?? null, $this->store()->batchAssessments(publicationId: 'pub-1')));
		$this->assertSame([], $this->store()->batchAssessments(publicationId: 'pub-2'));
	}//end testBatchAssessmentsKeepTheBatchOrderAndAnUnreadableOneKeepsItsPlace()

	public function testWithoutAWooRegisterThereIsNoBatch(): void {
		$this->assertSame([], $this->store(config: ['publication_register' => 'r'])->batchAssessments(publicationId: 'pub-1'));
	}//end testWithoutAWooRegisterThereIsNoBatch()

	public function testTheCataloguesHoldingARegisterAndSchemaAreFound(): void {
		$this->objects->rows['c'] = [
			['id' => 'c-1', 'registers' => [1], 'schemas' => [5]],
			['id' => 'c-2', 'registers' => '["1"]', 'schemas' => '["6"]'],
			['id' => 'c-3', 'registers' => null, 'schemas' => [5]],
		];

		$this->assertSame(['c-1'], array_column($this->store()->catalogsHolding(register: '1', schema: '5'), 'id'));
		$this->assertSame(['c-2'], array_column($this->store()->catalogsHolding(register: '1', schema: '6'), 'id'));
	}//end testTheCataloguesHoldingARegisterAndSchemaAreFound()

	public function testAMissingOpenRegisterOrConfigurationIsSaid(): void {
		try {
			$this->store(installed: false)->storedEntries(publicationId: 'pub-1');
			$this->fail('no refusal');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('OpenRegister is not available', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('withheld_document_schema');
		$this->store(config: ['publication_register' => 'r'])->storedEntries(publicationId: 'pub-1');
	}//end testAMissingOpenRegisterOrConfigurationIsSaid()
}//end class
