<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Exception\TransferException;

/**
 * Unit tests for DirectoryController.
 */
class DirectoryControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private DirectoryService|MockObject $directoryService;
    private IL10N|MockObject $l10n;
    private DirectoryController $controller;

    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->l10n             = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text) => $text);

        $this->controller = new DirectoryController(
            'opencatalogi',
            $this->request,
            $this->directoryService,
            $this->l10n
        );
    }

    public function testPreflightedCorsReturnsResponse(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testPreflightedCorsWildcardWhenNoOrigin(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexReturnsJsonResponseSuccess(): void
    {
        $directoryData = ['results' => [['id' => 1]], 'total' => 1];

        $this->request->method('getParams')
            ->willReturn([]);

        $this->directoryService->method('getDirectory')
            ->willReturn($directoryData);

        $this->request->server = ['HTTP_ORIGIN' => 'https://test.com'];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->directoryService->method('getDirectory')
            ->willThrowException(new \Exception('Database error'));

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testUpdateReturnsBadRequestWhenNoDirectoryUrl(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn(null);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturnsBadRequestWithEmptyDirectoryUrl(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('');

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturnsSuccessOnValidSync(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/directory');

        $this->directoryService->method('syncDirectory')
            ->with('https://example.com/directory')
            ->willReturn(['synced' => 5]);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUpdateReturns400OnInvalidArgumentException(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('not-a-url');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \InvalidArgumentException('Invalid URL'));

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturns500OnGenericException(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/dir');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }
}
