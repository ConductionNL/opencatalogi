<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FederationController.
 */
class FederationControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private PublicationService|MockObject $publicationService;
    private IL10N|MockObject $l10n;
    private FederationController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);
        $this->l10n               = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text, array $params = []) => $text);

        $this->controller = new FederationController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->l10n
        );
    }

    public function testPublicationsReturnsJsonResponse(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $_SERVER['HTTPS']       = '';
        $_SERVER['HTTP_HOST']   = 'localhost';
        $_SERVER['REQUEST_URI'] = '/api/publications';

        $this->publicationService->method('getAggregatedPublications')
            ->willReturn(['results' => [], 'total' => 0]);

        $response = $this->controller->publications();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testPublicationsWithHttps(): void
    {
        $this->request->method('getParams')
            ->willReturn(['page' => '2']);

        $_SERVER['HTTPS']       = 'on';
        $_SERVER['HTTP_HOST']   = 'secure.example.com';
        $_SERVER['REQUEST_URI'] = '/api/publications?page=2';

        $this->publicationService->method('getAggregatedPublications')
            ->willReturn(['results' => [['id' => 1]], 'total' => 1]);

        $response = $this->controller->publications();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testPublicationsReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $_SERVER['HTTPS']       = '';
        $_SERVER['HTTP_HOST']   = 'localhost';
        $_SERVER['REQUEST_URI'] = '/api/publications';

        $this->publicationService->method('getAggregatedPublications')
            ->willThrowException(new \Exception('Service error'));

        $response = $this->controller->publications();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testPublicationReturnsJsonResponse(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedPublication')
            ->with('abc-123', [])
            ->willReturn(['data' => ['id' => 'abc-123'], 'status' => 200]);

        $response = $this->controller->publication('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testPublicationReturns404WhenNotFound(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedPublication')
            ->willReturn(['data' => ['error' => 'Not found'], 'status' => 404]);

        $response = $this->controller->publication('nonexistent');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testPublicationReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedPublication')
            ->willThrowException(new \Exception('Error'));

        $response = $this->controller->publication('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testPublicationUsesReturnsJsonResponse(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedUses')
            ->willReturn(['data' => ['results' => []], 'status' => 200]);

        $response = $this->controller->publicationUses('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testPublicationUsesReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedUses')
            ->willThrowException(new \Exception('Error'));

        $response = $this->controller->publicationUses('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testPublicationUsedReturnsJsonResponse(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedUsed')
            ->willReturn(['data' => ['results' => []], 'status' => 200]);

        $response = $this->controller->publicationUsed('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testPublicationUsedReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->publicationService->method('getFederatedUsed')
            ->willThrowException(new \Exception('Error'));

        $response = $this->controller->publicationUsed('abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testPublicationAttachmentsDelegatesToService(): void
    {
        $expectedResponse = new JSONResponse(['files' => []]);

        $this->publicationService->method('attachments')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->publicationAttachments('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testPublicationDownloadDelegatesToService(): void
    {
        $expectedResponse = new JSONResponse(['download' => 'url']);

        $this->publicationService->method('download')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->publicationDownload('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }
}
