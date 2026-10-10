<?php

/**
 * WithheldDocuments::record(), dossiq's door for a decision's withheld documents (REQ-WDW-002).
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

require_once __DIR__ . '/Fixtures/DossiqWooRefusalGrounds.php';

use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\Dossiq\Woo\WooRefusalGroundsUnavailable;
use OCA\OpenCatalogi\Service\Woo\WithheldDocuments;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An in-memory OpenRegister ObjectService with the four calls record() makes.
 */
class WithheldShapedObjectService {

	/** @var array<string, array<string, mixed>> Stored withheldDocument rows by id. */
	public array $rows = [];

	/** @var list<string> Publication ids that exist. */
	public array $publications = [];

	public int $next = 1;

	public function find(string $id, mixed ...$rest): ?array {
		if (in_array($id, $this->publications, true) === false) {
			throw new RuntimeException('not found');
		}

		return ['id' => $id];
	}//end find()

	public function saveObject(array $object, mixed ...$rest): array {
		$id = 'w-' . $this->next++;
		$this->rows[$id] = $object;

		return ['id' => $id, 'object' => $object];
	}//end saveObject()

	public function deleteObject(string $uuid, mixed ...$rest): bool {
		unset($this->rows[$uuid]);

		return true;
	}//end deleteObject()

	public function searchObjectsPaginated(array $query, mixed ...$rest): array {
		$results = [];
		foreach ($this->rows as $id => $row) {
			$results[] = ['id' => $id, 'object' => $row];
		}

		return ['results' => $results];
	}//end searchObjectsPaginated()
}//end class

/**
 * dossiq's list answering with REQ-WRG-007's keys.
 */
class WithheldGroundsDouble extends WooRefusalGrounds {

	public bool $unavailable = false;

	public int $calls = 0;

	public function byCode(string $code): ?array {
		$this->calls++;
		if ($this->unavailable === true) {
			throw new WooRefusalGroundsUnavailable('The Woo refusal grounds cannot be read.');
		}

		if ($code !== '5.1.2.e') {
			return null;
		}

		// dossiq lib/Woo/WooRefusalGrounds.php shape(), lines 212-223 on development.
		return [
			'id' => 'g-1',
			'code' => '5.1.2.e',
			'article' => '5.1',
			'paragraph' => '2',
			'letter' => 'e',
			'label' => 'Eerbiediging van de persoonlijke levenssfeer',
			'description' => '',
			'parent' => null,
			'status' => 'active',
			'legalSource' => 'Woo',
			'kind' => 'ground',
			'citable' => true,
		];
	}//end byCode()
}//end class

/**
 * Every path of record(), on a real class and an in-memory store.
 */
class WithheldDocumentsRecordTest extends TestCase {

	private const PUBLICATION = 'aaaaaaaa-0000-4000-8000-000000000001';

	private WithheldShapedObjectService $objects;

	private WithheldGroundsDouble $grounds;

	/** @var list<string> */
	private array $resolved = [];

	protected function setUp(): void {
		$this->objects = new WithheldShapedObjectService();
		$this->objects->publications = [self::PUBLICATION];
		$this->grounds = new WithheldGroundsDouble();
		$this->resolved = [];
	}//end setUp()

	private function service(bool $dossiq = true): WithheldDocuments {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('7');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->resolved[] = $id;
				if ($id === WithheldDocuments::GROUNDS_CLASS) {
					return $this->grounds;
				}

				return $this->objects;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app !== 'dossiq' || $dossiq
		);

