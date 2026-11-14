<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Unit\Support\ServerAwareRequest;

/**
 * Unit tests for FederationController.
 */
class FederationControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private PublicationService|MockObject $publicationService;
	private IL10N|MockObject $l10n;
	private FederationController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->publicationService = $this->createMock(PublicationService::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->l10n->method('t')
			->willReturnCallback(fn (string $text, array $params = []) => $text);

		$this->controller = new FederationController(
			$this->request,
			$this->publicationService,
			$this->l10n
		);
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

	/**
	 * The attachments come from the federated lookup with the request's parameters,
	 * so a remote publication's attachments are read from its source.
	 *
	 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-attachments-of-a-remote-publication-are-listed-from-the-source-req-for-002
	 */
	public function testPublicationAttachmentsDelegatesToTheFederatedLookup(): void {
		$expectedResponse = new JSONResponse(['results' => []]);
		$this->request->method('getParams')->willReturn(['_aggregate' => 'false']);

		$this->publicationService->expects($this->never())->method('attachments');
		$this->publicationService->expects($this->once())
			->method('getFederatedAttachments')
			->with('pub-123', ['_aggregate' => 'false'])
			->willReturn($expectedResponse);

		$this->assertSame($expectedResponse, $this->controller->publicationAttachments('pub-123'));
	}

	public function testPublicationDownloadDelegatesToService(): void {
		$expectedResponse = new JSONResponse(['download' => 'url']);

		$this->publicationService->method('download')
			->with('pub-123')
			->willReturn($expectedResponse);

		$response = $this->controller->publicationDownload('pub-123');

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	/**
	 * A controller over a request carrying an Accept-Language header and a
	 * query service that records the order of the language and the read.
	 *
	 * @param array<int, string> $order The recorded order.
	 *
	 * @return FederationController The controller.
	 */
	private function controllerInEnglish(array &$order): FederationController {
		$request = $this->createMock(ServerAwareRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->server = ['HTTP_ACCEPT_LANGUAGE' => 'en'];

		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->expects($this->once())
			->method('applyVisitorLanguage')
			->with([], 'en')
			->willReturnCallback(static function () use (&$order): string {
				$order[] = 'language';
				return 'en';
			});
		$queryService->method('languageHeaders')->willReturn(['Content-Language' => 'en', 'X-Source-Language' => 'nl']);

		return new FederationController(
			request: $request,
			publicationService: $this->publicationService,
			l10n: $this->l10n,
			queryService: $queryService
		);
	}

	/**
	 * The federated list sets the visitor's language before it reads locally or
	 * asks a peer, and names the language served (REQ-PTR-002).
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testPublicationsAreReadInTheVisitorsLanguage(): void {
		$order = [];
		$controller = $this->controllerInEnglish(order: $order);
		$this->publicationService->method('getAggregatedPublications')
			->willReturnCallback(static function () use (&$order): array {
				$order[] = 'read';
				return ['results' => [], 'total' => 0];
			});

		$response = $controller->publications();

		$this->assertSame(['language', 'read'], $order);
		$this->assertSame('en', $response->getHeaders()['Content-Language']);
		$this->assertSame('nl', $response->getHeaders()['X-Source-Language']);
	}

	/**
	 * A federated publication is read in the visitor's language too.
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testAPublicationIsReadInTheVisitorsLanguage(): void {
		$order = [];
		$controller = $this->controllerInEnglish(order: $order);
		$this->publicationService->method('getFederatedPublication')
			->willReturnCallback(static function () use (&$order): array {
				$order[] = 'read';
				return ['data' => ['id' => 'p1'], 'status' => 200, 'federated' => true];
			});

		$response = $controller->publication('p1');

		$this->assertSame(['language', 'read'], $order);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('en', $response->getHeaders()['Content-Language']);
	}
}
