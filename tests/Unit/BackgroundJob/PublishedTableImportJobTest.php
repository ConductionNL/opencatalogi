<?php

/**
 * Unit tests for PublishedTableImportJob.
 *
 * The job runs over the real PublishedTableService; OpenRegister's mappers,
 * import preview, ObjectService and the root folder are a fake that records
 * every table it is asked to save.
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

namespace OCA\OpenCatalogi\Tests\Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\PublishedTableImportJob;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A fake of the OpenRegister services and the root folder the import reaches.
 */
class ImportJobFakeOpenRegister {

	/** @var array<int, array<string, mixed>> Every saved table, in order. */
	public array $saved = [];

	/** @var array<int, array<string, mixed>> Every search query. */
	public array $searches = [];

	/** @var array<int, array<int, mixed>> Every preview asked. */
	public array $previews = [];

	/** @var array<string, mixed> The stored table records, by id. */
	public array $tables = [];

	/** @var array<string, mixed> What the preview answers. */
	public array $preview = ['toRefuse' => 0, 'state' => 'previewed', 'failed' => 0, 'sourceHash' => 'h', 'report' => ['refusals' => []]];

	/** @var \Throwable|null What the preview throws. */
	public ?\Throwable $previewThrows = null;

	/** @var \Throwable|null What every save throws. */
	public ?\Throwable $saveThrows = null;

	/** @var mixed The node the root folder answers. */
	public mixed $node = null;

	/** ObjectService find, and the register and schema mappers. */
	public function find(mixed $id, mixed ...$rest): mixed {
		if ($id === 'open-data-tables') {
			return new ImportJobFakeEntity(['id' => 5]);
		}

		if ($rest === []) {
			return new ImportJobFakeEntity(['id' => (int)$id]);
		}

		if (isset($this->tables[$id]) === false) {
			throw new RuntimeException('not found');
		}

		return $this->tables[$id];
	}

	/** ObjectService save. */
	public function saveObject(array $object, mixed ...$options): array {
		if ($this->saveThrows !== null) {
			throw $this->saveThrows;
		}

		$this->saved[] = $object;
		return $object;
	}

	/** ObjectService search. */
	public function searchObjectsPaginated(array $query, mixed ...$options): array {
		$this->searches[] = $query;
		$results = array_values(
			array_filter(
				$this->tables,
				static fn (array $t): bool => ($t['status'] ?? null) === ($query['status'] ?? null)
			)
		);
		return ['results' => $results];
	}

	/** Import preview. */
	public function preview(array $params, mixed $user): ImportJobFakeEntity {
		$this->previews[] = [$params, $user];
		if ($this->previewThrows !== null) {
			throw $this->previewThrows;
		}

		return new ImportJobFakeEntity($this->preview);
	}

	/** Import commit. */
	public function commit(mixed $preview, mixed $commit): ImportJobFakeEntity {
		return new ImportJobFakeEntity(['toRefuse' => 0, 'state' => 'committed', 'failed' => 0, 'applied' => 7, 'report' => []]);
	}

	/** Root folder. */
	public function getFirstNodeById(int $id): mixed {
		return $this->node;
	}
}

/**
 * A fake OpenRegister entity: getters over an array.
 */
class ImportJobFakeEntity {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fields The fields.
	 */
	public function __construct(public array $fields) {
	}

	/**
	 * Read a field by its getter name.
	 *
	 * @param string $name The method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed The field.
	 */
	public function __call(string $name, array $args): mixed {
		return ($this->fields[lcfirst(substr($name, 3))] ?? null);
	}
}

/**
 * A queued import ends ready or failed, never importing.
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\PublishedTableImportJob
 * @uses   \OCA\OpenCatalogi\Service\Table\PublishedTableService
 * @uses   \OCA\OpenCatalogi\Service\SchemaObjects
 * @uses   \OCA\OpenCatalogi\Contract\TableFile
 */
class PublishedTableImportJobTest extends TestCase {

	/**
	 * The fake OpenRegister.
	 *
	 * @var ImportJobFakeOpenRegister
	 */
	private ImportJobFakeOpenRegister $openRegister;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var PublishedTableImportJob
	 */
	private PublishedTableImportJob $job;

	/**
	 * Wire the job to the real service over the fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->openRegister = new ImportJobFakeOpenRegister();
		$this->openRegister->tables['t1'] = [
			'id' => 't1', 'title' => 'subsidies.csv', 'publication' => 'p1', 'file' => '77', 'schema' => '42',
			'status' => 'importing', 'importedBy' => 'anna', '@self' => ['updated' => '2026-11-16T10:00:00+00:00'],
		];

		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->with('files/subsidies.csv')->willReturn('/data/anna/files/subsidies.csv');
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/subsidies.csv');
		$file->method('getName')->willReturn('subsidies.csv');
		$this->openRegister->node = $file;

		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->openRegister);
		$registers->method('registerConfiguration')->willReturn(['register' => 'pub', 'schema' => 'tbl']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): ImportJobFakeOpenRegister {
				$this->assertContains(
					$class,
					[
						IRootFolder::class,
						'OCA\OpenRegister\Db\RegisterMapper',
						'OCA\OpenRegister\Db\SchemaMapper',
						'OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface',
					]
				);
				return $this->openRegister;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		// 30 minutes after t1 was stored importing: not stale yet.
		$time->method('getTime')->willReturn(strtotime('2026-11-16T10:30:00+00:00'));
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new PublishedTableImportJob(
			time: $time,
			tables: new PublishedTableService(registers: $registers, container: $container),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Run the job's protected run().
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, $argument);
	}//end runJob()

	/**
	 * A refused row ends the table failed with the row, under who queued it.
	 *
	 * @return void
	 */
	public function testAFailedImportEndsFailedWithImportedBy(): void {
		$this->openRegister->preview['toRefuse'] = 1;
		$this->openRegister->preview['report'] = ['refusals' => [['row' => 12408, 'reason' => 'bedrag is not a number']]];

		$this->runJob(argument: ['table' => 't1', 'importedBy' => 'bram']);

		$this->assertSame(
			[['register' => 5, 'schema' => 42, 'filePath' => '/data/anna/files/subsidies.csv', 'sourceName' => 'subsidies.csv', 'format' => 'csv'], null],
			$this->openRegister->previews[0]
		);
		$this->assertSame(['importing', 'failed'], array_column($this->openRegister->saved, 'status'));
		$this->assertSame(['bram', 'bram'], array_column($this->openRegister->saved, 'importedBy'));
		$this->assertSame('Row 12408: bedrag is not a number', $this->openRegister->saved[1]['lastError']);
		$this->assertSame('t1', $this->openRegister->saved[1]['id']);
	}//end testAFailedImportEndsFailedWithImportedBy()

