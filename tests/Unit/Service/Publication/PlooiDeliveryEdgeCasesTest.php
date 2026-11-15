<?php

/**
 * Edge cases of the PLOOI delivery: missing configuration, missing records,
 * catalogue shapes and the document links sent along.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService
 */
class PlooiDeliveryEdgeCasesTest extends TestCase {

	private MockObject&SettingsService $settings;

	private MockObject&ObjectService $objects;

	private MockObject&NationalIndexService $index;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject', 'searchObjectsPaginated'])
			->getMock();
		$this->index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['deliverToPlooi'])
			->getMock();
		$this->settings->method('getObjectService')->willReturn($this->objects);
	}

	private function configured(array $configuration = ['catalog_register' => '7', 'catalog_schema' => '3']): void {
		$this->settings->method('getSettings')->willReturn(['configuration' => $configuration]);
	}

	private function catalogs(array $catalogs): void {
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => $catalogs, 'total' => count($catalogs)]);
	}

	private function service(): PlooiDeliveryService {
		return new PlooiDeliveryService(
			settingsService: $this->settings,
			indexService: $this->index,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	public function testWithoutACatalogueConfigurationNothingIsSearchedOrDelivered(): void {
		$this->configured(['catalog_register' => '7', 'catalog_schema' => '']);
		$this->objects->expects($this->never())->method('searchObjectsPaginated');
		$this->index->expects($this->never())->method('deliverToPlooi');

		$this->assertNull($this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12'));
	}

	public function testACatalogueWithPlooiDeliveryOffIsIgnored(): void {
		$this->configured();
		$this->catalogs([['registers' => ['7'], 'schemas' => ['12'], 'plooiDelivery' => 'yes'], 'not-a-catalogue']);
		$this->index->expects($this->never())->method('deliverToPlooi');

		$this->assertNull($this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12'));
	}

	public function testAPublicationThatNoLongerExistsIsNotDelivered(): void {
		$this->configured();
		$this->catalogs([['registers' => ['7'], 'schemas' => ['12'], 'plooiDelivery' => true]]);
		$this->objects->expects($this->once())->method('find')
			->with('pub-gone', $this->anything(), $this->anything(), '7', '12', false, false)
			->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->index->expects($this->never())->method('deliverToPlooi');

		$this->assertNull($this->service()->deliver(uuid: 'pub-gone', register: '7', schema: '12'));
	}

	public function testACatalogueEntityIsReadAndTheDownloadableFilesTravelAlong(): void {
		$this->configured();
		$catalog = new ObjectEntity();
		$catalog->setObject(['registers' => [7], 'schemas' => [12], 'plooiDelivery' => true]);
		$this->catalogs([$catalog]);

		$publication = $this->getMockBuilder(ObjectEntity::class)->onlyMethods(['jsonSerialize'])->getMock();
		$publication->method('jsonSerialize')->willReturn(
			[
				'title' => 'Besluit',
				'description' => 'Lang',
				'organization' => 'Gemeente X',
				'@self' => [
					'id' => 'pub-1',
					'files' => [
						['title' => 'Besluit.pdf', 'downloadUrl' => 'https://example.nl/d/1'],
						['name' => 'bijlage.pdf', 'downloadUrl' => 'https://example.nl/d/2'],
						['title' => 'Intern', 'downloadUrl' => ''],
						'not-a-file',
					],
				],
			]
		);
		$this->objects->method('find')->willReturn($publication);
		$saved = null;
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved, $publication): ObjectEntity {
				$saved = $object;
				return $publication;
			}
		);

		$sent = null;
		$this->index->expects($this->once())->method('deliverToPlooi')->willReturnCallback(
			function (array $document) use (&$sent): array {
				$sent = $document;
				return ['identifier' => 'plooi-7'];
			}
		);

		$result = $this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12');

		$this->assertSame('delivered', $result['plooiStatus']);
		$this->assertSame('plooi-7', $result['plooiIdentifier']);
		$this->assertSame('Lang', $sent['description']);
		$this->assertSame('Gemeente X', $sent['organisation']);
		$this->assertSame(
			[
				['title' => 'Besluit.pdf', 'url' => 'https://example.nl/d/1'],
				['title' => 'bijlage.pdf', 'url' => 'https://example.nl/d/2'],
			],
			$sent['documents']
		);
		$this->assertArrayNotHasKey('@self', $saved);
		$this->assertSame('Besluit', $saved['title']);
		$this->assertSame('delivered', $saved['plooiStatus']);
	}
}
