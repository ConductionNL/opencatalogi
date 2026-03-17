<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class DirectoryControllerTest extends TestCase
{
    /** @var MockObject&IRequest */
    private $request;

    /** @var MockObject&DirectoryService */
    private $directoryService;

    /** @var DirectoryController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->directoryService = $this->createMock(DirectoryService::class);

        $this->controller = new DirectoryController(
            'opencatalogi',
            $this->request,
            $this->directoryService
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(DirectoryController::class, $this->controller);
    }

    public function testPreflightedCorsWithOrigin(): void
    {
        $this->request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->controller->preflightedCors();

        $headers = $response->getHeaders();
        $this->assertEquals('https://example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('PUT', $headers['Access-Control-Allow-Methods']);
    }

    public function testPreflightedCorsWithoutOrigin(): void
    {
        $this->request->server = [];

        $response = $this->controller->preflightedCors();

        $headers = $response->getHeaders();
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testIndex(): void
    {
        $directoryData = [
            'results' => [
                ['directory' => 'https://example.com/directory'],
            ],
            'total' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];
        $this->directoryService->method('getDirectory')
            ->with([])
            ->willReturn($directoryData);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($directoryData, $response->getData());
    }

    public function testIndexWithCorsHeaders(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->server = ['HTTP_ORIGIN' => 'https://example.com'];
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $response = $this->controller->index();

        $headers = $response->getHeaders();
        $this->assertEquals('https://example.com', $headers['Access-Control-Allow-Origin']);
    }

    public function testIndexWithError(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];
        $this->directoryService->method('getDirectory')
            ->willThrowException(new \Exception('Database error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Database error', $data['error']);
    }

    public function testUpdateWithoutDirectoryUrl(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn(null);
        $this->request->server = [];

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateWithDirectoryUrl(): void
    {
        $syncResult = ['synced' => 5, 'new' => 2];

        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/directory');
        $this->request->server = [];
        $this->directoryService->method('syncDirectory')
            ->with('https://example.com/directory')
            ->willReturn($syncResult);

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals($syncResult, $data['data']);
    }

    public function testUpdateWithSyncError(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/directory');
        $this->request->server = [];
        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \Exception('Sync failed'));

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }
}