	/**
	 * A clean import ends ready; without an importer the table keeps its own.
	 *
	 * @return void
	 */
	public function testACleanImportEndsReadyAndKeepsTheImporter(): void {
		$this->runJob(argument: ['table' => 't1', 'importedBy' => '']);

		$last = end($this->openRegister->saved);
		$this->assertSame(['ready', 7, 'anna'], [$last['status'], $last['rowCount'], $last['importedBy']]);
	}//end testACleanImportEndsReadyAndKeepsTheImporter()

	/**
	 * A Throwable from the import, or from finding the file, ends the table failed with its message.
	 *
	 * @return void
	 */
	public function testAThrowableEndsFailed(): void {
		$this->openRegister->previewThrows = new \Error('Call to a member function getId() on null');
		$this->runJob(argument: ['table' => 't1']);
		$this->assertSame(['importing', 'failed'], array_column($this->openRegister->saved, 'status'));
		$this->assertSame('Call to a member function getId() on null', $this->openRegister->saved[1]['lastError']);

		$this->openRegister->saved = [];
		$this->openRegister->node = null;
		$this->runJob(argument: ['table' => 't1', 'importedBy' => 'bram']);
		$this->assertCount(1, $this->openRegister->saved);
		$this->assertSame('failed', $this->openRegister->saved[0]['status']);
		$this->assertSame('The file of table "subsidies.csv" is no longer there.', $this->openRegister->saved[0]['lastError']);
		$this->assertSame('bram', $this->openRegister->saved[0]['importedBy']);
	}//end testAThrowableEndsFailed()

	/**
	 * A failure that cannot even be stored is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureThatCannotBeStoredIsLogged(): void {
		$this->openRegister->node = null;
		$this->openRegister->saveThrows = new RuntimeException('database gone');
		$this->logger->expects($this->once())->method('warning')->with('[PublishedTableImportJob] stale imports could not be failed', $this->anything());
		$this->logger->expects($this->once())->method('error')->with(
			'[PublishedTableImportJob] the failed import could not be recorded',
			['table' => 't1', 'error' => 'The file of table "subsidies.csv" is no longer there.', 'exception' => 'database gone']
		);

		// The stale sweep also saves, so it throws first and is logged as a warning.
		$this->openRegister->tables['t1']['@self']['updated'] = '2026-11-16T08:00:00+00:00';
		$this->runJob(argument: ['table' => 't1']);
	}//end testAFailureThatCannotBeStoredIsLogged()

	/**
	 * A table importing for over an hour is failed with the stale error before the queued import runs.
	 *
	 * @return void
	 */
	public function testAStaleImportIsFailed(): void {
		$this->openRegister->tables['t2'] = [
			'id' => 't2', 'file' => '88', 'schema' => '43', 'status' => 'importing', 'importedBy' => 'cas',
			'@self' => ['updated' => '2026-11-16T09:29:59+00:00'],
		];
		$this->openRegister->tables['t3'] = [
			'id' => 't3', 'status' => 'importing', '@self' => ['updated' => '2026-11-16T09:30:00+00:00'],
		];
		$this->openRegister->tables['t4'] = ['id' => 't4', 'status' => 'importing'];

		$this->runJob(argument: ['table' => 't1']);

		$this->assertSame(['status' => 'importing', '_limit' => 1000], array_diff_key($this->openRegister->searches[0], ['@self' => 1]));
		$stale = $this->openRegister->saved[0];
		$this->assertSame(['t2', 'failed', 'The import stopped without an answer.', 'cas'], [$stale['id'], $stale['status'], $stale['lastError'], $stale['importedBy']]);
		$this->assertSame(['t2', 't1', 't1'], array_column($this->openRegister->saved, 'id'), 'exactly one hour is not stale, and a table without a time is left alone');
	}//end testAStaleImportIsFailed()

	/**
	 * Without a table id, or for a table that is gone, nothing is imported.
	 *
	 * @return void
	 */
	public function testNothingIsImportedWithoutATable(): void {
		$this->logger->expects($this->once())->method('info')->with('[PublishedTableImportJob] the table is gone', ['table' => 'gone']);

		$this->runJob(argument: null);
		$this->runJob(argument: ['table' => ' ']);
		$this->runJob(argument: ['table' => 'gone']);

		$this->assertSame([], $this->openRegister->saved);
		$this->assertSame([], $this->openRegister->previews);
	}//end testNothingIsImportedWithoutATable()
}//end class
