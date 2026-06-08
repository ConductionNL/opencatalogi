<?php
/**
 * Unit tests for PublicationQueryService.
 *
 * Covers: isAnonymous, isObjectPublic, enforcePublishedForAnonymous, findObjectLocation.
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
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PublicationQueryService.
 *
 * Focuses on security-relevant predicates: anonymous detection, object visibility,
 * published-predicate enforcement, and the constrained findObjectLocation query.
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
     * User session mock.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;

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
        $this->db          = $this->createMock(IDBConnection::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->service = new PublicationQueryService(
            db: $this->db,
            container: $this->container,
            userSession: $this->userSession
        );

    }//end setUp()

    // -------------------------------------------------------------------------
    // isAnonymous() tests
    // -------------------------------------------------------------------------

    /**
     * IsAnonymous returns true when no user is logged in.
     *
     * @return void
     */
    public function testIsAnonymousReturnsTrueWhenNotLoggedIn(): void
    {
        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->assertTrue($this->service->isAnonymous());

    }//end testIsAnonymousReturnsTrueWhenNotLoggedIn()

    /**
     * IsAnonymous returns false when a user is logged in.
     *
     * @return void
     */
    public function testIsAnonymousReturnsFalseWhenLoggedIn(): void
    {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->assertFalse($this->service->isAnonymous());

    }//end testIsAnonymousReturnsFalseWhenLoggedIn()

    // -------------------------------------------------------------------------
    // isObjectPublic() tests
    // -------------------------------------------------------------------------

    /**
     * IsObjectPublic returns false when @self.published is absent.
     *
     * @return void
     */
    public function testIsObjectPublicReturnsFalseWithNoPublished(): void
    {
        $object = ['@self' => []];
        $this->assertFalse($this->service->isObjectPublic($object));

    }//end testIsObjectPublicReturnsFalseWithNoPublished()

    /**
     * IsObjectPublic returns false when @self.published is in the future.
     *
     * @return void
     */
    public function testIsObjectPublicReturnsFalseWithFuturePublished(): void
    {
        $object = ['@self' => ['published' => '2099-01-01T00:00:00Z']];
        $this->assertFalse($this->service->isObjectPublic($object));

    }//end testIsObjectPublicReturnsFalseWithFuturePublished()

    /**
     * IsObjectPublic returns true when published in the past and no depublished set.
     *
     * @return void
     */
    public function testIsObjectPublicReturnsTrueWhenPublishedInPast(): void
    {
        $object = ['@self' => ['published' => '2000-01-01T00:00:00Z']];
        $this->assertTrue($this->service->isObjectPublic($object));

    }//end testIsObjectPublicReturnsTrueWhenPublishedInPast()

    /**
     * IsObjectPublic returns false when depublished is in the past (already depublished).
     *
     * @return void
     */
    public function testIsObjectPublicReturnsFalseWhenAlreadyDepublished(): void
    {
        $object = [
            '@self' => [
                'published'   => '2000-01-01T00:00:00Z',
                'depublished' => '2001-01-01T00:00:00Z',
            ],
        ];
        $this->assertFalse($this->service->isObjectPublic($object));

    }//end testIsObjectPublicReturnsFalseWhenAlreadyDepublished()

    /**
     * IsObjectPublic returns true when depublished is in the future.
     *
     * @return void
     */
    public function testIsObjectPublicReturnsTrueWhenDepublishedInFuture(): void
    {
        $object = [
            '@self' => [
                'published'   => '2000-01-01T00:00:00Z',
                'depublished' => '2099-01-01T00:00:00Z',
            ],
        ];
        $this->assertTrue($this->service->isObjectPublic($object));

    }//end testIsObjectPublicReturnsTrueWhenDepublishedInFuture()

    // -------------------------------------------------------------------------
    // enforcePublishedForAnonymous() tests
    // -------------------------------------------------------------------------

    /**
     * EnforcePublishedForAnonymous is a no-op for authenticated callers.
     *
     * @return void
     */
    public function testEnforcePublishedForAnonymousSkipsWhenAuthenticated(): void
    {
        $this->userSession->method('isLoggedIn')->willReturn(true);

        $result = [
            'results' => [
                ['@self' => []],
            ],
            'total'   => 1,
        ];

        $filtered = $this->service->enforcePublishedForAnonymous($result);
        $this->assertCount(1, $filtered['results']);

    }//end testEnforcePublishedForAnonymousSkipsWhenAuthenticated()

    /**
     * EnforcePublishedForAnonymous removes unpublished items for anonymous callers.
     *
     * @return void
     */
    public function testEnforcePublishedForAnonymousFiltersUnpublishedItems(): void
    {
        $this->userSession->method('isLoggedIn')->willReturn(false);

        $result = [
            'results' => [
                ['@self' => ['published' => '2000-01-01T00:00:00Z']],
                ['@self' => []],
                ['@self' => ['published' => '2099-01-01T00:00:00Z']],
            ],
            'total'   => 3,
            'count'   => 3,
        ];

        $filtered = $this->service->enforcePublishedForAnonymous($result);
        $this->assertCount(1, $filtered['results']);
        $this->assertSame(1, $filtered['total']);

    }//end testEnforcePublishedForAnonymousFiltersUnpublishedItems()

    /**
     * EnforcePublishedForAnonymous adjusts total downward by removed item count.
     *
     * @return void
     */
    public function testEnforcePublishedForAnonymousAdjustsTotalCount(): void
    {
        $this->userSession->method('isLoggedIn')->willReturn(false);

        $result = [
            'results' => [
                ['@self' => ['published' => '2000-01-01T00:00:00Z']],
                ['@self' => ['published' => '2000-06-01T00:00:00Z']],
                ['@self' => []],
            ],
            'total'   => 10,
            'count'   => 3,
        ];

        $filtered = $this->service->enforcePublishedForAnonymous($result);
        $this->assertCount(2, $filtered['results']);
        // Total reduced by 1 (one item removed).
        $this->assertSame(9, $filtered['total']);

    }//end testEnforcePublishedForAnonymousAdjustsTotalCount()

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
                $expr->method('andWhere')->willReturn('andWhere');

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
}//end class
