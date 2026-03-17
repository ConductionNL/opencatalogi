<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class PublicationsControllerTest extends TestCase
{
    /** @var MockObject&IRequest */
    private $request;

    /** @var MockObject&PublicationService */
    private $publicationService;

    /** @var MockObject&DirectoryService */
    private $directoryService;

    /** @var MockObject&CatalogiService */
    private $catalogiService;

    /** @var MockObject&IAppConfig */
    private $config;

    /** @var MockObject&ContainerInterface */
    private $container;

    /** @var MockObject&IAppManager */
    private $appManager;

    /** @var MockObject&LoggerInterface */
    private $logger;

    /** @var MockObject&IDBConnection */
    private $db;

    /** @var PublicationsController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->catalogiService = $this->createMock(CatalogiService::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->controller = new PublicationsController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->directoryService,
            $this->catalogiService,
            $this->config,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->db
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(PublicationsController::class, $this->controller);
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
        $expectedResponse = new JSONResponse([
            'results' => [['id' => '1', 'title' => 'Publication 1']],
            'total' => 1,
        ]);

        $this->publicationService->method('index')
            ->with('test-catalog')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->index('test-catalog');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShow(): void
    {
        $expectedResponse = new JSONResponse(['id' => 'pub-1', 'title' => 'Test Publication']);

        $this->publicationService->method('show')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testAttachments(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('attachments')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->attachments('test-catalog', 'pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testDownload(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('download')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->download('test-catalog', 'pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testUses(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('uses')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->uses('test-catalog', 'pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testUsed(): void
    {
        $expectedResponse = new JSONResponse(['results' => []]);

        $this->publicationService->method('used')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->used('test-catalog', 'pub-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }
}
