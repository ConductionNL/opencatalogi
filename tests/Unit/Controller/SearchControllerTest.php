<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SearchController;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SearchController.
 */
class SearchControllerTest extends TestCase
{

    private IRequest|MockObject $request;

    private PublicationService|MockObject $publicationService;

    private IUserSession|MockObject $userSession;

    private IL10N|MockObject $l10n;

    private PublicationQueryService|MockObject $queryService;

    private ContainerInterface|MockObject $container;

    private IAppManager|MockObject $appManager;

    private LoggerInterface|MockObject $logger;

    private SearchController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->l10n         = $this->createMock(IL10N::class);
        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        // L10n->t() returns the source string unchanged for assertions.
        $this->l10n->method('t')
            ->willReturnArgument(0);

        // Show()/attachments()/etc. guard on an authenticated user; default to logged-in.
        $this->userSession->method('getUser')
            ->willReturn($this->createMock(\OCP\IUser::class));

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->controller = new SearchController(
            appName: 'opencatalogi',
            request: $this->request,
            publicationService: $this->publicationService,
            userSession: $this->userSession,
            l10n: $this->l10n,
            queryService: $this->queryService,
            container: $this->container,
            appManager: $this->appManager,
            logger: $this->logger
        );
    }//end setUp()

    /**
     * Anonymous callers get the assembled envelope with HTTP 200.
     *
     * @return void
     */
    public function testIndexReturnsAssembledSearchResultsForAnonymousCallers(): void
    {
        $objectService = new \stdClass();
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->request->method('getParams')->willReturn(['_search' => 'jaarverslag']);

        $expected = ['results' => [['@self' => ['schema' => 'publication']]], 'total' => 1];
        $this->queryService->expects($this->once())
            ->method('assemblePublicSearchResults')
            ->with(['_search' => 'jaarverslag'], $objectService)
            ->willReturn($expected);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expected, $response->getData());
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexReturnsAssembledSearchResultsForAnonymousCallers()

    /**
     * The index() endpoint must be reachable without a session user — no auth guard.
     *
     * @return void
     */
    public function testIndexNeverReturns401(): void
    {
        // Index() must be reachable without a session user — no auth guard.
        $this->userSession->method('getUser')->willReturn(null);

        $objectService = new \stdClass();
        $this->container->method('get')->willReturn($objectService);
        $this->request->method('getParams')->willReturn([]);
        $this->queryService->method('assemblePublicSearchResults')
            ->willReturn(['results' => [], 'total' => 0]);

        $response = $this->controller->index();

        $this->assertNotSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexNeverReturns401()

    /**
     * When OpenRegister is not installed, index() returns a generic 500 body.
     *
     * @return void
     */
    public function testIndexReturnsGenericErrorWhenOpenRegisterUnavailable(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn([]);
        $this->logger->expects($this->once())->method('error');

        $controller = new SearchController(
            appName: 'opencatalogi',
            request: $this->request,
            publicationService: $this->publicationService,
            userSession: $this->userSession,
            l10n: $this->l10n,
            queryService: $this->queryService,
            container: $this->container,
            appManager: $appManager,
            logger: $this->logger
        );

        $response = $controller->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame(['error' => 'Internal server error'], $response->getData());
    }//end testIndexReturnsGenericErrorWhenOpenRegisterUnavailable()

    public function testShowDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['id' => 'pub-123', 'title' => 'Test']);

        $this->publicationService->method('show')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->show('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }//end testShowDelegatesToPublicationService()

    public function testShowWithUuid(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $expectedResponse = new JSONResponse(['uuid' => $uuid]);

        $this->publicationService->expects($this->once())
            ->method('show')
            ->with($uuid)
            ->willReturn($expectedResponse);

        $response = $this->controller->show($uuid);

        $this->assertSame($expectedResponse, $response);
    }//end testShowWithUuid()

    public function testAttachmentsDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['files' => [['name' => 'doc.pdf']]]);

        $this->publicationService->method('attachments')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->attachments('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }//end testAttachmentsDelegatesToPublicationService()

    public function testAttachmentsWithEmptyResult(): void
    {
        $expectedResponse = new JSONResponse(['files' => []]);

        $this->publicationService->expects($this->once())
            ->method('attachments')
            ->with('pub-empty')
            ->willReturn($expectedResponse);

        $response = $this->controller->attachments('pub-empty');

        $this->assertSame($expectedResponse, $response);
    }//end testAttachmentsWithEmptyResult()

    public function testDownloadReturnsJsonResponse(): void
    {
        $expectedResponse = new JSONResponse(['url' => 'http://example.com/file.pdf']);

        $this->publicationService->method('download')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->download('pub-123');

        $this->assertSame($expectedResponse, $response);
    }//end testDownloadReturnsJsonResponse()

    public function testDownloadReturnsDataDownloadResponse(): void
    {
        $expectedResponse = new DataDownloadResponse('file-content', 'document.pdf', 'application/pdf');

        $this->publicationService->method('download')
            ->with('pub-456')
            ->willReturn($expectedResponse);

        $response = $this->controller->download('pub-456');

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }//end testDownloadReturnsDataDownloadResponse()

    public function testUsesDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('uses')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->uses('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }//end testUsesDelegatesToPublicationService()

    public function testUsesWithRelatedObjects(): void
    {
        $expectedResponse = new JSONResponse(
                [
                    'results' => [['id' => 'obj-1'], ['id' => 'obj-2']],
                    'total'   => 2,
                ]
                );

        $this->publicationService->expects($this->once())
            ->method('uses')
            ->with('pub-789')
            ->willReturn($expectedResponse);

        $response = $this->controller->uses('pub-789');

        $this->assertSame($expectedResponse, $response);
    }//end testUsesWithRelatedObjects()

    public function testUsedDelegatesToPublicationService(): void
    {
        $expectedResponse = new JSONResponse(['results' => [], 'total' => 0]);

        $this->publicationService->method('used')
            ->with('pub-123')
            ->willReturn($expectedResponse);

        $response = $this->controller->used('pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame($expectedResponse, $response);
    }//end testUsedDelegatesToPublicationService()

    public function testUsedWithReferencingObjects(): void
    {
        $expectedResponse = new JSONResponse(
                [
                    'results' => [['id' => 'ref-1']],
                    'total'   => 1,
                ]
                );

        $this->publicationService->expects($this->once())
            ->method('used')
            ->with('pub-999')
            ->willReturn($expectedResponse);

        $response = $this->controller->used('pub-999');

        $this->assertSame($expectedResponse, $response);
    }//end testUsedWithReferencingObjects()
}//end class
