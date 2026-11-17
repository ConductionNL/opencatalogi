<?php

/**
 * The configured published collections at the public API read (REQ-PIN-101, REQ-PIN-108).
 *
 * An anonymous read of a record whose type a published collection covers
 * answers only that collection's anonymous set, and nothing when the record
 * fails the collection's conditions. A signed-in read is unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\PublishedRecordView;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Unit\Support\ServerAwareRequest;

/**
 * Tests for the published-collection view on PublicationsController::show() and index().
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishedRecordView
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeIds
 * @uses   \OCA\OpenCatalogi\Contract\PublicationUsage
 * @uses   \OCA\OpenCatalogi\Controller\CatalogSlugQuery
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses   \OCA\OpenCatalogi\Controller\FoundPublication
 * @uses   \OCA\OpenCatalogi\Controller\PublicationRead
 */
class PublicationsPublishedCollectionsTest extends TestCase {

	private const UUID = '00000000-0000-0000-0000-00000000000a';

	/**
	 * The ObjectService double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Whether the caller is anonymous.
	 *
	 * @var boolean
	 */
	private bool $anonymous = true;

	/**
	 * The record OpenRegister renders.
	 *
	 * @var array<string, mixed>
	 */
	private array $record = [];

	/**
	 * The controller under test.
	 *
	 * @var PublicationsController
	 */
	private PublicationsController $controller;

	/**
	 * Wire the controller to a decision collection on schema 1 that publishes only final records.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->record = [
			'id' => self::UUID,
			'title' => 'Besluit geluid',
			'status' => 'final',
			'citizenServiceNumber' => '123456782',
			'@self' => ['id' => self::UUID, 'register' => 1, 'schema' => 1],
		];

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('renderEntity')->willReturnCallback(fn (): array => $this->record);
		$this->objectService->method('searchObjects')->willReturnCallback(
			fn (array $query): array => ($query['@self']['uuid'] ?? null) === self::UUID ? [$this->entity()] : []
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === PublishedCollectionsService::CONFIG_KEY
				? (string)json_encode(
					[
						[
							'recordType' => '1',
							'anonymousProperties' => ['title'],
							'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'final']],
						],
					]
				)
				: $default
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$rules = new PublicationRuleService();
		$view = new PublishedRecordView(
			new PublishedCollectionsService($config, $rules, 'opencatalogi'),
			$rules,
			new OpenRegisterLookup($container, $appManager)
		);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => $id === PublishedRecordViewInterface::class ? $view : $this->objectService
		);

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applyCatalogReadRuleGuard')->willReturnArgument(0);
		$queryService->method('isAnonymous')->willReturnCallback(fn (): bool => $this->anonymous);
		$queryService->method('buildCatalogSearchQuery')->willReturn(['@self' => ['register' => 1, 'schema' => 1]]);
		$queryService->method('stripEmptyValues')->willReturnArgument(0);
		$queryService->method('resolveSchemaAndRegisterObjects')->willReturn(['schemas' => [], 'registers' => []]);

		$catalogi = $this->createMock(CatalogiService::class);
		$catalogi->method('getCatalogBySlug')->willReturn(['title' => 'Woo', 'slug' => 'woo', 'schemas' => [1], 'registers' => [1]]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$request = $this->createMock(ServerAwareRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->server = [];

		$this->controller = new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationService::class),
			$catalogi,
			$queryService,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$l10n,
			$this->createMock(UsageCounterService::class),
			$config
		);

	}//end setUp()

	/**
	 * The stored publication as an entity double.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(): ObjectEntity {
		$mock = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchema', 'getRegister', 'getUuid'])
			->addMethods(['getId'])
			->getMock();
		$mock->method('getId')->willReturn(42);
		$mock->method('getUuid')->willReturn(self::UUID);
		$mock->method('getSchema')->willReturn('1');
		$mock->method('getRegister')->willReturn('1');

		return $mock;

	}//end entity()

	/**
	 * An anonymous read answers only the collection's anonymous set.
	 *
	 * @return void
	 */
	public function testAnAnonymousReadOmitsAPropertyOutsideTheSet(): void {
		$response = $this->controller->show('woo', self::UUID);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['id' => self::UUID, '@self' => ['id' => self::UUID, 'register' => 1, 'schema' => 1], 'title' => 'Besluit geluid', 'relations' => []],
			$response->getData()
		);

	}//end testAnAnonymousReadOmitsAPropertyOutsideTheSet()

	/**
	 * A record that fails the collection's condition is not found by an anonymous reader.
	 *
	 * @return void
	 */
	public function testARecordFailingTheConditionIsNotPublic(): void {
		$this->record['status'] = 'draft';

		$this->assertSame(404, $this->controller->show('woo', self::UUID)->getStatus());

	}//end testARecordFailingTheConditionIsNotPublic()

	/**
	 * A signed-in reader gets the rendered publication unchanged.
	 *
	 * @return void
	 */
	public function testASignedInReadIsUnchanged(): void {
		$this->anonymous = false;
		$this->record['status'] = 'draft';

		$response = $this->controller->show('woo', self::UUID);

		$this->assertSame(200, $response->getStatus());
		// The detail answer also carries the relations a reader may see (REQ-PRS-002); none here.
		$this->assertSame($this->record + ['relations' => []], $response->getData());

	}//end testASignedInReadIsUnchanged()

	/**
	 * An anonymous list drops what the collection does not publish, projects the rest, and lowers the total.
	 *
	 * @return void
	 */
	public function testAnAnonymousListKeepsOnlyWhatPublishes(): void {
		$draft = ['status' => 'draft', 'id' => 'b'] + $this->record;
		$final = ['id' => 'a'] + $this->record;
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [$final, $draft], 'total' => 7]);

		$data = $this->controller->index('woo')->getData();

		$this->assertSame(['a'], array_column($data['results'], 'id'));
		$this->assertArrayNotHasKey('citizenServiceNumber', $data['results'][0]);
		$this->assertSame(6, $data['total']);

	}//end testAnAnonymousListKeepsOnlyWhatPublishes()
}//end class
