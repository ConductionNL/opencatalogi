<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\PublishedRecordView;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PublishedRecordView: the configured published collections at the anonymous read and search.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublishedRecordView
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
 */
class PublishedRecordViewTest extends TestCase {

	/**
	 * The published-collection configuration the fake app config holds.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $installed = true;

	/**
	 * The ObjectService the lookup reaches.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The view under test.
	 *
	 * @var PublishedRecordView
	 */
	private PublishedRecordView $view;

	/**
	 * Wire the view to a fake app config and a mocked ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === PublishedCollectionsService::CONFIG_KEY ? $this->stored : $default
		);

		$this->objects = $this->createMock(ObjectService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'openregister' && $this->installed);

		$rules = new PublicationRuleService();
		$this->view = new PublishedRecordView(
			new PublishedCollectionsService($config, $rules, 'opencatalogi'),
			$rules,
			new OpenRegisterLookup($container, $appManager)
		);

	}//end setUp()

	/**
	 * Configure the published collections.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return void
	 */
	private function configure(array $collections): void {
		$this->stored = (string)json_encode($collections);

	}//end configure()

	/**
	 * The decision collection: schema 12 in register 3, only when `status` is "final".
	 *
	 * @return array<string, mixed> The collection.
	 */
	private function decisions(): array {
		return [
			'recordType' => '12',
			'register' => '3',
			'anonymousProperties' => ['title', 'summary'],
			'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'final']],
		];

	}//end decisions()

	/**
	 * A stored decision.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $properties Properties beside the defaults.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function decision(string $id, array $properties = []): array {
		return array_merge(
			[
				'id' => $id,
				'title' => 'Besluit ' . $id,
				'summary' => 'Geluidsnormen voor het centrum',
				'status' => 'final',
				'citizenServiceNumber' => '123456782',
				'dossier' => ['id' => 'dossier-9', 'title' => 'Geluid centrum'],
				'@self' => ['id' => $id, 'register' => 3, 'schema' => 12],
			],
			$properties
		);

	}//end decision()

	/**
	 * A record of a covered type carries only the anonymous set, its id, `@self` and its dossier.
	 *
	 * @return void
	 */
	public function testACoveredRecordCarriesOnlyItsAnonymousSet(): void {
		$this->configure(collections: [$this->decisions()]);

		$view = $this->view->view(record: $this->decision(id: 'd-1'));

		$this->assertSame(
			[
				'id' => 'd-1',
				'@self' => ['id' => 'd-1', 'register' => 3, 'schema' => 12],
				'title' => 'Besluit d-1',
				'summary' => 'Geluidsnormen voor het centrum',
				'dossier' => ['id' => 'dossier-9', 'title' => 'Geluid centrum'],
			],
			$view
		);
		$this->assertArrayNotHasKey('citizenServiceNumber', $view);

	}//end testACoveredRecordCarriesOnlyItsAnonymousSet()

	/**
	 * A record that fails its collection's condition is not public.
	 *
	 * @return void
	 */
	public function testAConditionNarrowsWhatPublishes(): void {
		$this->configure(collections: [$this->decisions()]);

		$this->assertNull($this->view->view(record: $this->decision(id: 'd-2', properties: ['status' => 'draft'])));

	}//end testAConditionNarrowsWhatPublishes()

	/**
	 * A type no collection covers is answered unchanged.
	 *
	 * @return void
	 */
	public function testAnUncoveredTypeIsUnchanged(): void {
		$this->configure(collections: [$this->decisions()]);
		$record = $this->decision(id: 'd-3', properties: ['@self' => ['id' => 'd-3', 'register' => 3, 'schema' => 40]]);

		$this->assertSame($record, $this->view->view(record: $record));

	}//end testAnUncoveredTypeIsUnchanged()

	/**
	 * A record's own `@type` names its type before its schema does.
	 *
	 * @return void
	 */
	public function testTheRecordsOwnTypeWins(): void {
		$collection = $this->decisions();
		$collection['recordType'] = 'besluit';
		$this->configure(collections: [$collection]);

		$view = $this->view->view(record: $this->decision(id: 'd-4', properties: ['@type' => 'besluit']));

		$this->assertSame(['id', '@self', 'title', 'summary', 'dossier'], array_keys($view));

	}//end testTheRecordsOwnTypeWins()

	/**
	 * A collection added on a running instance governs the next read, without a release.
	 *
	 * @return void
	 */
	public function testACollectionAddedTakesEffectOnTheNextRead(): void {
		$record = $this->decision(id: 'd-5');
		$this->assertSame($record, $this->view->view(record: $record));

		$this->configure(collections: [$this->decisions()]);

		$this->assertArrayNotHasKey('citizenServiceNumber', $this->view->view(record: $record));

	}//end testACollectionAddedTakesEffectOnTheNextRead()

