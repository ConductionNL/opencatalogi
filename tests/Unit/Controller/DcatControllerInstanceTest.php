<?php

/**
 * Unit tests for DcatController's instance document and its failure answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DcatSerializerInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Http\DcatResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The instance-level feed is negotiated, built and serialized with CORS and
 * caching headers; every endpoint answers a thrown failure with a logged 500.
 *
 * @covers \OCA\OpenCatalogi\Controller\DcatController
 * @uses   \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Http\DcatResponse
 */
class DcatControllerInstanceTest extends TestCase {

	private IRequest&MockObject $request;
	private DcatServiceInterface&MockObject $dcatService;
	private DcatSerializerInterface&MockObject $serializer;
	private CatalogiServiceInterface&MockObject $catalogiService;
	private LoggerInterface&MockObject $logger;
	private DcatController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'format' ? 'turtle' : $default)
		);
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Accept' ? 'text/turtle' : '')
		);
		$this->dcatService = $this->createMock(DcatServiceInterface::class);
		$this->serializer = $this->createMock(DcatSerializerInterface::class);
		$this->catalogiService = $this->createMock(CatalogiServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new DcatController(
			'opencatalogi',
			$this->request,
			$this->dcatService,
			$this->serializer,
			$this->catalogiService,
			$this->createMock(PublicationQueryServiceInterface::class),
			$l10n,
			$this->logger,
			$this->createMock(StandardsVersionServiceInterface::class),
			null
		);
	}

	public function testTheInstanceDocumentIsServedInTheNegotiatedFormat(): void {
		$this->serializer->expects($this->once())->method('negotiate')->with('turtle', 'text/turtle')->willReturn('turtle');
		$this->dcatService->expects($this->once())->method('buildInstanceDocument')->willReturn(
			[
				'@id' => 'https://example.org/catalog',
				'_meta' => ['etag' => '"abc"', 'lastModified' => 'Fri, 13 Nov 2026 07:00:00 GMT'],
			]
		);
		// The _meta block is stripped before serializing.
		$this->serializer->expects($this->once())->method('serialize')
			->with(['@id' => 'https://example.org/catalog'], 'turtle')
			->willReturn('<turtle body>');

		$response = $this->controller->instance();

		$this->assertInstanceOf(DcatResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('<turtle body>', $response->render());
		$headers = $response->getHeaders();
		$this->assertSame('text/turtle; charset=utf-8', $headers['Content-Type']);
		$this->assertSame('"abc"', $headers['ETag']);
		$this->assertSame('Fri, 13 Nov 2026 07:00:00 GMT', $headers['Last-Modified']);
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
	}

	public function testTheInstanceDocumentRefusesAnUnsupportedFormat(): void {
		$this->serializer->method('negotiate')->willReturn(null);
		$this->dcatService->expects($this->never())->method('buildInstanceDocument');

		$response = $this->controller->instance();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(406, $response->getStatus());
		$this->assertSame(
			['error' => 'Unsupported serialization format', 'supported' => ['jsonld', 'turtle', 'rdfxml']],
			$response->getData()
		);
	}

	public function testAFailingInstanceBuildIsLoggedAndAnswers500(): void {
		$this->serializer->method('negotiate')->willReturn('turtle');
		$this->dcatService->method('buildInstanceDocument')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[DcatController::instance] Failed to build instance DCAT document',
			['error' => 'register down']
		);

		$response = $this->controller->instance();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}

	public function testAFailingCatalogLookupIsLoggedWithTheSlugAndAnswers500(): void {
		$this->serializer->method('negotiate')->willReturn('turtle');
		$this->catalogiService->method('getCatalogBySlug')->willThrowException(new RuntimeException('lookup failed'));
		$this->logger->expects($this->once())->method('error')->with(
			'[DcatController::catalog] Failed to build catalog DCAT document',
			['catalogSlug' => 'woo', 'error' => 'lookup failed']
		);

		$response = $this->controller->catalog('woo');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}

	public function testAFailingValidationIsLoggedAndAnswers500(): void {
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'c-1']);
		$this->dcatService->method('validateCatalog')->willThrowException(new RuntimeException('broken feed'));
		$this->logger->expects($this->once())->method('error')->with(
			'[DcatController::validate] Validation failed',
			['catalogSlug' => 'woo', 'error' => 'broken feed']
		);

		$response = $this->controller->validate('woo');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}

	public function testAFailingDonlReportIsLoggedAndAnswers500(): void {
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'c-1']);
		$this->dcatService->method('validateForDonl')->willThrowException(new RuntimeException('no source'));
		$this->logger->expects($this->once())->method('error')->with(
			'[DcatController::donlReport] Validation failed',
			['catalogSlug' => 'woo', 'error' => 'no source']
		);

		$response = $this->controller->donlReport('woo');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}
}
