<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\TableImportQueueInterface;
use OCA\OpenCatalogi\Listener\PublishedTableReimportListener;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenCatalogi\Service\Table\TableImportFollowUp;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A stand-in for OpenRegister's ObjectService that answers the tables of a file.
 */
class ReimportObjects {

	/**
	 * The search queries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $results What a search answers.
	 * @param bool                             $fails   Whether a search throws.
	 */
	public function __construct(public array $results = [], public bool $fails = false) {
	}//end __construct()

	/**
	 * ObjectService search.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param mixed                ...$rest The options.
	 *
	 * @return array<string, mixed> The page.
	 */
	public function searchObjectsPaginated(array $query, mixed ...$rest): array {
		if ($this->fails === true) {
			throw new RuntimeException('OpenRegister is down');
		}

		$this->queries[] = $query;

		return ['results' => $this->results];
	}//end searchObjectsPaginated()
}//end class

/**
 * A stand-in for OpenRegister's FileVersionRestoredEvent: same accessor.
 */
class VersionRestoredStandIn extends Event {

	/**
	 * Constructor.
	 *
	 * @param int $fileId The restored file.
	 */
	public function __construct(private readonly int $fileId) {
		parent::__construct();
	}//end __construct()

	/**
	 * The restored file.
	 *
	 * @return int The file id.
	 */
	public function getFileId(): int {
		return $this->fileId;
	}//end getFileId()
}//end class

/**
 * A replaced file behind a published table queues a re-import (REQ-DFA-003).
 *
 * @covers \OCA\OpenCatalogi\Listener\PublishedTableReimportListener
 * @covers \OCA\OpenCatalogi\Service\Table\TableImportFollowUp
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.4
 */
class PublishedTableReimportListenerTest extends TestCase {

	/**
	 * The queued re-imports, as [table, importedBy].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $queued = [];

	/**
	 * A listener over the given object service, with `bram` signed in or nobody.
	 *
	 * @param ReimportObjects $objects The object service.
	 * @param string|null     $user    The signed-in user.
	 *
	 * @return PublishedTableReimportListener The listener.
	 */
	private function listener(ReimportObjects $objects, ?string $user = 'bram'): PublishedTableReimportListener {
		$registers = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($objects);
		$registers->method('registerConfiguration')->willReturn(['register' => 'pub', 'schema' => 'tbl']);
		$queue = $this->createMock(originalClassName: TableImportQueueInterface::class);
		$queue->method('queue')->willReturnCallback(
			function (string $tableId, string $importedBy): bool {
				$this->queued[] = [$tableId, $importedBy];
				return true;
			}
		);
		$followUp = new TableImportFollowUp(
			tables: new PublishedTableService(registers: $registers, container: $this->createMock(originalClassName: ContainerInterface::class)),
			queue: $queue,
			time: $this->createMock(originalClassName: ITimeFactory::class)
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$signedIn = null;
		if ($user !== null) {
			$signedIn = $this->createMock(originalClassName: IUser::class);
			$signedIn->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($signedIn);

		return new PublishedTableReimportListener(followUp: $followUp, session: $session, logger: new NullLogger());
	}//end listener()

	/**
	 * A written file node.
	 *
	 * @param string $name The file name.
	 * @param int    $id   The file id.
	 * @param object $node The node double (a File or a Folder).
	 *
	 * @return NodeWrittenEvent The event Nextcloud dispatches.
	 */
	private static function written(string $name, int $id, object $node): NodeWrittenEvent {
		$node->method('getName')->willReturn($name);
		$node->method('getId')->willReturn($id);

		return new NodeWrittenEvent($node);
	}//end written()

	/**
	 * Replacing the CSV behind a table queues its re-import, started by who replaced it.
	 *
	 * @return void
	 */
	public function testAReplacedFileQueuesAReimport(): void {
		$objects = new ReimportObjects(results: [
			['id' => 't1', 'file' => '77', 'importedBy' => 'anna'],
			['id' => 't2', 'file' => '78', 'importedBy' => 'anna'],
		]);

		$event = self::written(name: 'speeltuinen.csv', id: 77, node: $this->createMock(originalClassName: File::class));
		$this->listener(objects: $objects)->handle(event: $event);

		$this->assertSame(expected: [['t1', 'bram']], actual: $this->queued);
		$this->assertSame(expected: ['file' => '77', '_limit' => 100], actual: array_diff_key($objects->queries[0], ['@self' => 1]));
	}//end testAReplacedFileQueuesAReimport()

	/**
	 * Without a signed-in user the table's last importer is notified.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheLastImporterStartsIt(): void {
		$objects = new ReimportObjects(results: [['id' => 't1', 'file' => '77', 'importedBy' => 'anna']]);

		$event = self::written(name: 'SPEELTUINEN.CSV', id: 77, node: $this->createMock(originalClassName: File::class));
		$this->listener(objects: $objects, user: null)->handle(event: $event);

		$this->assertSame(expected: [['t1', 'anna']], actual: $this->queued);
	}//end testWithoutAUserTheLastImporterStartsIt()

	/**
	 * A file that is not a CSV, a folder, or an unrelated event looks nothing up.
	 *
	 * @return void
	 */
	public function testAnythingButACsvIsIgnored(): void {
		$objects = new ReimportObjects(results: [['id' => 't1', 'file' => '77']]);
		$listener = $this->listener(objects: $objects);

		$listener->handle(event: self::written(name: 'besluit.pdf', id: 77, node: $this->createMock(originalClassName: File::class)));
		$listener->handle(event: self::written(name: 'data.csv', id: 77, node: $this->createMock(originalClassName: Folder::class)));
		$listener->handle(event: new Event());

		$this->assertSame(expected: [], actual: $objects->queries);
		$this->assertSame(expected: [], actual: $this->queued);
	}//end testAnythingButACsvIsIgnored()

	/**
	 * OpenRegister's restore of an older version is handled by its file id.
	 *
	 * @return void
	 */
	public function testARestoredVersionQueuesAReimport(): void {
		if (class_exists(PublishedTableReimportListener::VERSION_RESTORED_EVENT) === false) {
			class_alias(VersionRestoredStandIn::class, PublishedTableReimportListener::VERSION_RESTORED_EVENT);
		}

		$objects = new ReimportObjects(results: [['id' => 't1', 'file' => '77', 'importedBy' => 'anna']]);
		$class = PublishedTableReimportListener::VERSION_RESTORED_EVENT;
		$arguments = ['obj-1', 77];
		if (is_a($class, VersionRestoredStandIn::class, true) === true) {
			$arguments = [77];
		}

		$event = new $class(...$arguments);

		$this->listener(objects: $objects)->handle(event: $event);

		$this->assertSame(expected: [['t1', 'bram']], actual: $this->queued);
	}//end testARestoredVersionQueuesAReimport()

	/**
	 * A lookup that throws is logged, never thrown into the file write.
	 *
	 * @return void
	 */
	public function testAFailingLookupDoesNotBreakTheWrite(): void {
		$event = self::written(name: 'a.csv', id: 77, node: $this->createMock(originalClassName: File::class));
		$this->listener(objects: new ReimportObjects(fails: true))->handle(event: $event);

		$this->assertSame(expected: [], actual: $this->queued);
	}//end testAFailingLookupDoesNotBreakTheWrite()
}//end class
