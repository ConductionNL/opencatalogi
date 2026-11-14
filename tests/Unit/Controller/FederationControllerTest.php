<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
			'opencatalogi',
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
	 * A federation controller whose allowlist is $allowlist, called from $origin.
	 *
	 * @param string $allowlist The `cors_allowed_origins` setting.
	 * @param string $origin    The caller's Origin header.
	 *
	 * @return FederationController The controller.
	 */
	private function controllerCalledFrom(string $allowlist, string $origin): FederationController {
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($key === 'cors_allowed_origins' ? $allowlist : $default)
		);
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? $origin : '')
		);

		return new FederationController(
			appName: 'opencatalogi',
			request: $this->request,
			publicationService: $this->publicationService,
			l10n: $this->l10n,
			appConfig: $config
		);
	}//end controllerCalledFrom()

	/**
	 * A municipality's website on the allowlist is named on every federation answer.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 *
	 * @return void
	 */
	public function testAnAllowedWebsiteIsNamedOnEveryFederationAnswer(): void {
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['REQUEST_URI'] = '/api/federation/publications';
		$this->request->method('getParams')->willReturn([]);
		$this->publicationService->method('getAggregatedPublications')->willReturn(['results' => []]);
		$this->publicationService->method('getFederatedPublication')->willReturn(['data' => ['id' => 'pub-1'], 'status' => 200]);
		$this->publicationService->method('getFederatedUses')->willThrowException(new \RuntimeException('peer down'));
		$this->publicationService->method('getFederatedUsed')->willReturn(['data' => [], 'status' => 200]);
		$this->publicationService->method('getFederatedAttachments')->willReturn(new JSONResponse([]));
		$this->publicationService->method('download')->willReturn(new JSONResponse([], 404));
		$controller = $this->controllerCalledFrom(allowlist: 'https://www.voorbeeldgemeente.nl', origin: 'https://www.voorbeeldgemeente.nl');

		$answers = [
			'publications' => $controller->publications(),
			'publication' => $controller->publication('pub-1'),
			'publicationUses' => $controller->publicationUses('pub-1'),
			'publicationUsed' => $controller->publicationUsed('pub-1'),
			'publicationAttachments' => $controller->publicationAttachments('pub-1'),
			'publicationDownload' => $controller->publicationDownload('pub-1'),
		];

		foreach ($answers as $action => $response) {
			$headers = $response->getHeaders();
			$this->assertSame('https://www.voorbeeldgemeente.nl', $headers['Access-Control-Allow-Origin'], $action);
			$this->assertSame('false', $headers['Access-Control-Allow-Credentials'], $action);
			$this->assertSame('Origin', $headers['Vary'], $action);
		}

		$this->assertSame(500, $answers['publicationUses']->getStatus());

	}//end testAnAllowedWebsiteIsNamedOnEveryFederationAnswer()

	/**
	 * A preflight from a site that is not on the list does not name that site.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 *
	 * @return void
	 */
	public function testAPreflightFromAnUnlistedSiteDoesNotNameIt(): void {
		$controller = $this->controllerCalledFrom(allowlist: 'https://www.voorbeeldgemeente.nl', origin: 'https://elders.example');

		$headers = $controller->preflightedCors()->getHeaders();

		$this->assertSame('https://www.voorbeeldgemeente.nl', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('false', $headers['Access-Control-Allow-Credentials']);

	}//end testAPreflightFromAnUnlistedSiteDoesNotNameIt()
}//end class
