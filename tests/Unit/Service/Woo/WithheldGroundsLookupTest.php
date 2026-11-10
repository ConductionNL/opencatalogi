<?php

/**
 * WithheldDocuments::record()'s reading of dossiq's grounds list: the
 * container that cannot build it, an object without byCode(), a list with
 * rows that are not grounds, an error that is not dossiq's, and an empty
 * call without dossiq.
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

use LogicException;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\OpenCatalogi\Service\Woo\WithheldDocuments;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * A grounds list whose rows are scripted per test.
 */
class ScriptedGroundsList extends WooRefusalGrounds {

	/**
	 * The rows listWithRetired() answers.
	 *
	 * @var array<int, mixed>
	 */
	public array $rows = [];

	/**
	 * What listWithRetired() throws, if anything.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $failure = null;

	/**
	 * The scripted rows.
	 *
	 * @return array<int, mixed>
	 */
	public function listWithRetired(): array {
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->rows;
	}//end listWithRetired()
}//end class

/**
 * Pins how record() reads dossiq's grounds list.
 */
class WithheldGroundsLookupTest extends TestCase {

	private const PUBLICATION = 'aaaaaaaa-0000-4000-8000-000000000001';

	/**
	 * What replace() was asked to store, per call.
	 *
	 * @var list<array{0: string, 1: array<int, mixed>, 2: string}>
	 */
	private array $replaced = [];

	/**
	 * A container that cannot build the grounds list is no dossiq.
	 *
	 * @return void
	 */
	public function testAGroundsListThatCannotBeBuiltRefusesEverything(): void {
		$answer = $this->service(grounds: new RuntimeException('no service'))->record(self::PUBLICATION, $this->entries(), 'dossiq');

		$this->assertSame($this->refusedAll(reason: 'grounds-unavailable'), $answer);
		$this->assertSame([], $this->replaced);
	}//end testAGroundsListThatCannotBeBuiltRefusesEverything()

	/**
	 * An object without byCode() is no grounds list.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutByCodeRefusesEverything(): void {
		$answer = $this->service(grounds: new stdClass())->record(self::PUBLICATION, $this->entries(), 'dossiq');

		$this->assertSame($this->refusedAll(reason: 'grounds-unavailable'), $answer);
	}//end testAnObjectWithoutByCodeRefusesEverything()

	/**
	 * Rows that are no ground are skipped, and the first row of a code wins.
	 *
	 * @return void
	 */
	public function testRowsThatAreNoGroundAreSkippedAndTheFirstOfACodeWins(): void {
		$list = new ScriptedGroundsList();
		$list->rows = [
			'not a row',
			['code' => 5, 'label' => 'numeric code'],
			['label' => 'no code'],
			['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'first'],
			['code' => '5.1.2.e', 'article' => '9.9', 'label' => 'second'],
		];

		$answer = $this->service(grounds: $list)->record(self::PUBLICATION, $this->entries(), 'dossiq');

		$this->assertSame(['recorded' => 1, 'refused' => [['position' => 4, 'code' => '5.1.1.c', 'reason' => 'unknown-ground']]], $answer);
		$this->assertSame(
			[[self::PUBLICATION, [['position' => 3, 'grounds' => [['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'first']]]], 'dossiq']],
			$this->replaced
		);
	}//end testRowsThatAreNoGroundAreSkippedAndTheFirstOfACodeWins()

	/**
	 * An error that is not dossiq's own is not turned into a refusal.
	 *
	 * @return void
	 */
	public function testAnErrorThatIsNotDossiqsIsThrown(): void {
		$list = new ScriptedGroundsList();
		$list->failure = new LogicException('a bug');

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('a bug');
		$this->service(grounds: $list)->record(self::PUBLICATION, $this->entries(), 'dossiq');
	}//end testAnErrorThatIsNotDossiqsIsThrown()

	/**
	 * An empty call without dossiq still clears what was stored.
	 *
	 * @return void
	 */
	public function testAnEmptyCallWithoutDossiqClearsTheStoredEntries(): void {
		$answer = $this->service(grounds: null)->record(self::PUBLICATION, [], 'dossiq');

		$this->assertSame(['recorded' => 0, 'refused' => []], $answer);
		$this->assertSame([[self::PUBLICATION, [], 'dossiq']], $this->replaced);
	}//end testAnEmptyCallWithoutDossiqClearsTheStoredEntries()

	/**
	 * Two entries: one with a listed code, one with an unlisted code.
	 *
	 * @return list<array{position: int, grounds: list<string>}>
	 */
	private function entries(): array {
		return [
			['position' => 3, 'grounds' => ['5.1.2.e']],
			['position' => 4, 'grounds' => ['5.1.1.c']],
		];
	}//end entries()

	/**
	 * The answer that refuses both entries for one reason.
	 *
	 * @param string $reason The reason.
	 *
	 * @return array<string, mixed>
	 */
	private function refusedAll(string $reason): array {
		return [
			'recorded' => 0,
			'refused' => [
				['position' => 3, 'code' => '5.1.2.e', 'reason' => $reason],
				['position' => 4, 'code' => '5.1.1.c', 'reason' => $reason],
			],
		];
	}//end refusedAll()

	/**
	 * The service over a store that finds the publication and a container
	 * that answers the given grounds (null: dossiq is not installed; a
	 * Throwable: the container throws it).
	 *
	 * @param mixed $grounds What the container answers for the grounds class.
	 *
	 * @return WithheldDocuments
	 */
	private function service(mixed $grounds): WithheldDocuments {
		$store = $this->createMock(WithheldDocumentStore::class);
		$store->method('publicationExists')->willReturn(true);
		$store->method('replace')->willReturnCallback(
			function (string $publicationId, array $accepted, string $source): void {
				$this->replaced[] = [$publicationId, $accepted, $source];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function () use ($grounds): mixed {
				if ($grounds instanceof \Throwable) {
					throw $grounds;
				}

				return $grounds;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($grounds !== null);

		return new WithheldDocuments($store, $container, $appManager);
	}//end service()
}//end class
