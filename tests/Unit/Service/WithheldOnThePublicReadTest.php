<?php

/**
 * The public read of a Woo decision names what was withheld and why, through both public routes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenCatalogi\Service\Woo\PublicWithheld;
use OCA\OpenCatalogi\Service\Woo\PublicWithheldStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * OpenRegister as the public read sees it: rows per schema, objects by id.
 */
class PublicReadObjectService {

	/** @var array<string, list<array<string, mixed>>> Rows by schema id. */
	public array $rows = [];

	/** @var array<string, array<string, mixed>> Objects by id. */
	public array $objects = [];

	/** @var list<mixed> What searchObjects answers for the publication lookup. */
	public array $publication = [];

	/** @var array<string, mixed> What renderEntity answers. */
	public array $rendered = [];

	public function searchObjectsPaginated(array $query, mixed ...$rest): array {
		$results = [];
		foreach ($this->rows[(string)($query['@self']['schema'] ?? '')] ?? [] as $row) {
			$results[] = ['id' => $row['id'] ?? null, 'object' => $row];
		}

		return ['results' => $results];
	}//end searchObjectsPaginated()

	public function find(string $id, mixed ...$rest): array {
		if (isset($this->objects[$id]) === false) {
			throw new RuntimeException('not found');
		}

		return $this->objects[$id];
	}//end find()

	public function searchObjects(mixed ...$rest): array {
		return $this->publication;
	}//end searchObjects()

	public function renderEntity(mixed ...$rest): array {
		return $this->rendered;
	}//end renderEntity()
}//end class

/**
 * REQ-WDW-003 through `publications#show` and `federation#publication`.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\PublicWithheld
 * @covers \OCA\OpenCatalogi\Service\Woo\PublicWithheldStore
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @covers \OCA\OpenCatalogi\Controller\FederationController
 */
class WithheldOnThePublicReadTest extends TestCase {

	private const CONFIG = [
		'publication_register' => 'reg-pub',
		'withheld_document_schema' => 'sch-withheld',
		'catalog_register' => 'reg-pub',
		'catalog_schema' => 'sch-catalog',
		'woo_register' => 'reg-woo',
		'woo_batch_schema' => 'sch-batch',
	];

	private PublicReadObjectService $objects;

	private PublicWithheld $withheld;

