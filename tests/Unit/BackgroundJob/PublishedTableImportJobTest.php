<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use InvalidArgumentException;
use OCA\OpenCatalogi\BackgroundJob\PublishedTableImportJob;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\TableImportQueueInterface;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenCatalogi\Service\Table\TableImportFollowUp;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * A stand-in for OpenRegister's ObjectService: answers one search and records saves.
 */
class ImportJobObjects {

	/**
	 * The saved objects.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

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
	 */
	public function __construct(public array $results = []) {
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
		$this->queries[] = $query;

		return ['results' => $this->results, 'total' => count($this->results)];
	}//end searchObjectsPaginated()

	/**
	 * ObjectService save.
	 *
	 * @param array<string, mixed> $object  The object.
	 * @param mixed                ...$rest The options.
	 *
	 * @return array<string, mixed> The stored object.
	 */
	public function saveObject(array $object, mixed ...$rest): array {
		$this->saved[] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * The background import of a published table ends `ready` or `failed`, never `importing` (REQ-DFA-002).
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\PublishedTableImportJob
 * @covers \OCA\OpenCatalogi\Service\Table\TableImportFollowUp
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
 */
class PublishedTableImportJobTest extends TestCase {

	/**
	 * The stored table the job imports.
	 *
	 * @var array<string, mixed>
	 */
	private const TABLE = ['id' => 't1', 'publication' => 'p1', 'file' => '77', 'title' => 'speeltuinen.csv', 'status' => 'importing', 'importedBy' => 'anna'];

	/**
	 * The table service double.
	 *
	 * @var PublishedTableService&MockObject
	 */
	private PublishedTableService $tables;

	/**
	 * The object service double.
	 *
	 * @var ImportJobObjects
	 */
	private ImportJobObjects $objects;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tables = $this->createMock(PublishedTableService::class);
		$this->objects = new ImportJobObjects();
	}//end setUp()

	/**
	 * Run the job with an argument.
	 *
	 * @param mixed $argument The job argument.
	 * @param int   $now      The clock's time.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument, int $now = 1_800_000_000): void {
		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->objects);
		$registers->method('registerConfiguration')->willReturn(['register' => 'pub', 'schema' => 'tbl']);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$followUp = new TableImportFollowUp(
			tables: $this->tables,
			registers: $registers,
			queue: $this->createMock(TableImportQueueInterface::class),
			time: $time
		);
		$job = new PublishedTableImportJob(time: $time, followUp: $followUp, logger: new NullLogger());

		$run = new ReflectionMethod(objectOrMethod: $job, method: 'run');
		$run->setAccessible(accessible: true);
		$run->invoke($job, $argument);
	}//end runJob()

	/**
	 * A failed import ends the table failed with the user who started it, so the notification reaches them.
	 *
	 * @return void
	 */
	public function testAFailedImportEndsFailedWithImportedBy(): void {
		$this->tables->method('findTable')->with('t1')->willReturn(self::TABLE);
		$this->tables->expects($this->once())->method('reimport')
			->with(self::TABLE, 'bram')
			->willReturn(['status' => 'failed', 'lastError' => 'Row 350: not a number', 'importedBy' => 'bram'] + self::TABLE);

		$this->runJob(argument: ['table' => 't1', 'importedBy' => 'bram']);

		$this->assertSame([], $this->objects->saved, 'the import itself stored the outcome');
	}//end testAFailedImportEndsFailedWithImportedBy()

	/**
	 * Anything thrown outside the import (a publication or attachment that is gone) ends the table failed with its message.
	 *
	 * @return void
	 */
	public function testAThrowableEndsFailed(): void {
		$this->tables->method('findTable')->willReturn(self::TABLE);
		$this->tables->method('reimport')->willThrowException(new InvalidArgumentException('The publication has no attachment 77.'));

		$this->runJob(argument: ['table' => 't1', 'importedBy' => '']);

		$this->assertCount(1, $this->objects->saved);
		$saved = $this->objects->saved[0];
		$this->assertSame('failed', $saved['status']);
		$this->assertSame('The publication has no attachment 77.', $saved['lastError']);
		$this->assertSame('anna', $saved['importedBy'], 'without a starter the table keeps its last importer');
	}//end testAThrowableEndsFailed()

	/**
	 * A table importing for over an hour is ended failed by the next run; a recent one and the job's own table are left alone.
	 *
	 * @return void
	 */
	public function testAStaleImportIsFailed(): void {
		$now = 1_800_000_000;
		$this->objects->results = [
			['id' => 'old', 'status' => 'importing', '@self' => ['updated' => gmdate('c', $now - 3601)]],
			['id' => 'new', 'status' => 'importing', '@self' => ['updated' => gmdate('c', $now - 60)]],
			['id' => 't1', 'status' => 'importing', '@self' => ['updated' => gmdate('c', $now - 7200)]],
			['id' => 'done', 'status' => 'ready', '@self' => ['updated' => gmdate('c', $now - 7200)]],
			['id' => 'unknown', 'status' => 'importing'],
		];
		$this->tables->method('findTable')->willReturn(null);

		$this->runJob(argument: ['table' => 't1'], now: $now);

		$this->assertSame(['status' => 'importing', '_limit' => 100], array_diff_key($this->objects->queries[0], ['@self' => 1]));
		$this->assertCount(1, $this->objects->saved);
		$this->assertSame('old', $this->objects->saved[0]['id']);
		$this->assertSame('failed', $this->objects->saved[0]['status']);
		$this->assertSame(TableImportFollowUp::STALE_ERROR, $this->objects->saved[0]['lastError']);
		$this->assertArrayNotHasKey('@self', $this->objects->saved[0]);
	}//end testAStaleImportIsFailed()

	/**
	 * A run without a table only sweeps; a sweep that throws does not stop the import.
	 *
	 * @return void
	 */
	public function testASweepThatThrowsDoesNotStopTheImport(): void {
		$this->tables->expects($this->never())->method('findTable');
		$this->runJob(argument: 'not an array');

		$this->objects = new class extends ImportJobObjects {
			/**
			 * A search that fails.
			 *
			 * @param array<string, mixed> $query   The query.
			 * @param mixed                ...$rest The options.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function searchObjectsPaginated(array $query, mixed ...$rest): array {
				throw new RuntimeException('OpenRegister is down');
			}//end searchObjectsPaginated()
		};
		$tables = $this->createMock(PublishedTableService::class);
		$tables->method('findTable')->willReturn(self::TABLE);
		$tables->expects($this->once())->method('reimport')->willReturn(self::TABLE);
		$this->tables = $tables;

		$this->runJob(argument: ['table' => 't1', 'importedBy' => 'anna']);
	}//end testASweepThatThrowsDoesNotStopTheImport()

	/**
	 * A table that is gone is not imported, and the job does not fail.
	 *
	 * @return void
	 */
	public function testAGoneTableIsSkipped(): void {
		$this->tables->method('findTable')->willReturn(null);
		$this->tables->expects($this->never())->method('reimport');

		$this->runJob(argument: ['table' => 'gone', 'importedBy' => 'anna']);

		$this->assertSame([], $this->objects->saved);
	}//end testAGoneTableIsSkipped()
}//end class
