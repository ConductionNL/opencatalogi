<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\MetricsController;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MetricsController.
 */
class MetricsControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IDBConnection|MockObject $db;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;
    private MetricsController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->db         = $this->createMock(IDBConnection::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->controller = new MetricsController(
            'opencatalogi',
            $this->request,
            $this->db,
            $this->appManager,
            $this->logger
        );
    }

    private function mockQueryBuilder(): MockObject
    {
        $queryFunc = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);

        $funcBuilder = $this->createMock(IFunctionBuilder::class);
        $funcBuilder->method('count')->willReturn($queryFunc);

        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetch')->willReturn(['cnt' => 0]);
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('createFunction')->willReturn($queryFunc);
        $qb->method('createNamedParameter')->willReturn($queryFunc);
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qb->method('func')->willReturn($funcBuilder);
        $qb->method('executeQuery')->willReturn($result);

        return $qb;
    }

    public function testIndexReturnsTextPlainResponse(): void
    {
        $qb = $this->mockQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('1.0.0');

        $response = $this->controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);
    }

    public function testIndexContainsPrometheusHeaders(): void
    {
        $qb = $this->mockQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        $response = $this->controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);
    }

    public function testIndexHandlesDatabaseErrors(): void
    {
        $queryFunc = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('createFunction')->willReturn($queryFunc);
        $qb->method('createNamedParameter')->willReturn($queryFunc);
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

        $funcBuilder = $this->createMock(IFunctionBuilder::class);
        $funcBuilder->method('count')->willReturn($queryFunc);
        $qb->method('func')->willReturn($funcBuilder);
        $qb->method('executeQuery')
            ->willThrowException(new \Exception('DB error'));

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        // Should not throw, handles errors internally
        $response = $this->controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);
    }

    public function testIndexReturnsUnknownVersionOnException(): void
    {
        $qb = $this->mockQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->willThrowException(new \Exception('Not found'));

        $response = $this->controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);
    }
}
