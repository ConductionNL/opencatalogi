<?php
/**
 * Unit tests for PublicationQueryService.
 *
 * Covers: findObjectLocation (the constrained object-location query) and isObjectPublic
 * (the public-relation-endpoint visibility guard). Bulk visibility filtering lives in
 * OpenRegister RBAC; isObjectPublic mirrors the same RBAC rule
 * (`publicatiedatum <= now`, APB-006) for the per-object guard on the public uses/used
 * relation endpoints. The removed object-level @self.published predicate is not consulted.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PublicationQueryService.
 *
 * Focuses on the constrained findObjectLocation query (#734). Object visibility is now
 * enforced by OpenRegister RBAC, not by an app-side published predicate.
 */
class PublicationQueryServiceTest extends TestCase
{

    /**
     * Database connection mock.
     *
     * @var IDBConnection|MockObject
     */
    private IDBConnection|MockObject $db;

    /**
     * DI container mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * Service under test.
     *
     * @var PublicationQueryService
     */
    private PublicationQueryService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db        = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);

        $this->service = new PublicationQueryService(
            db: $this->db,
            container: $this->container
        );

    }//end setUp()

    // -------------------------------------------------------------------------
    // findObjectLocation() tests
    // -------------------------------------------------------------------------

    /**
     * Security (#734): findObjectLocation MUST return null without touching the
     * database when no constraint is supplied.
     *
     * @return void
     */
    public function testFindObjectLocationFailsClosedWithoutConstraint(): void
    {
        // The DB must NOT be touched at all.
        $this->db->expects($this->never())->method('executeQuery');
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertNull($this->service->findObjectLocation('any-uuid'));
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [1], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [1])
        );

    }//end testFindObjectLocationFailsClosedWithoutConstraint()

    /**
     * Security (#734): constrained lookup queries only the expected magic table.
     *
     * @return void
     */
    public function testFindObjectLocationLooksUpOnlyConstrainedTables(): void
    {
        // Stub magicTableExists to claim only one table exists.
        $this->stubMagicTableExists(['oc_openregister_table_21_11' => true]);

        $resultRow   = ['register_id' => 21, 'schema_id' => 11];
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

        $this->assertSame(expected: ['register' => 21, 'schema' => 11], actual: $location);

    }//end testFindObjectLocationLooksUpOnlyConstrainedTables()

    /**
     * Security (#734): returns null when no magic tables exist for the constraints.
     *
     * @return void
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

    }//end testFindObjectLocationReturnsNullWhenNoTablesExist()

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Stub the IQueryBuilder chain used by magicTableExists().
     *
     * @param array<string, bool> $tableExistence Map of table_name => exists?
     *
     * @return void
     */
    private function stubMagicTableExists(array $tableExistence): void
    {
        $this->db->method('getQueryBuilder')->willReturnCallback(
            function () use ($tableExistence) {
                $qb          = $this->createMock(IQueryBuilder::class);
                $funcBuilder = $this->createMock(IFunctionBuilder::class);
                $funcBuilder->method('count')->willReturn($this->createMock(IQueryFunction::class));
                $expr = $this->createMock(IExpressionBuilder::class);
                $expr->method('eq')->willReturn('eq');
                // NOTE: andWhere() lives on the query builder ($qb->andWhere()), not on
                // the expression builder — IExpressionBuilder has no such method, so a
                // stub here raises MethodCannotBeConfigured. The $qb->andWhere() stub is
                // configured below.

                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnSelf();
                $qb->method('func')->willReturn($funcBuilder);
                $qb->method('expr')->willReturn($expr);
                $qb->method('createNamedParameter')->willReturnCallback(fn($v) => $v);
                $qb->method('createFunction')->willReturn('DATABASE()');
                $qb->method('where')->willReturnSelf();
                $qb->method('andWhere')->willReturnSelf();

                $qb->method('executeQuery')->willReturnCallback(
                    function () use ($tableExistence) {
                        $result = $this->createMock(IResult::class);
                        // If any tableExistence entry is true return 1, else 0.
                        $anyExists = (array_filter($tableExistence) !== []);
                        $cntValue  = 0;
                        if ($anyExists === true) {
                            $cntValue = 1;
                        }

                        $result->method('fetch')->willReturn(['cnt' => $cntValue]);
                        $result->method('closeCursor');
                        return $result;
                    }
                );

                return $qb;
            }
        );

    }//end stubMagicTableExists()

    // -------------------------------------------------------------------------
    // isObjectPublic() tests — RBAC publicatiedatum model (APB-006)
    // -------------------------------------------------------------------------

    /**
     * A past publicatiedatum with no depublicatiedatum is publicly visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithPastPublicatiedatum(): void
    {
        $this->assertTrue(
            $this->service->isObjectPublic(['publicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithPastPublicatiedatum()

    /**
     * No publicatiedatum means the object is not public (concept).
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithoutPublicatiedatum(): void
    {
        $this->assertFalse($this->service->isObjectPublic([]));
        $this->assertFalse(
            $this->service->isObjectPublic(['depublicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithoutPublicatiedatum()

    /**
     * A future publicatiedatum (embargo) is not yet public.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithFuturePublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);
        $this->assertFalse($this->service->isObjectPublic(['publicatiedatum' => $future]));

    }//end testIsObjectPublicWithFuturePublicatiedatum()

    /**
     * A reached depublicatiedatum hides the object; a future one keeps it visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicRespectsDepublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        $this->assertFalse(
            $this->service->isObjectPublic(
                [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => '2024-06-01T10:00:00+00:00',
                ]
            )
        );

        $this->assertTrue(
            $this->service->isObjectPublic(
                [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => $future,
                ]
            )
        );

    }//end testIsObjectPublicRespectsDepublicatiedatum()
}//end class
