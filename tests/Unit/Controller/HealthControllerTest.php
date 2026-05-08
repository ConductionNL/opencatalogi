<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\HealthController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for HealthController.
 */
class HealthControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IDBConnection|MockObject $db;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;
    private \Psr\Container\ContainerInterface|MockObject $container;
    private HealthController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->db         = $this->createMock(IDBConnection::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->container  = $this->createMock(\Psr\Container\ContainerInterface::class);

        $this->controller = new HealthController(
            'opencatalogi',
            $this->request,
            $this->db,
            $this->appManager,
            $this->logger,
            $this->container
        );
    }

    public function testIndexReturnsOkWhenAllChecksPass(): void
    {
        // Mock database check
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');

        $result = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);

        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('1.0.0');

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testIndexReturnsErrorWhenDatabaseFails(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');
        $qb->method('executeQuery')
            ->willThrowException(new \Exception('Connection refused'));

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
    }

    public function testIndexReturnsUnknownVersionOnException(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');

        $result = $this->createMock(IResult::class);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->appManager->method('getAppVersion')
            ->willThrowException(new \Exception('App not found'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }
}
