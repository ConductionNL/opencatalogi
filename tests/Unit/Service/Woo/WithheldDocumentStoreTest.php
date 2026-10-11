<?php

/**
 * The OpenRegister side of WithheldDocuments (REQ-WDW-002).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

require_once __DIR__ . '/WithheldDocumentsRecordTest.php';

use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Replace touches one publication only; a missing OpenRegister or configuration is said.
 */
class WithheldDocumentStoreTest extends TestCase {

	private WithheldShapedObjectService $objects;

	protected function setUp(): void {
		$this->objects = new WithheldShapedObjectService();
		$this->objects->publications = ['pub-1'];
	}//end setUp()

	private function store(bool $installed = true, string $configured = '7'): WithheldDocumentStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configured);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		return new WithheldDocumentStore($config, $container, $appManager, new NullLogger());
	}//end store()

	public function testAReplaceLeavesAnotherPublicationsEntries(): void {
		$this->objects->rows = [
			'mine' => ['publication' => 'pub-1', 'position' => 1, 'grounds' => [], 'source' => 'dossiq', 'recordedAt' => 'x'],
			'other' => ['publication' => 'pub-2', 'position' => 1, 'grounds' => [], 'source' => 'dossiq', 'recordedAt' => 'x'],
		];

		$this->store()->replace('pub-1', [['position' => 4, 'grounds' => [['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'L']]]], 'dossiq');

		$this->assertArrayNotHasKey('mine', $this->objects->rows);
		$this->assertArrayHasKey('other', $this->objects->rows);
		$this->assertSame([1, 4], array_values(array_map(static fn (array $row): int => $row['position'], $this->objects->rows)));
	}//end testAReplaceLeavesAnotherPublicationsEntries()

	public function testThePublicationIsLookedUp(): void {
		$this->assertTrue($this->store()->publicationExists('pub-1'));
		$this->assertFalse($this->store()->publicationExists('pub-404'));
		$this->assertFalse($this->store()->publicationExists(''));
	}//end testThePublicationIsLookedUp()

	public function testWithoutOpenRegisterNothingExistsAndNothingIsWritten(): void {
		$this->assertFalse($this->store(installed: false)->publicationExists('pub-1'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->store(installed: false)->replace('pub-1', [], 'dossiq');
	}//end testWithoutOpenRegisterNothingExistsAndNothingIsWritten()

	public function testAnUnconfiguredRegisterIsNamed(): void {
		$this->assertFalse($this->store(configured: '')->publicationExists('pub-1'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('withheld_document_schema');
		$this->store(configured: '')->replace('pub-1', [], 'dossiq');
	}//end testAnUnconfiguredRegisterIsNamed()
}//end class
