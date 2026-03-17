<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\SearchController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SearchControllerTest extends TestCase
{
    /** @var MockObject&IRequest */
    private $request;

    /** @var MockObject&PublicationService */
    private $publicationService;

    /** @var SearchController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);

        $this->controller = new SearchController(
            'opencatalogi',
            $this->request,
            $this->publicationService
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(SearchController::class, $this->controller);
    }

    public function testIndex(): void
    {
        $expectedResponse = new JSONResponse([
            'results' => [['id' => '1', 'title' => 'Test']],
            'total' => 1,
        ]);

        $this->publicationService->method('index')
            ->with(null)
            ->willReturn($expectedResponse);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(
            ['results' => [['id' => '1', 'title' => 'Test']], 'total' => 1],
            $response->getData()
        );
    }

    public function testIndexWithCatalogId(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('index')
            ->with('catalog-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->index('catalog-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShow(): void
    {
        $expectedResponse = new JSONResponse(['id' => 'pub-1', 'title' => 'Test Publication']);

        $this->publicationService->method('show')
            ->willReturn($expectedResponse);

        $response = $this->controller->show('pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(
            ['id' => 'pub-1', 'title' => 'Test Publication'],
            $response->getData()
        );
    }

    public function testAttachments(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('attachments')
            ->willReturn($expectedResponse);

        $response = $this->controller->attachments('pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testDownload(): void
    {
        $expectedResponse = new JSONResponse(['url' => 'https://example.com/file.pdf']);

        $this->publicationService->method('download')
            ->willReturn($expectedResponse);

        $response = $this->controller->download('pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testUses(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('uses')
            ->willReturn($expectedResponse);

        $response = $this->controller->uses('pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testUsed(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('used')
            ->willReturn($expectedResponse);

        $response = $this->controller->used('pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }
}
