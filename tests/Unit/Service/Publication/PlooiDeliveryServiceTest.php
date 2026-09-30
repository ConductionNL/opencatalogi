<?php

/**
 * Tests for the PLOOI delivery of a publication that turned public.
 *
 * The fields written back onto the publication are validated against the real
 * `publication` fragment in lib/Settings/publication_register.json, so a value
 * the schema refuses fails here and not only on a live instance.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService
 */
class PlooiDeliveryServiceTest extends TestCase {

	private MockObject&SettingsService $settings;

	private MockObject&ObjectService $objects;

	private MockObject&NationalIndexService $index;

	/** @var array<string, mixed>|null */
	private ?array $saved = null;

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
		$this->settings->method('getSettings')->willReturn(
			['configuration' => ['catalog_register' => '7', 'catalog_schema' => '3']]
		);

		$publication = new ObjectEntity();
		$publication->setUuid('pub-1');
		$publication->setRegister('7');
		$publication->setSchema('12');
		$publication->setObject(['title' => 'Besluit', 'summary' => 'Kort', 'publicationDate' => '2026-09-01T00:00:00+00:00']);
		$this->objects->method('find')->willReturn($publication);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($publication): ObjectEntity {
				$this->saved = $object;
				return $publication;
			}
		);
	}

	private function catalogs(array $catalogs): void {
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($catalogs): array {
				$this->assertTrue($query['plooiDelivery']);
				return ['results' => $catalogs, 'total' => count($catalogs)];
			}
		);
	}

	private function service(): PlooiDeliveryService {
		return new PlooiDeliveryService(
			settingsService: $this->settings,
			indexService: $this->index,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Validate the written PLOOI fields against the real publication fragment.
	 *
	 * @param array<string, mixed> $fields The fields.
	 */
	private function assertTheSchemaAccepts(array $fields): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/publication_register.json'), true);
		$properties = $register['components']['schemas']['publication']['properties'];
		$schema = ['type' => 'object', 'properties' => []];
		foreach (array_keys($fields) as $key) {
			$this->assertArrayHasKey($key, $properties, 'The publication schema declares no "' . $key . '".');
			$schema['properties'][$key] = $properties[$key];
		}

		$result = (new Validator())->validate(json_decode((string)json_encode($fields)), (string)json_encode($schema));
		$this->assertTrue($result->isValid(), 'The publication schema refuses the PLOOI fields.');
	}

	/** REQ-WND-003: a publication is delivered and its identifier stored. */
	public function testAPublicationIsDeliveredAndTheIdentifierStored(): void {
		$this->catalogs([['registers' => ['7'], 'schemas' => ['12'], 'plooiDelivery' => true]]);
		$this->index->expects($this->once())->method('deliverToPlooi')
			->with($this->callback(fn (array $document): bool => $document['identifier'] === 'pub-1' && $document['title'] === 'Besluit'))
			->willReturn(['answer' => '{"id":"plooi-42"}', 'identifier' => 'plooi-42']);

		$result = $this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12');

		$this->assertSame('delivered', $result['plooiStatus']);
		$this->assertSame('plooi-42', $this->saved['plooiIdentifier']);
		$this->assertSame('Besluit', $this->saved['title']);
		$this->assertTheSchemaAccepts(array_intersect_key($this->saved, array_flip(PlooiDeliveryService::FIELDS)));
	}

	/** REQ-WND-003: PLOOI refuses; the publication records the failure with the reason. */
	public function testARefusalIsStoredAsFailedWithTheReason(): void {
		$this->catalogs([['registers' => [7], 'schemas' => [12], 'plooiDelivery' => true]]);
		$this->index->method('deliverToPlooi')->willThrowException(new IndexUnreachableException('The channel "plooi" answered HTTP 422.'));

		$result = $this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12');

		$this->assertSame('failed', $result['plooiStatus']);
		$this->assertStringContainsString('422', $this->saved['plooiReason']);
		$this->assertTheSchemaAccepts(array_intersect_key($this->saved, array_flip(PlooiDeliveryService::FIELDS)));
	}

	/** A publication in no catalogue with PLOOI delivery on is left alone. */
	public function testAPublicationOutsideAPlooiCatalogueIsNotDelivered(): void {
		$this->catalogs([['registers' => ['7'], 'schemas' => ['99'], 'plooiDelivery' => true]]);
		$this->index->expects($this->never())->method('deliverToPlooi');

		$this->assertNull($this->service()->deliver(uuid: 'pub-1', register: '7', schema: '12'));
		$this->assertNull($this->saved);
	}

	/** The catalogue flag exists in the real catalog fragment and is off by default. */
	public function testTheCatalogueFlagIsOffByDefault(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/publication_register.json'), true);
		$flag = $register['components']['schemas']['catalog']['properties']['plooiDelivery'] ?? null;
		$this->assertSame('boolean', $flag['type'] ?? null);
		$this->assertFalse($flag['default'] ?? null);
	}
}
