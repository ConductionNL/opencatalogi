<?php

/**
 * Unit tests for ApiDocumentationController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ApiDocumentationController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The bundled openapi.json is served with the installed app version and CORS
 * headers; a failure is logged and answered with a 500.
 *
 * @covers \OCA\OpenCatalogi\Controller\ApiDocumentationController
 * @uses   \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 */
class ApiDocumentationControllerTest extends TestCase {

	private IAppManager&MockObject $appManager;
	private LoggerInterface&MockObject $logger;
	private ApiDocumentationController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new ApiDocumentationController(
			'opencatalogi',
			$request,
			$this->appManager,
			$l10n,
			$this->logger,
			null
		);
	}

	public function testTheDocumentCarriesTheInstalledAppVersion(): void {
		$this->appManager->expects($this->once())->method('getAppVersion')->with('opencatalogi')->willReturn('9.8.7');

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$document = $response->getData();
		$this->assertIsObject($document);
		$this->assertSame('9.8.7', $document->info->version);
		$expected = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/openapi.json'), false);
		$this->assertSame($expected->openapi, $document->openapi);
		$this->assertEquals($expected->paths, $document->paths);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $response->getHeaders()['Access-Control-Allow-Methods']);
	}

	public function testAFailureIsLoggedAndAnswers500WithCorsHeaders(): void {
		$this->appManager->method('getAppVersion')->willThrowException(new RuntimeException('no app'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ApiDocumentationController::index] Failed to serve openapi.json',
			['error' => 'no app']
		);

		$response = $this->controller->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
	}
}
