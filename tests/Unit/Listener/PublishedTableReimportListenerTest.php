<?php

/**
 * Unit tests for PublishedTableReimportListener.
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

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\PublishedTableReimportListener;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenRegister\Event\FileVersionRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A replaced CSV queues a re-import of every table it feeds, under who replaced it.
 *
 * @covers \OCA\OpenCatalogi\Listener\PublishedTableReimportListener
 */
class PublishedTableReimportListenerTest extends TestCase {

	/**
	 * The tables.
	 *
	 * @var PublishedTableService&MockObject
	 */
	private PublishedTableService&MockObject $tables;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var PublishedTableReimportListener
	 */
	private PublishedTableReimportListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tables = $this->createMock(PublishedTableService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new PublishedTableReimportListener($this->tables, $this->session, $this->logger);
	}//end setUp()

	/**
	 * A written file node.
	 *
	 * @param string $name The file name.
	 * @param int $id The file id.
	 *
	 * @return File&MockObject The node.
	 */
	private function file(string $name, int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);

		return $file;
	}//end file()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A new version of a CSV queues each table behind it, under the editor who wrote it.
	 *
	 * @return void
	 */
	public function testAReplacedFileQueuesAReimport(): void {
		$this->signIn(uid: 'bram');
		$this->tables->expects($this->once())->method('tablesForFile')->with('77')->willReturn([['id' => 't1'], ['id' => 't2']]);
		$queued = [];
		$this->tables->expects($this->exactly(2))->method('queueImport')->willReturnCallback(
			static function (string $tableId, ?string $importedBy = null) use (&$queued): bool {
				$queued[] = [$tableId, $importedBy];
				return true;
			}
		);

		$this->listener->handle(new NodeWrittenEvent($this->file(name: 'Subsidies.CSV', id: 77)));

		$this->assertSame([['t1', 'bram'], ['t2', 'bram']], $queued);
	}//end testAReplacedFileQueuesAReimport()

	/**
	 * A version restored through OpenRegister queues a re-import; without a session the table keeps its importer.
	 *
	 * @return void
	 */
	public function testARestoredVersionQueuesAReimport(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->tables->expects($this->once())->method('tablesForFile')->with('88')->willReturn([['id' => 't3']]);
		$this->tables->expects($this->once())->method('queueImport')->with('t3', null)->willReturn(true);

		$this->listener->handle(new FileVersionRestoredEvent(objectUuid: 'p1', fileId: 88, data: ['version' => '2']));
	}//end testARestoredVersionQueuesAReimport()

	/**
	 * Other files, folders and events are never looked up.
	 *
	 * @return void
	 */
	public function testOtherFilesAndEventsAreIgnored(): void {
		$this->tables->expects($this->never())->method('tablesForFile');
		$this->tables->expects($this->never())->method('queueImport');

		$this->listener->handle(new NodeWrittenEvent($this->file(name: 'report.pdf', id: 5)));
		$this->listener->handle(new NodeWrittenEvent($this->file(name: 'csv', id: 6)));
		$this->listener->handle(new NodeWrittenEvent($this->createMock(Folder::class)));
		$this->listener->handle(new Event());
	}//end testOtherFilesAndEventsAreIgnored()

	/**
	 * A failure to queue is logged and never thrown: the file was already saved.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$this->signIn(uid: 'bram');
		$this->tables->method('tablesForFile')->willThrowException(new RuntimeException('search down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[PublishedTableReimportListener] the re-import could not be queued',
			['fileId' => '77', 'exception' => 'search down']
		);

		$this->listener->handle(new NodeWrittenEvent($this->file(name: 'subsidies.csv', id: 77)));
	}//end testAFailureIsLoggedNotThrown()
}//end class
