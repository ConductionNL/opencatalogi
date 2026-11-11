<?php

/**
 * Golden tests for the guards of a resident's dossiers: what adding an item
 * refuses, and that removing a portal account deletes only that resident's
 * own objects, counting and logging what happened.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

require_once __DIR__ . '/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A store whose owner lookup also returns rows that are not the owner's, and
 * whose delete fails for one id.
 */
class LooseOwnerPortalObjectStore extends FakePortalObjectStore {

	/**
	 * Every delete asked for, in order.
	 *
	 * @var array<int, string>
	 */
	public array $deletes = [];

	public function findByOwner(string $schema, string $owner): array {
		return [
			['id' => $schema . '-own', 'owner' => $owner],
			['id' => $schema . '-other', 'owner' => 'subject-2'],
			['id' => '', 'owner' => $owner],
			['owner' => $owner],
			['id' => $schema . '-unowned'],
			['id' => $schema . '-gone', 'owner' => $owner],
			['id' => $schema . '-fails', 'owner' => $owner],
		];
	}

	public function delete(string $schema, string $id): bool {
		$this->deletes[] = $schema . ':' . $id;
		if ($id === $schema . '-fails') {
			throw new RuntimeException('register down for ' . $id);
		}

		return $id !== $schema . '-gone';
	}
}

/**
 * The guards of adding an item and of removing an account.
 */
class CitizenCollectionGuardsGoldenTest extends TestCase {

	private const PUB = 'aaaaaaaa-0000-4000-8000-000000000001';

	/**
	 * The service over a store.
	 *
	 * @param FakePortalObjectStore $store The store.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CitizenCollectionService
	 */
	private function service(FakePortalObjectStore $store, ?LoggerInterface $logger = null): CitizenCollectionService {
		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/' . $id);
		return new CitizenCollectionService(store: $store, linker: $linker, logger: ($logger ?? new NullLogger()));
	}

	/**
	 * Removing an account deletes only the rows with an id that the owner
	 * owns, in both schemas, counts the deletes that happened, and logs a
	 * failed one without stopping.
	 *
	 * @return void
	 */
	public function testRemovingAnAccountDeletesOnlyItsOwnObjects(): void {
		$store = new LooseOwnerPortalObjectStore();
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$deleted = $this->service(store: $store, logger: $logger)->removeEverythingOf('subject-1');

		$this->assertSame(2, $deleted);
		$this->assertSame(
			['collection:collection-own', 'collection:collection-gone', 'collection:collection-fails', 'savedSearch:savedSearch-own', 'savedSearch:savedSearch-gone', 'savedSearch:savedSearch-fails'],
			$store->deletes
		);
		$this->assertSame(
			[
				["OpenCatalogi: could not delete a removed resident's object", ['schema' => 'collection', 'reason' => 'register down for collection-fails']],
				["OpenCatalogi: could not delete a removed resident's object", ['schema' => 'savedSearch', 'reason' => 'register down for savedSearch-fails']],
			],
			$logged
		);

	}//end testRemovingAnAccountDeletesOnlyItsOwnObjects()

	/**
	 * An account without a subject reference removes nothing and asks nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyOwnerRemovesNothing(): void {
		$store = new LooseOwnerPortalObjectStore();
		$this->assertSame(0, $this->service(store: $store)->removeEverythingOf(''));
		$this->assertSame([], $store->deletes);

	}//end testAnEmptyOwnerRemovesNothing()

	/**
	 * An item needs a publication, checked after the dossier.
	 *
	 * @return void
	 */
	public function testAnItemNeedsAPublication(): void {
		$store = new FakePortalObjectStore();
		$service = $this->service(store: $store);
		$messages = [];
		foreach ([['title' => 'T'], ['publication' => self::PUB], ['collection' => 'nope', 'publication' => '']] as $input) {
			try {
				$service->addItem(owner: 'subject-1', input: $input);
			} catch (PortalInputException | PortalNotFoundException $e) {
				$messages[] = get_class($e) . ': ' . $e->getMessage();
			}
		}

		$this->assertSame(
			[
				PortalInputException::class . ': A publication is needed',
				PortalInputException::class . ': A new dossier needs a title',
				PortalNotFoundException::class . ': Not found',
			],
			$messages
		);
		$this->assertSame([], $store->saves);

	}//end testAnItemNeedsAPublication()

	/**
	 * A full dossier refuses a new item but still answers for one it holds.
	 *
	 * @return void
	 */
	public function testAFullDossierRefusesANewItem(): void {
		$store = new FakePortalObjectStore();
		$store->publication(self::PUB, 'Besluit');
		$items = [];
		for ($i = 0; $i < CitizenCollectionService::MAX_ITEMS; $i++) {
			$items[] = ['id' => 'i' . $i, 'publication' => 'p' . $i, 'addedAt' => '2026-01-01T00:00:00+00:00', 'addedBy' => 'resident', 'title' => 't'];
		}

		$items[0]['publication'] = self::PUB;
		$store->objects['collection']['c-1'] = ['id' => 'c-1', 'title' => 'D', 'owner' => 'subject-1', 'items' => $items];
		$service = $this->service(store: $store);

		$held = $service->addItem(owner: 'subject-1', input: ['collection' => 'c-1', 'publication' => self::PUB]);
		$this->assertCount(CitizenCollectionService::MAX_ITEMS, $held['items']);

		$this->expectException(PortalInputException::class);
		$this->expectExceptionMessage('This dossier is full');
		$service->addItem(owner: 'subject-1', input: ['collection' => 'c-1', 'publication' => self::PUB, 'attachment' => 42]);

	}//end testAFullDossierRefusesANewItem()
}//end class
