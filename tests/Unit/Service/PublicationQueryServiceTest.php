<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\IResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PublicationQueryService — focused on the findObjectLocation
 * constraint behaviour added for #734 (no platform-wide table scan).
 */
class PublicationQueryServiceTest extends TestCase
{

    private IDBConnection|MockObject $db;
    private ContainerInterface|MockObject $container;
    private IUserSession|MockObject $userSession;
    private PublicationQueryService $service;

    protected function setUp(): void
    {
        $this->db          = $this->createMock(IDBConnection::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->service = new PublicationQueryService(
            $this->db,
            $this->container,
            $this->userSession
        );
    }

    /**
     * Security (#734): findObjectLocation MUST return null without touching the
     * database when no constraint is supplied. The legacy platform-wide table
     * scan is gone.
     */
    public function testFindObjectLocationFailsClosedWithoutConstraint(): void
    {
        // The DB must NOT be touched at all.
        $this->db->expects($this->never())->method('executeQuery');
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertNull($this->service->findObjectLocation('any-uuid'));
        $this->assertNull(
            $this->service->findObjectLocation('any-uuid', allowedRegisters: [], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation('any-uuid', allowedRegisters: [1], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation('any-uuid', allowedRegisters: [], allowedSchemas: [1])
        );
    }

    /**
     * Security (#734): when a constraint IS supplied, the lookup is bounded to
     * the catalog's (register × schema) magic tables — no information_schema
     * pattern scan. The deterministic table name is the only thing queried.
     */
    public function testFindObjectLocationLooksUpOnlyConstrainedTables(): void
    {
        // Stub magicTableExists to claim only one table exists, then assert the
        // UNION-ALL query runs once and returns its result.
        $this->stubMagicTableExists(['oc_openregister_table_21_11' => true]);

        $resultRow = ['register_id' => 21, 'schema_id' => 11];
        $unionResult = $this->createMock(IResult::class);
        $unionResult->method('fetch')->willReturn($resultRow);
        $unionResult->method('closeCursor');

        $this->db->method('quote')->willReturn("'uuid-found'");
        $this->db->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('oc_openregister_table_21_11'))
            ->willReturn($unionResult);

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [21],
            allowedSchemas: [11]
        );

        $this->assertSame(['register' => 21, 'schema' => 11], $location);
    }

    /**
     * Security (#734): non-existent tables are skipped — they never appear in
     * the UNION query. When every (register × schema) pair has no backing table,
     * findObjectLocation returns null without running any UNION query.
     */
    public function testFindObjectLocationReturnsNullWhenNoTablesExist(): void
    {
        $this->stubMagicTableExists([]);

        // No UNION query should ever execute — only the existence probes.
        $this->db->expects($this->never())->method('executeQuery');

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-missing',
            allowedRegisters: [1],
            allowedSchemas: [2]
        );

        $this->assertNull($location);
    }

    /**
     * Helper: stub the IQueryBuilder chain used by magicTableExists().
     *
     * @param array<string, bool> $tableExistence Map of table_name => exists?
     */
    private function stubMagicTableExists(array $tableExistence): void
    {
        $this->db->method('getQueryBuilder')->willReturnCallback(function () use ($tableExistence) {
            $qb = $this->createMock(IQueryBuilder::class);
            $funcBuilder = $this->createMock(IFunctionBuilder::class);
            $funcBuilder->method('count')->willReturn($this->createMock(IQueryFunction::class));
            $expr = $this->createMock(IExpressionBuilder::class);
            $expr->method('eq')->willReturn('eq');

            $qb->method('select')->willReturnSelf();
            $qb->method('from')->willReturnSelf();
            $qb->method('func')->willReturn($funcBuilder);
            $qb->method('expr')->willReturn($expr);
            $qb->method('createNamedParameter')->willReturnCallback(fn($v) => $v);

            // Use the *most recently bound* table name (createNamedParameter)
            // to decide the exists value via where().
            $boundTable = null;
            $qb->method('where')->willReturnCallback(function () use (&$boundTable, $qb) {
                return $qb;
            });

            // We can't easily intercept createNamedParameter to pull the table name
            // back out — but we don't need to. executeQuery returns the configured
            // existence for ALL tables in this stub.
            $qb->method('executeQuery')->willReturnCallback(function () use ($tableExistence) {
                $result = $this->createMock(IResult::class);
                // Map total count: true => 1, false/absent => 0.
                // If any tableExistence entry is true, return 1; else 0.
                $anyExists = !empty(array_filter($tableExistence));
                $result->method('fetch')->willReturn(['cnt' => $anyExists ? 1 : 0]);
                $result->method('closeCursor');
                return $result;
            });

            return $qb;
        });
    }
}