		return new WithheldDocuments(
			new WithheldDocumentStore($config, $container, $appManager, new NullLogger()),
			$container,
			$appManager
		);
	}//end service()

	/**
	 * @return list<array<string, mixed>>
	 */
	private function stored(): array {
		$rows = array_values($this->objects->rows);
		usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

		return $rows;
	}//end stored()

	public function testTwoEntriesAreRecordedWithDossiqsLabels(): void {
		$answer = $this->service()->record(
			self::PUBLICATION,
			[['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 7, 'grounds' => ['5.1.2.e']]],
			'dossiq'
		);

		$this->assertSame(['recorded' => 2, 'refused' => []], $answer);
		$stored = $this->stored();
		$this->assertSame([3, 7], array_column($stored, 'position'));
		foreach ($stored as $row) {
			$this->assertSame([['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'Eerbiediging van de persoonlijke levenssfeer']], $row['grounds']);
			$this->assertSame(self::PUBLICATION, $row['publication']);
			$this->assertSame('dossiq', $row['source']);
			$this->assertNotEmpty($row['recordedAt']);
		}
	}//end testTwoEntriesAreRecordedWithDossiqsLabels()

	public function testExtraKeysAreNotStored(): void {
		$this->service()->record(
			self::PUBLICATION,
			[['position' => 3, 'grounds' => ['5.1.2.e'], 'fileId' => 81, 'sha256' => str_repeat('a', 64), 'documentRef' => 'doc-1']],
			'dossiq'
		);

		$this->assertSame(['publication', 'position', 'grounds', 'source', 'recordedAt'], array_keys($this->stored()[0]));
	}//end testExtraKeysAreNotStored()

	public function testATitleIsNeverStored(): void {
		$answer = $this->service()->record(
			self::PUBLICATION,
			[['position' => 3, 'grounds' => ['5.1.2.e'], 'title' => 'Advies over de locatiekeuze']],
			'dossiq'
		);

		$this->assertSame(1, $answer['recorded']);
		$this->assertArrayNotHasKey('title', $this->stored()[0]);
		$this->assertStringNotContainsString('locatiekeuze', json_encode($this->objects->rows, JSON_THROW_ON_ERROR));
	}//end testATitleIsNeverStored()

	public function testAnUnknownGroundRefusesOnlyItsEntry(): void {
		$answer = $this->service()->record(
			self::PUBLICATION,
			[['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 4, 'grounds' => ['5.2.5']]],
			'dossiq'
		);

		$this->assertSame(1, $answer['recorded']);
		$this->assertSame([['position' => 4, 'code' => '5.2.5', 'reason' => 'unknown-ground']], $answer['refused']);
		$this->assertSame([3], array_column($this->stored(), 'position'));
	}//end testAnUnknownGroundRefusesOnlyItsEntry()

	public function testWithoutDossiqEveryEntryIsRefusedAndTheSnapshotIsNotRead(): void {
		$this->objects->rows = ['old' => ['publication' => self::PUBLICATION, 'position' => 9, 'grounds' => [], 'source' => 'dossiq', 'recordedAt' => 'x']];

		$answer = $this->service(dossiq: false)->record(self::PUBLICATION, [['position' => 3, 'grounds' => ['5.1.2.e']]], 'dossiq');

		$this->assertSame(['recorded' => 0, 'refused' => [['position' => 3, 'code' => '5.1.2.e', 'reason' => 'grounds-unavailable']]], $answer);
		$this->assertArrayHasKey('old', $this->objects->rows, 'nothing is removed when the grounds cannot be read');
		$this->assertNotContains(WithheldDocuments::GROUNDS_CLASS, $this->resolved);
		$this->assertSame(['OCA\OpenRegister\Service\ObjectService'], array_values(array_unique($this->resolved)), 'no snapshot or other grounds source is asked');
	}//end testWithoutDossiqEveryEntryIsRefusedAndTheSnapshotIsNotRead()

	public function testAnUnavailableListRefusesEverything(): void {
		$this->grounds->unavailable = true;
		$this->objects->rows = ['old' => ['publication' => self::PUBLICATION, 'position' => 9, 'grounds' => [], 'source' => 'dossiq', 'recordedAt' => 'x']];

		$answer = $this->service()->record(
			self::PUBLICATION,
			[['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 7, 'grounds' => ['5.1.2.e']]],
			'dossiq'
		);

		$this->assertSame(0, $answer['recorded']);
		$this->assertSame(['grounds-unavailable', 'grounds-unavailable'], array_column($answer['refused'], 'reason'));
		$this->assertArrayHasKey('old', $this->objects->rows);
	}//end testAnUnavailableListRefusesEverything()

	public function testAMissingPublicationRefusesEverything(): void {
		$answer = $this->service()->record('bbbbbbbb-0000-4000-8000-000000000404', [['position' => 3, 'grounds' => ['5.1.2.e']]], 'dossiq');

		$this->assertSame([['position' => 3, 'code' => '5.1.2.e', 'reason' => 'no-publication']], $answer['refused']);
		$this->assertSame([], $this->objects->rows);
		$this->assertSame(0, $this->grounds->calls);
	}//end testAMissingPublicationRefusesEverything()

	public function testAnEmptyCallRemovesTheStoredEntries(): void {
		$service = $this->service();
		$service->record(self::PUBLICATION, [['position' => 3, 'grounds' => ['5.1.2.e']]], 'dossiq');
		$this->assertCount(1, $this->objects->rows);

		$answer = $service->record(self::PUBLICATION, [], 'dossiq');

		$this->assertSame(['recorded' => 0, 'refused' => []], $answer);
		$this->assertSame([], $this->objects->rows);
	}//end testAnEmptyCallRemovesTheStoredEntries()

	public function testASecondCallReplacesTheFirst(): void {
		$service = $this->service();
		$service->record(self::PUBLICATION, [['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 7, 'grounds' => ['5.1.2.e']]], 'dossiq');

		$service->record(self::PUBLICATION, [['position' => 5, 'grounds' => ['5.1.2.e']]], 'dossiq');

		$this->assertSame([5], array_column($this->stored(), 'position'));
	}//end testASecondCallReplacesTheFirst()

	public function testAnEntryWithoutAPositionOrGroundsIsRefusedAsInvalid(): void {
		$answer = $this->service()->record(self::PUBLICATION, [['position' => 0, 'grounds' => ['5.1.2.e']], ['position' => 2, 'grounds' => []], 'not-an-entry'], 'dossiq');

		$this->assertSame(0, $answer['recorded']);
		$this->assertSame(['invalid-entry', 'invalid-entry', 'invalid-entry'], array_column($answer['refused'], 'reason'));
	}//end testAnEntryWithoutAPositionOrGroundsIsRefusedAsInvalid()

	/**
	 * The contract both sides test (REQ-WDW-002, task 2.2).
	 *
	 * The ground keys record() reads must be among the keys dossiq's
	 * `WooRefusalGrounds::byCode()` answers (dossiq lib/Woo/WooRefusalGrounds.php
	 * shape(), lines 212-223); the entry keys are exactly {position, grounds};
	 * the answer keys {recorded, refused}, a refused item {position, code, reason}.
	 * dossiq's WithheldEntriesTest::testNoKeyButPositionAndGrounds pins the
	 * same entry keys from its side.
	 */
	public function testTheContractKeysMatchBothSides(): void {
		$dossiqGroundKeys = ['id', 'code', 'article', 'paragraph', 'letter', 'label', 'description', 'parent', 'status', 'legalSource', 'kind', 'citable'];
		foreach (WithheldDocuments::GROUND_KEYS as $key) {
			$this->assertContains($key, $dossiqGroundKeys);
		}

		$this->assertSame(['code', 'article', 'label'], WithheldDocuments::GROUND_KEYS);
		$this->assertSame(['position', 'grounds'], WithheldDocuments::ENTRY_KEYS);
		$this->assertSame(['recorded', 'refused'], WithheldDocuments::ANSWER_KEYS);
		$this->assertSame(['position', 'code', 'reason'], WithheldDocuments::REFUSED_KEYS);

		$answer = $this->service()->record(self::PUBLICATION, [['position' => 4, 'grounds' => ['5.2.5']]], 'dossiq');
		$this->assertSame(WithheldDocuments::ANSWER_KEYS, array_keys($answer));
		$this->assertSame(WithheldDocuments::REFUSED_KEYS, array_keys($answer['refused'][0]));

		$method = new \ReflectionMethod(WithheldDocuments::class, 'record');
		$this->assertSame(['publicationId', 'entries', 'source'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
	}//end testTheContractKeysMatchBothSides()
}//end class
