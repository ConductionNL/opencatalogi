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
	 * A federation controller whose publication has a comment period with these dates.
	 *
	 * @param array<string, mixed>|null $period The stored period, or null for none.
	 *
	 * @return FederationController
	 */
	private function controllerWithPeriod(?array $period): FederationController {
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'publication_register' => '5',
				'comment_period_schema' => '9',
				default => '',
			}
		);
		$objects = new class ($period) {
			/** @var list<array<string, mixed>> */
			public array $queries = [];

			/**
			 * @param array<string, mixed>|null $period The period it holds.
			 */
			public function __construct(private ?array $period) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = ['query' => $query, 'rbac' => $_rbac];
				return ['results' => ($this->period === null ? [] : [$this->period])];
			}
		};
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$this->objects = $objects;

		$periods = new \OCA\OpenCatalogi\Service\Publication\CommentPeriodService(
			$this->createMock(\OCA\OpenCatalogi\Service\Publication\TermRoll::class),
			$this->createMock(\OCP\IAppConfig::class),
			$this->createMock(\OCP\IURLGenerator::class)
		);
		$commentPeriods = new \OCA\OpenCatalogi\Service\Publication\PublicationCommentPeriod(
			$periods,
			$config,
			$container,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->publicationService->method('getFederatedPublication')
			->willReturn(['status' => 200, 'data' => ['id' => 'pub-1', 'title' => 'Besluit']]);
		$this->request->method('getParams')->willReturn([]);

		return new FederationController(
			'opencatalogi',
			$this->request,
			$this->publicationService,
			$this->l10n,
			null,
			null,
			$commentPeriods
		);
	}

	/** @var object|null The object service double of the last controllerWithPeriod(). */
	private ?object $objects = null;

	/**
	 * REQ-PDP-003: an open period on the federation endpoint carries its end and the reaction form.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function testThePublicationCarriesItsOpenCommentPeriod(): void {
		$end = (new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
		$controller = $this->controllerWithPeriod([
			'publication' => 'pub-1',
			'startDate' => (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
			'endDate' => $end,
			'termDays' => 14,
			'legalRemedy' => 'zienswijze',
			'reactionFormUrl' => 'https://example.org/reageer',
		]);

		$data = $controller->publication('pub-1')->getData();

		$this->assertSame('open', $data['commentPeriod']['state']);
		$this->assertSame($end, $data['commentPeriod']['endDate']);
		$this->assertSame('https://example.org/reageer', $data['commentPeriod']['reactionFormUrl']);
		$this->assertArrayNotHasKey('commentPeriodError', $data);
		$this->assertSame('pub-1', $this->objects->queries[0]['query']['publication']);
		$this->assertSame(['register' => '5', 'schema' => '9'], $this->objects->queries[0]['query']['@self']);
	}

	/**
	 * REQ-PDP-003: dates that cannot be read give an error, not a state.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function testUnreadableDatesGiveAnErrorNotAState(): void {
		$controller = $this->controllerWithPeriod([
			'publication' => 'pub-1',
			'startDate' => 'not a date',
			'endDate' => 'also not',
		]);

		$data = $controller->publication('pub-1')->getData();

		$this->assertSame('unreadable-dates', $data['commentPeriodError']);
		$this->assertArrayNotHasKey('commentPeriod', $data);
	}

	/**
	 * A closed period carries no reaction form, and a publication without a period carries no key.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function testAClosedPeriodHasNoFormAndNoPeriodHasNoKey(): void {
		$controller = $this->controllerWithPeriod([
			'publication' => 'pub-1',
			'startDate' => '2020-01-01T00:00:00Z',
			'endDate' => '2020-01-15T00:00:00Z',
			'reactionFormUrl' => 'https://example.org/reageer',
		]);
		$closed = $controller->publication('pub-1')->getData();
		$this->assertSame('closed', $closed['commentPeriod']['state']);
		$this->assertNull($closed['commentPeriod']['reactionFormUrl']);

		$this->setUp();
		$none = $this->controllerWithPeriod(null)->publication('pub-1')->getData();
		$this->assertArrayNotHasKey('commentPeriod', $none);
		$this->assertArrayNotHasKey('commentPeriodError', $none);
	}
}
