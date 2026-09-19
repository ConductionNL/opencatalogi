<?php

/**
 * Unit tests for CommunityController.
 *
 * Asserted on the response the caller gets. A service that withholds correctly
 * and a controller that serves the raw record anyway look identical from
 * inside the service.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\Community\SubscriptionService;
use OCA\OpenCatalogi\Service\Community\VoteService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for CommunityController.
 */
class CommunityControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IUserSession|MockObject $userSession;
	private ServiceCatalogueService|MockObject $objects;
	private CommunityController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($key === 'cors_allowed_origins' ? '*' : '42')
		);
		$this->config->method('getValueInt')->willReturnArgument(2);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === \OCP\IAppConfig::class) {
					return $this->config;
				}

				throw new \RuntimeException('not available');
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);

		// onlyMethods against the real class: the double cannot answer a call
		// the production reader would not have.
		$this->objects = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();

		$notices = new NoticeBoardService();

		$this->controller = new CommunityController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$l10n,
			$this->userSession,
			new StatusPageService(),
			new SubscriptionService(salt: 'test-salt'),
			new BannerService(),
			$notices,
			new AtomFeedService(new PublicationRuleService(), $notices),
			new VoteService(salt: 'test-salt'),
			new MarkupRenderService(),
			$this->objects
		);

	}//end setUp()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

	}//end withParams()

	/**
	 * A status page this app cannot read answers a refusal, not an empty list.
	 *
	 * An empty status page reads as "nothing is wrong", which is the worst
	 * thing a status page can say while being unable to check.
	 */
	public function testAnUnreadableStatusPageAnswers503RatherThanAllClear(): void {
		$this->objects->method('getObjectService')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->statusPage();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('status-unreadable', $response->getData()['error']);

	}//end testAnUnreadableStatusPageAnswers503RatherThanAllClear()

	public function testTheRenderEndpointAnswersOurHtmlAndCarriesCors(): void {
		$this->withParams(['markup' => '# Kop']);

		$response = $this->controller->renderMarkup();

		$this->assertSame('<h1>Kop</h1>', $response->getData()['html']);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);

	}//end testTheRenderEndpointAnswersOurHtmlAndCarriesCors()

	/**
	 * The render endpoint has no side effect: it never resolves an object
	 * service at all, so it cannot create or change anything.
	 */
	public function testTheRenderEndpointTouchesNoObjectService(): void {
		$this->objects->expects($this->never())->method('getObjectService');
		$this->withParams(['markup' => 'Tekst met <b>markup</b>.']);

		$html = $this->controller->renderMarkup()->getData()['html'];

		$this->assertStringNotContainsString('<b>', $html);

	}//end testTheRenderEndpointTouchesNoObjectService()

	public function testRenderingWithoutAnyMarkupIsRefused(): void {
		$this->withParams([]);

		$response = $this->controller->renderMarkup();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-markup', $response->getData()['error']);

	}//end testRenderingWithoutAnyMarkupIsRefused()

	public function testAnUnauthenticatedCallerGetsNoBanners(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->banners()->getStatus());

	}//end testAnUnauthenticatedCallerGetsNoBanners()

	public function testAnUnauthenticatedCallerCannotDismissABanner(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->dismissBanner()->getStatus());

	}//end testAnUnauthenticatedCallerCannotDismissABanner()

	public function testABoardWithCommentsAndNoModeratorIsRefusedByTheController(): void {
		$this->withParams(['board' => ['title' => 'Mededelingen', 'commentsEnabled' => true]]);

		$response = $this->controller->saveNoticeBoard();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('board-refused', $response->getData()['error']);

	}//end testABoardWithCommentsAndNoModeratorIsRefusedByTheController()

	/**
	 * An in-memory stand-in for OpenRegister's ObjectService.
	 *
	 * OpenRegister is not on the autoload path in a standalone checkout, so it
	 * cannot be mocked by type. This stores what it is handed and answers what
	 * it stored; a save that dropped the object would fail the assertions
	 * below rather than pass them.
	 *
	 * @return object The stand-in.
	 */
	private function objectStore(): object {
		return new class {
			/** @var array<string, array<string, mixed>> */
			public array $stored = [];

			public function saveObject(
				array $object,
				array $extend = [],
				string $register = '',
				string $schema = '',
				string $uuid = '',
			): array {
				$id = ($uuid !== '' ? $uuid : 'notice-1');
				$object['id'] = $id;
				$this->stored[$id] = $object;

				return $object;
			}
		};

	}//end objectStore()

	/**
	 * A notice that passes its checks is stored.
	 *
	 * The write path REQ-PCS-104 asks for. Before this, `validateNotice` had no
	 * caller at all, so nothing a user touched ever checked a notice.
	 */
	public function testANoticeThatPassesItsChecksIsStored(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(
			[
				'notice' => [
					'board' => 'mededelingen',
					'title' => 'Werk aan de kade',
					'body' => 'De kade is deze week afgesloten.',
					'startDate' => '2026-09-01T00:00:00+00:00',
					'endDate' => '2099-01-01T00:00:00+00:00',
				],
			]
		);

		$response = $this->controller->saveNotice();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('Werk aan de kade', $response->getData()['title']);
		$this->assertTrue($response->getData()['current']);
		$this->assertArrayHasKey('notice-1', $store->stored);

	}//end testANoticeThatPassesItsChecksIsStored()

	/**
	 * A notice with no board is refused with its reason, and nothing is stored.
	 */
	public function testANoticeWithNoBoardIsRefusedAndNothingIsStored(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(
			[
				'notice' => [
					'title' => 'Werk aan de kade',
					'startDate' => '2026-09-01T00:00:00+00:00',
					'endDate' => '2099-01-01T00:00:00+00:00',
				],
			]
		);

		$response = $this->controller->saveNotice();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('notice-refused', $response->getData()['error']);
		$this->assertSame([], $store->stored);

	}//end testANoticeWithNoBoardIsRefusedAndNothingIsStored()

	/**
	 * A notice whose period this app cannot read is refused, not stored blank.
	 */
	public function testANoticeWithAnUnreadablePeriodIsRefused(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(
			[
				'notice' => [
					'board' => 'mededelingen',
					'title' => 'Werk aan de kade',
					'startDate' => 'ooit',
					'endDate' => 'later',
				],
			]
		);

		$response = $this->controller->saveNotice();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('notice-refused', $response->getData()['error']);
		$this->assertSame([], $store->stored);

	}//end testANoticeWithAnUnreadablePeriodIsRefused()

	/**
	 * A notice that ends before it starts is refused.
	 */
	public function testANoticeThatEndsBeforeItStartsIsRefused(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(
			[
				'notice' => [
					'board' => 'mededelingen',
					'title' => 'Werk aan de kade',
					'startDate' => '2026-09-10T00:00:00+00:00',
					'endDate' => '2026-09-01T00:00:00+00:00',
				],
			]
		);

		$response = $this->controller->saveNotice();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('notice-refused', $response->getData()['error']);
		$this->assertSame([], $store->stored);

	}//end testANoticeThatEndsBeforeItStartsIsRefused()

	public function testASubscriptionToAnUnusableAddressIsRefused(): void {
		$this->withParams(['address' => 'niet-een-adres', 'scope' => 'status']);

		$response = $this->controller->subscribe();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('subscription-refused', $response->getData()['error']);

	}//end testASubscriptionToAnUnusableAddressIsRefused()

	public function testAVoteWithoutAValueIsRefused(): void {
		$this->withParams([]);

		$response = $this->controller->vote(id: 'r1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-value', $response->getData()['error']);

	}//end testAVoteWithoutAValueIsRefused()
}//end class