	protected function setUp(): void {
		$this->objects = new PublicReadObjectService();
		// A Woo decision published from dossiq: five documents, two withheld at 3 and 7 on 5.1.2.e.
		$ground = ['code' => '5.1.2.e', 'article' => 'Artikel 5.1, tweede lid, onder e', 'label' => 'Eerbiediging van de persoonlijke levenssfeer'];
		$this->objects->rows['sch-withheld'] = [
			['id' => 'w-7', 'publication' => 'pub-1', 'position' => 7, 'grounds' => [$ground], 'source' => 'dossiq', 'recordedAt' => '2026-10-10T10:00:00+00:00'],
			['id' => 'w-3', 'publication' => 'pub-1', 'position' => 3, 'grounds' => [$ground], 'source' => 'dossiq', 'recordedAt' => '2026-10-10T10:00:00+00:00'],
			['id' => 'w-x', 'publication' => 'pub-2', 'position' => 1, 'grounds' => [$ground], 'source' => 'dossiq', 'recordedAt' => '2026-10-10T10:00:00+00:00'],
		];
		$this->objects->rows['sch-catalog'] = [
			['id' => 'cat-1', 'slug' => 'woo', 'registers' => ['1'], 'schemas' => ['1'], 'showWithheld' => true],
		];
		$this->objects->publication = [$this->publicationEntity()];
		$this->objects->rendered = ['id' => 'pub-1', 'title' => 'Besluit op Woo-verzoek', '@self' => ['id' => 'pub-1', 'register' => '1', 'schema' => '1']];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$this->withheld = new PublicWithheld(
			store: new PublicWithheldStore(config: $config, container: $container, appManager: $appManager),
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * The ObjectEntity the publication lookup finds, in the catalogue's register 1 and schema 1.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function publicationEntity(): \OCA\OpenRegister\Db\ObjectEntity {
		$entity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchema', 'getRegister'])
			->addMethods(['getId'])
			->getMock();
		$entity->method('getId')->willReturn(42);
		$entity->method('getSchema')->willReturn('1');
		$entity->method('getRegister')->willReturn('1');

		return $entity;
	}//end publicationEntity()

	/**
	 * publications#show for a catalogue with or without the opt-in.
	 *
	 * @param bool $optIn Whether the catalogue opted in.
	 *
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function show(bool $optIn): array {
		$catalog = ['slug' => 'woo', 'registers' => [1], 'schemas' => [1]];
		if ($optIn === true) {
			$catalog['showWithheld'] = true;
		}

		$catalogi = $this->createMock(CatalogiService::class);
		$catalogi->method('getCatalogBySlug')->willReturn($catalog);
		$query = $this->createMock(PublicationQueryService::class);
		$query->method('applyCatalogReadRuleGuard')->willReturnCallback(static fn (array $catalog): array => $catalog);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === PublicWithheld::class ? $this->withheld : $this->objects)
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$controller = new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationService::class),
			$catalogi,
			$query,
			$container,
			$appManager,
			new NullLogger(),
			$l10n,
			$this->createMock(UsageCounterService::class),
		);
		$response = $controller->show('woo', 'pub-1');

		return [$response->getStatus(), (array)$response->getData()];
	}//end show()

	/**
	 * federation#publication for the same publication.
	 *
	 * @return array<string, mixed>
	 */
	private function federation(): array {
		$publications = $this->createMock(PublicationService::class);
		$publications->method('getFederatedPublication')->willReturn(['success' => true, 'data' => $this->objects->rendered, 'status' => 200]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$controller = new FederationController('opencatalogi', $request, $publications, $this->createMock(IL10N::class), new NullLogger(), $this->withheld);

		return (array)$controller->publication('pub-1')->getData();
	}//end federation()

	public function testAnOptedInCatalogueShowsTheWithheldDocumentsOfADossiqDecision(): void {
		[$status, $data] = $this->show(optIn: true);

		$this->assertSame(200, $status);
		$this->assertSame([3, 7], array_column($data['withheld'], 'position'));
		foreach ($data['withheld'] as $entry) {
			$this->assertSame(['position', 'grounds', 'groundDetails'], array_keys($entry), 'no title, file, hash or reference');
			$this->assertSame(['5.1.2.e'], $entry['grounds']);
			$this->assertSame('Eerbiediging van de persoonlijke levenssfeer', $entry['groundDetails'][0]['label']);
			$this->assertSame('Artikel 5.1, tweede lid, onder e', $entry['groundDetails'][0]['article']);
		}
	}//end testAnOptedInCatalogueShowsTheWithheldDocumentsOfADossiqDecision()

	public function testWithoutOptInThereIsNoWithheldKeyEvenWithStoredEntries(): void {
		[$status, $data] = $this->show(optIn: false);

		$this->assertSame(200, $status);
		$this->assertArrayNotHasKey('withheld', $data);
	}//end testWithoutOptInThereIsNoWithheldKeyEvenWithStoredEntries()

	public function testTheFederationEndpointCarriesTheSameEntries(): void {
		[, $shown] = $this->show(optIn: true);

		$this->assertSame($shown['withheld'], $this->federation()['withheld']);
	}//end testTheFederationEndpointCarriesTheSameEntries()

	public function testTheFederationEndpointSaysNothingWhenACatalogueHoldingItDidNotOptIn(): void {
		$this->objects->rows['sch-catalog'][] = ['id' => 'cat-2', 'registers' => ['1'], 'schemas' => ['1'], 'showWithheld' => false];
		$this->assertArrayNotHasKey('withheld', $this->federation());

		$this->objects->rows['sch-catalog'] = [];
		$this->assertArrayNotHasKey('withheld', $this->federation(), 'an unresolved catalogue gives no key');
	}//end testTheFederationEndpointSaysNothingWhenACatalogueHoldingItDidNotOptIn()

	public function testANonPublicPublicationShowsNothing(): void {
		// OpenRegister's RBAC hides a publication that is not public from the anonymous lookup.
		$this->objects->publication = [];

		[$status, $data] = $this->show(optIn: true);

		$this->assertSame(404, $status);
		$this->assertArrayNotHasKey('withheld', $data);
	}//end testANonPublicPublicationShowsNothing()

	public function testAnUnreadableListGivesNoKeyRatherThanAnEmptyOne(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$this->withheld = new PublicWithheld(
			store: new PublicWithheldStore(config: $config, container: $this->createMock(ContainerInterface::class), appManager: $this->createMock(IAppManager::class)),
			logger: new NullLogger(),
		);

		[$status, $data] = $this->show(optIn: true);

		$this->assertSame(200, $status);
		$this->assertArrayNotHasKey('withheld', $data);
	}//end testAnUnreadableListGivesNoKeyRatherThanAnEmptyOne()
}//end class