	/**
	 * A list keeps the public records in order and drops the others.
	 *
	 * @return void
	 */
	public function testAListDropsWhatIsNotPublic(): void {
		$this->configure(collections: [$this->decisions()]);

		$views = $this->view->viewList(
			records: [
				$this->decision(id: 'd-6'),
				$this->decision(id: 'd-7', properties: ['status' => 'draft']),
				'not a record',
				$this->decision(id: 'd-8'),
			]
		);

		$this->assertSame(['d-6', 'd-8'], array_column($views, 'id'));

	}//end testAListDropsWhatIsNotPublic()

	/**
	 * An unreadable configuration refuses the read rather than serving the record.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfigurationRefuses(): void {
		$this->stored = '{not json';

		$this->expectException(UnreadableRuleException::class);
		$this->view->view(record: $this->decision(id: 'd-9'));

	}//end testAnUnreadableConfigurationRefuses()

	/**
	 * The search reads each collection's register and schema and returns a match with its dossier.
	 *
	 * @return void
	 */
	public function testTheSearchFindsAStoredDocumentWithItsDossier(): void {
		$this->configure(collections: [$this->decisions()]);
		$this->objects->expects($this->once())
			->method('searchObjects')
			->with(
				['@self' => ['register' => '3', 'schema' => '12'], '_limit' => PublishedRecordView::SEARCH_LIMIT, '_search' => 'Geluidsnormen'],
				true,
				false
			)
			->willReturn([$this->decision(id: 'd-10'), $this->decision(id: 'd-11', properties: ['status' => 'draft'])]);

		$results = $this->view->searchStored(terms: 'Geluidsnormen');

		$this->assertSame(['d-10'], array_column($results, 'id'));
		$this->assertSame(['id' => 'dossier-9', 'title' => 'Geluid centrum'], $results[0]['dossier']);

	}//end testTheSearchFindsAStoredDocumentWithItsDossier()

	/**
	 * A word that occurs only in a withheld property does not return the record.
	 *
	 * @return void
	 */
	public function testTheSearchRespectsTheAnonymousSet(): void {
		$this->configure(collections: [$this->decisions()]);
		// OpenRegister's own search matches the withheld property; the view does not.
		$this->objects->method('searchObjects')->willReturn([$this->decision(id: 'd-12')]);

		$this->assertSame([], $this->view->searchStored(terms: '123456782'));

	}//end testTheSearchRespectsTheAnonymousSet()

	/**
	 * The collection that read a record governs it, whatever type the record names.
	 *
	 * @return void
	 */
	public function testTheReadingCollectionGovernsTheRecord(): void {
		$this->configure(collections: [$this->decisions()]);
		$this->objects->method('searchObjects')->willReturn([$this->decision(id: 'd-13', properties: ['@type' => 'other'])]);

		$results = $this->view->searchStored(terms: '');

		$this->assertCount(1, $results);
		$this->assertArrayNotHasKey('citizenServiceNumber', $results[0]);
		$this->assertArrayNotHasKey('status', $results[0]);

	}//end testTheReadingCollectionGovernsTheRecord()

	/**
	 * A collection without a register is not searched, and entities are read as their properties.
	 *
	 * @return void
	 */
	public function testACollectionWithoutARegisterIsNotSearched(): void {
		$withoutRegister = $this->decisions();
		unset($withoutRegister['register']);
		$this->configure(collections: [$withoutRegister]);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame([], $this->view->searchStored(terms: 'Besluit'));

	}//end testACollectionWithoutARegisterIsNotSearched()

	/**
	 * Stored entities are read through their serialised properties.
	 *
	 * @return void
	 */
	public function testStoredEntitiesAreSearched(): void {
		$this->configure(collections: [$this->decisions()]);
		$entity = new class($this->decision(id: 'd-14')) implements \JsonSerializable {
			/**
			 * An entity over a record.
			 *
			 * @param array<string, mixed> $object The record.
			 */
			public function __construct(private readonly array $object) {
			}//end __construct()

			/**
			 * The record.
			 *
			 * @return array<string, mixed> The record.
			 */
			public function jsonSerialize(): array {
				return $this->object;
			}//end jsonSerialize()
		};
		$this->objects->method('searchObjects')->willReturn([$entity]);

		$this->assertSame(['d-14'], array_column($this->view->searchStored(terms: 'besluit d-14'), 'id'));

	}//end testStoredEntitiesAreSearched()

	/**
	 * Without OpenRegister nothing is stored to search.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSearchIsEmpty(): void {
		$this->installed = false;
		$this->configure(collections: [$this->decisions()]);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame([], $this->view->searchStored(terms: 'Besluit'));

	}//end testWithoutOpenRegisterTheSearchIsEmpty()
}//end class
