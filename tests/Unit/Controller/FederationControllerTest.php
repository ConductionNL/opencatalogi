<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for FederationController.
 */
class FederationControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private PublicationService|MockObject $publicationService;
	private IL10N|MockObject $l10n;
	/**
	 * The container the cache headers read the session and config from.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;
	private FederationController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->publicationService = $this->createMock(PublicationService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);

		$this->l10n->method('t')
			->willReturnCallback(fn (string $text, array $params = []) => $text);

		$this->controller = new FederationController(
			'opencatalogi',
			$this->request,
			$this->publicationService,
			$this->l10n,
			$this->container
		);
	}

	/**
	 * A session and a config the cache headers read, as a given caller.
	 *
	 * @param boolean $signedIn Whether the caller is signed in.
	 *
	 * @return void
	 */
	private function callerIs(bool $signedIn): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('isLoggedIn')->willReturn($signedIn);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				IUserSession::class => $session,
				IAppConfig::class => $config,
				default => throw new \RuntimeException('not available'),
			}
		);
	}

	/**
	 * A publication read through the federation route, as a given caller.
	 *
	 * @param boolean $signedIn    Whether the caller is signed in.
	 * @param string  $ifNoneMatch The If-None-Match header the caller sends.
	 *
	 * @return JSONResponse The answer.
	 */
	private function publicationAs(bool $signedIn, string $ifNoneMatch = ''): JSONResponse {
		$this->callerIs(signedIn: $signedIn);
		$this->request->method('getParams')->willReturn(['_extend' => '@self.schema']);
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name) => match ($name) {
				'If-None-Match' => $ifNoneMatch,
				default => '',
			}
		);
		$this->publicationService->method('getFederatedPublication')
			->willReturn(['data' => ['id' => 'pub-1', '@self' => ['schema' => ['properties' => []]]], 'status' => 200]);

		return $this->controller->publication('pub-1');
	}

	/**
	 * An anonymous publication answer of the federation route may be cached publicly (REQ-PAC-001).
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/public-api-caching/spec.md#requirement-anonymous-answers-of-the-public-api-may-be-cached-req-pac-001
	 *
	 * @return void
	 */
	public function testAnAnonymousFederatedPublicationIsPubliclyCacheable(): void {
		$response = $this->publicationAs(signedIn: false);
		$headers = $response->getHeaders();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'public, max-age=60, must-revalidate', actual: $headers['Cache-Control']);
		$this->assertArrayHasKey(key: 'ETag', array: $headers);
		$this->assertSame(expected: 'Origin, Accept, Accept-Language', actual: $headers['Vary']);
	}

	/**
	 * A matching If-None-Match turns the federated publication into a 304 (REQ-PAC-001).
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/public-api-caching/spec.md#requirement-anonymous-answers-of-the-public-api-may-be-cached-req-pac-001
	 *
	 * @return void
	 */
	public function testAMatchingIfNoneMatchOnTheFederationRouteAnswers304(): void {
		$tag = $this->publicationAs(signedIn: false)->getHeaders()['ETag'];

		$this->setUp();
		$response = $this->publicationAs(signedIn: false, ifNoneMatch: $tag);

		$this->assertSame(expected: 304, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $response->getData());
	}

	/**
	 * A signed-in federated publication answer stays private (REQ-PAC-002).
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/public-api-caching/spec.md#requirement-answers-to-a-signed-in-user-stay-private-req-pac-002
	 *
	 * @return void
	 */
	public function testASignedInFederatedPublicationStaysPrivate(): void {
		$headers = $this->publicationAs(signedIn: true)->getHeaders();

		$this->assertSame(expected: 'private, no-store', actual: $headers['Cache-Control']);
		$this->assertArrayNotHasKey(key: 'ETag', array: $headers);
	}

	/**
	 * An anonymous attachments answer of the federation route may be cached publicly (REQ-PAC-001).
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/public-api-caching/spec.md#requirement-anonymous-answers-of-the-public-api-may-be-cached-req-pac-001
	 *
	 * @return void
	 */
	public function testAnAnonymousFederatedAttachmentsAnswerIsPubliclyCacheable(): void {
		$this->callerIs(signedIn: false);
		$this->request->method('getHeader')->willReturn('');
		$this->publicationService->method('attachments')
			->willReturn(new JSONResponse(['results' => [['name' => 'file.pdf']]], 200));

		$headers = $this->controller->publicationAttachments('pub-1')->getHeaders();

		$this->assertSame(expected: 'public, max-age=60, must-revalidate', actual: $headers['Cache-Control']);
		$this->assertArrayHasKey(key: 'ETag', array: $headers);
	}

	public function testPublicationsReturnsJsonResponse(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$_SERVER['HTTPS'] = '';
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['REQUEST_URI'] = '/api/publications';

		$this->publicationService->method('getAggregatedPublications')
			->willReturn(['results' => [], 'total' => 0]);

		$response = $this->controller->publications();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testPublicationsWithHttps(): void {
		$this->request->method('getParams')
			->willReturn(['page' => '2']);

		$_SERVER['HTTPS'] = 'on';
		$_SERVER['HTTP_HOST'] = 'secure.example.com';
		$_SERVER['REQUEST_URI'] = '/api/publications?page=2';

		$this->publicationService->method('getAggregatedPublications')
			->willReturn(['results' => [['id' => 1]], 'total' => 1]);

		$response = $this->controller->publications();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testPublicationsReturns500OnException(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$_SERVER['HTTPS'] = '';
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['REQUEST_URI'] = '/api/publications';

		$this->publicationService->method('getAggregatedPublications')
			->willThrowException(new \Exception('Service error'));

		$response = $this->controller->publications();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}

	public function testPublicationReturnsJsonResponse(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedPublication')
			->with('abc-123', [])
			->willReturn(['data' => ['id' => 'abc-123'], 'status' => 200]);

		$response = $this->controller->publication('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testPublicationReturns404WhenNotFound(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedPublication')
			->willReturn(['data' => ['error' => 'Not found'], 'status' => 404]);

		$response = $this->controller->publication('nonexistent');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
	}

	public function testPublicationReturns500OnException(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedPublication')
			->willThrowException(new \Exception('Error'));

		$response = $this->controller->publication('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}

	public function testPublicationUsesReturnsJsonResponse(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedUses')
			->willReturn(['data' => ['results' => []], 'status' => 200]);

		$response = $this->controller->publicationUses('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testPublicationUsesReturns500OnException(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedUses')
			->willThrowException(new \Exception('Error'));

		$response = $this->controller->publicationUses('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}

	public function testPublicationUsedReturnsJsonResponse(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedUsed')
			->willReturn(['data' => ['results' => []], 'status' => 200]);

		$response = $this->controller->publicationUsed('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testPublicationUsedReturns500OnException(): void {
		$this->request->method('getParams')
			->willReturn([]);

		$this->publicationService->method('getFederatedUsed')
			->willThrowException(new \Exception('Error'));

		$response = $this->controller->publicationUsed('abc-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}

	public function testPublicationAttachmentsDelegatesToService(): void {
		$expectedResponse = new JSONResponse(['files' => []]);

		$this->publicationService->method('attachments')
			->with('pub-123')
			->willReturn($expectedResponse);

		$response = $this->controller->publicationAttachments('pub-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testPublicationDownloadDelegatesToService(): void {
		$expectedResponse = new JSONResponse(['download' => 'url']);

		$this->publicationService->method('download')
			->with('pub-123')
			->willReturn($expectedResponse);

		$response = $this->controller->publicationDownload('pub-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
	}
}
