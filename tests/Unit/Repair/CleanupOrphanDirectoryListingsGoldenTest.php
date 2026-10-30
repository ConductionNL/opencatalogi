<?php
/**
 * Golden test for the CleanupOrphanDirectoryListings repair step.
 *
 * Every world is recorded once and replayed from the fixture: what the step
 * told the output, which listings it deleted, and which tables it read.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\CleanupOrphanDirectoryListings;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for CleanupOrphanDirectoryListings.
 *
 * @covers \OCA\OpenCatalogi\Repair\CleanupOrphanDirectoryListings
 * @covers \OCA\OpenCatalogi\Repair\ListingTable
 * @covers \OCA\OpenCatalogi\Repair\OrphanListingSweep
 */
class CleanupOrphanDirectoryListingsGoldenTest extends TestCase
{

    private const FIXTURE = __DIR__.'/fixtures/cleanup-orphan-directory-listings-golden.json';

    /**
     * What the doubles did during one run.
     *
     * @var array<string, list<mixed>>
     */
    private array $log = [];

    /**
     * The recorded worlds.
     *
     * A world names the installed apps, which lookups fail, the registers
     * (id => schema ids), the orphan rows and valid-row counts per table, and
     * the uuids whose delete throws. A table missing from `orphans` fails its
     * scan; a table missing from `valid` fails its count.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function worlds(): array
    {
        $tables = [
            'orphans' => [
                'openregister_table_1_7' => [
                    ['_uuid' => 'a-1'],
                    ['_uuid' => ''],
                    ['_uuid' => 'boom'],
                    ['_uuid' => 'a-2'],
                ],
                'openregister_table_3_7' => [['_uuid' => 'c-1']],
            ],
            'valid'   => [
                'openregister_table_1_7' => 5,
                'openregister_table_3_7' => 2,
            ],
        ];

        return [
            'no openregister'               => [['apps' => ['opencatalogi']]],
            'services unresolvable'         => [['fail' => 'container']],
            'schema lookup throws'          => [['fail' => 'schema']],
            'no listing schema'             => [['fail' => 'noSchema']],
            'registers throw'               => [['fail' => 'registers']],
            'no registers'                  => [['registers' => []]],
            'no register carries the schema' => [['registers' => [1 => [2, 3], 2 => null]]],
            'two registers, mixed rows'     => [array_merge($tables, ['registers' => [1 => ['7', 8], 2 => [9], 3 => [7]]])],
            'scan of one table fails'       => [
                [
                    'registers' => [1 => [7], 3 => [7]],
                    'orphans'   => ['openregister_table_3_7' => [['_uuid' => 'c-1']]],
                    'valid'     => ['openregister_table_1_7' => 4, 'openregister_table_3_7' => 1],
                ],
            ],
            'count fails'                   => [
                [
                    'registers' => [1 => [7]],
                    'orphans'   => ['openregister_table_1_7' => [['_uuid' => 'a-1']]],
                    'valid'     => [],
                ],
            ],
            'clean table'                   => [
                [
                    'registers' => [1 => [7]],
                    'orphans'   => ['openregister_table_1_7' => []],
                    'valid'     => ['openregister_table_1_7' => 3],
                ],
            ],
            'every delete fails'            => [
                [
                    'registers' => [1 => [7]],
                    'orphans'   => ['openregister_table_1_7' => [['_uuid' => 'boom'], ['_uuid' => '0']]],
                    'valid'     => ['openregister_table_1_7' => 0],
                ],
            ],
        ];

    }//end worlds()

    /**
     * Replay a world and compare it to the recording.
     *
     * @param array<string, mixed> $world The world.
     *
     * @return void
     */
    #[DataProvider('worlds')]
    public function testRunMatchesTheRecording(array $world): void
    {
        $this->log = ['output' => [], 'deleted' => [], 'tables' => []];
        $step      = new CleanupOrphanDirectoryListings(
            $this->appManager($world),
            $this->db($world),
            $this->container($world)
        );
        $step->run($this->recordingOutput());

        $recorded = [];
        if (file_exists(self::FIXTURE) === true) {
            $recorded = json_decode((string) file_get_contents(self::FIXTURE), true);
        }

        $name = (string) $this->dataName();
        if (getenv('GOLDEN_RECORD') === '1') {
            $recorded[$name] = $this->log;
            if (is_dir(dirname(self::FIXTURE)) === false) {
                mkdir(dirname(self::FIXTURE));
            }

            file_put_contents(self::FIXTURE, json_encode($recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n");
        }

        $this->assertArrayHasKey($name, $recorded, 'No recording for '.$name);
        $this->assertSame($recorded[$name], $this->log);

    }//end testRunMatchesTheRecording()

    /**
     * The app manager of a world.
     *
     * @param array<string, mixed> $world The world.
     *
     * @return IAppManager
     */
    private function appManager(array $world): IAppManager
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn($world['apps'] ?? ['opencatalogi', 'openregister']);
        return $appManager;

    }//end appManager()

    /**
     * An output that records every line.
     *
     * @return IOutput
     */
    private function recordingOutput(): IOutput
    {
        $output = $this->createMock(IOutput::class);
        foreach (['info', 'warning'] as $level) {
            $output->method($level)->willReturnCallback(
                function (string $message) use ($level): void {
                    $this->log['output'][] = [$level, $message];
                }
            );
        }

        return $output;

    }//end recordingOutput()

    /**
     * The container of a world, serving OpenRegister's mappers and object service.
     *
     * @param array<string, mixed> $world The world.
     *
     * @return ContainerInterface
     */
    private function container(array $world): ContainerInterface
    {
        $fail      = ($world['fail'] ?? '');
        $registers = ($world['registers'] ?? []);
        $log       = &$this->log;

        $schemaMapper = new class($fail) {
            public function __construct(private string $fail)
            {
            }

            public function findByApplicationAndSlug(string $slug, string $app): ?object
            {
                if ($this->fail === 'schema') {
                    throw new \RuntimeException('schema table locked');
                }

                if ($this->fail === 'noSchema') {
                    return null;
                }

                return new class($slug.'@'.$app) {
                    public function __construct(private string $key)
                    {
                    }

                    public function getId(): string
                    {
                        return ($this->key === 'listing@opencatalogi') ? '7' : '0';
                    }
                };
            }
        };

        $registerMapper = new class($fail, $registers) {
            public function __construct(private string $fail, private array $registers)
            {
            }

            public function findAll(): array
            {
                if ($this->fail === 'registers') {
                    throw new \RuntimeException('register table locked');
                }

                $all = [];
                foreach ($this->registers as $id => $schemas) {
                    $all[] = new class((string) $id, $schemas) {
                        public function __construct(private string $id, private ?array $schemas)
                        {
                        }

                        public function getId(): string
                        {
                            return $this->id;
                        }

                        public function getSchemas(): ?array
                        {
                            return $this->schemas;
                        }
                    };
                }

                return $all;
            }
        };

        $objectService = new class($log) {
            public function __construct(private array &$log)
            {
            }

            public function deleteObject(string $uuid, string $register, string $schema, bool $_rbac, bool $_multitenancy): bool
            {
                if ($uuid === 'boom') {
                    throw new \RuntimeException('locked row '.$uuid);
                }

                $this->log['deleted'][] = [$uuid, $register, $schema, $_rbac, $_multitenancy];
                return true;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($fail, $schemaMapper, $registerMapper, $objectService): object {
                if ($fail === 'container') {
                    throw new \RuntimeException($id.' is not registered');
                }

                return match ($id) {
                    'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
                    'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
                    'OCA\OpenRegister\Service\ObjectService' => $objectService,
                };
            }
        );

        return $container;

    }//end container()

    /**
     * The database of a world: a query builder that answers per table.
     *
     * @param array<string, mixed> $world The world.
     *
     * @return IDBConnection
     */
    private function db(array $world): IDBConnection
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(
            function () use ($world): IQueryBuilder {
                return $this->queryBuilder($world);
            }
        );

        return $db;

    }//end db()

    /**
     * One query builder: remembers whether it counts and which table it reads.
     *
     * @param array<string, mixed> $world The world.
     *
     * @return IQueryBuilder
     */
    private function queryBuilder(array $world): IQueryBuilder
    {
        $query = ['count' => false, 'table' => ''];
        $qb    = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('select')->willReturnCallback(
            static function (...$selects) use (&$query, $qb): IQueryBuilder {
                $query['count'] = ($selects[0] !== '_uuid');
                return $qb;
            }
        );
        $qb->method('from')->willReturnCallback(
            static function (string $table) use (&$query, $qb): IQueryBuilder {
                $query['table'] = $table;
                return $qb;
            }
        );
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('executeQuery')->willReturnCallback(
            function () use (&$query, $world): IResult {
                $this->log['tables'][] = [$query['table'], ($query['count'] === true) ? 'count' : 'scan'];
                $source = ($query['count'] === true) ? ($world['valid'] ?? []) : ($world['orphans'] ?? []);
                if (array_key_exists($query['table'], $source) === false) {
                    throw new \RuntimeException('no table '.$query['table']);
                }

                $result = $this->createMock(IResult::class);
                if ($query['count'] === true) {
                    $result->method('fetch')->willReturn(['c' => (string) $source[$query['table']]]);
                    return $result;
                }

                $result->method('fetchAll')->willReturn($source[$query['table']]);
                return $result;
            }
        );

        return $qb;

    }//end queryBuilder()
}//end class
