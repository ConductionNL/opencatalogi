<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SearchController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchController.
 */
class SearchControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private PublicationService|MockObject $publicationService;
    private SearchController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);

        $this->controller = new SearchController(
            'opencatalogi',
            $this->request,
            $this->publicationService
        );
    }

    public function testIndexDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('index')
            ->with(null)
            ->willReturn($expectedResponse);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testIndexWithCatalogId(): void
    {
        $expectedResponse = new JSONResponse(['results' => [['id' => 'pub-1']], 'total' => 1]);

        $this->publicationService->method('index')
            ->with('catalog-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->index('catalog-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testIndexWithNullCatalogId(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->expects($this->once())
            ->method('index')
            ->with(null)
            ->willReturn($expectedResponse);

        $response = $this->controller->index(null);

        $this->assertSame($expectedResponse, $response);
    }

    public function testShowDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['id' => 'pub-123', 'title' => 'Test']);

        $this->publicationService->method('show')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->show('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testShowWithUuid(): void
    {
        $uuid             = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $expectedResponse = new JSONResponse(['uuid' => $uuid]);

        $this->publicationService->expects($this->once())
            ->method('show')
            ->with($uuid)
            ->willReturn($expectedResponse);

        $response = $this->controller->show($uuid);

        $this->assertSame($expectedResponse, $response);
    }

    public function testAttachmentsDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['files' => [['name' => 'doc.pdf']]]);

        $this->publicationService->method('attachments')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->attachments('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testAttachmentsWithEmptyResult(): void
    {
        $expectedResponse = new JSONResponse(['files' => []]);

        $this->publicationService->expects($this->once())
            ->method('attachments')
            ->with('pub-empty')
            ->willReturn($expectedResponse);

        $response = $this->controller->attachments('pub-empty');

        $this->assertSame($expectedResponse, $response);
    }

    public function testDownloadReturnsJsonResponse(): void
    {
        $expectedResponse = new JSONResponse(['url' => 'http://example.com/file.pdf']);

        $this->publicationService->method('download')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->download('pub-123');

        $this->assertSame($expectedResponse, $response);
    }

    public function testDownloadReturnsDataDownloadResponse(): void
    {
        $expectedResponse = new DataDownloadResponse('file-content', 'document.pdf', 'application/pdf');

        $this->publicationService->method('download')
            ->with('pub-456')
            ->willReturn($expectedResponse);

        $response = $this->controller->download('pub-456');

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testUsesDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('uses')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->uses('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testUsesWithRelatedObjects(): void
    {
        $expectedResponse = new JSONResponse([
            'results' => [['id' => 'obj-1'], ['id' => 'obj-2']],
            'total'   => 2,
        ]);

        $this->publicationService->expects($this->once())
            ->method('uses')
            ->with('pub-789')
            ->willReturn($expectedResponse);

        $response = $this->controller->uses('pub-789');

        $this->assertSame($expectedResponse, $response);
    }

    public function testUsedDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('used')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->used('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }

    public function testUsedWithReferencingObjects(): void
    {
        $expectedResponse = new JSONResponse([
            'results' => [['id' => 'ref-1']],
            'total'   => 1,
        ]);

        $this->publicationService->expects($this->once())
            ->method('used')
            ->with('pub-999')
            ->willReturn($expectedResponse);

        $response = $this->controller->used('pub-999');

        $this->assertSame($expectedResponse, $response);
    }
}
