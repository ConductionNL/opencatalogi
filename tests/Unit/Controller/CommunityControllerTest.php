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
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
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
	private CatalogiService|MockObject $catalogi;

	/**
	 * Schema id => authorization block the fake SchemaMapper answers.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $authorizationById = [];
	private CommunityController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'cors_allowed_origins' => '*',
				'notice_board_schema' => '7',
				'notice_schema' => '8',
				default => '42',
			}
		);
		$this->config->method('getValueInt')->willReturnArgument(2);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === \OCP\IAppConfig::class) {
					return $this->config;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\SchemaMapper') {
					$authorizations = $this->authorizationById;
					return new class ($authorizations) {
						/**
						 * @param array<int, array<string, mixed>|null> $authorizations Per schema id.
						 */
						public function __construct(private array $authorizations) {
						}

						public function find(int $id): object {
							$authorization = ($this->authorizations[$id] ?? ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]]]]);
							return new class ($authorization) {
								public function __construct(private ?array $authorization) {
								}

								public function getAuthorization(): ?array {
									return $this->authorization;
								}

								public function getSlug(): string {
									return 'publication';
								}
							};
						}
					};
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
		$this->catalogi = $this->createMock(CatalogiService::class);

		$this->controller = new CommunityController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$l10n,
			$this->userSession,
			new StatusPageService(),
			new AtomFeedService(new NoticeBoardService()),
			new MarkupRenderService(),
			$this->objects,
			$this->catalogi,
			new PublicationQueryService(container: $this->container)
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

	/**
	 * An object service stand-in that records what it was asked to store.
	 *
	 * @param array<int, mixed> $rows The rows searchObjectsPaginated answers with.
	 *
	 * @return object The stand-in.
	 */
	private function readWriteStore(array $rows = []): object {
		return new class($rows) {
			/**
			 * Every object handed to saveObject, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<int, mixed> $rows The search rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array{results: array<int, mixed>} The page.
			 */
			public function searchObjectsPaginated(
				array $query,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				return ['results' => $this->rows];
			}

			/**
			 * @param array<string, mixed> $object The object to store.
			 * @param array<int, string>   $extend Unused here.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(
				array $object,
				array $extend = [],
				string $register = '',
				string $schema = '',
				string $uuid = '',
			): array {
				$this->saved[] = $object;

				return $object;
			}
		};

	}//end readWriteStore()

	/**
	 * Setting a component's state stores it and answers 201.
	 */
	public function testSettingAComponentStateStoresItAndAnswers201(): void {
		$store = $this->readWriteStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['component' => 'zoeken', 'state' => 'degraded', 'message' => 'Trager dan normaal.']);

		$response = $this->controller->setStatus();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $store->saved);
		$this->assertSame('zoeken', $store->saved[0]['component']);
		$this->assertSame('degraded', $store->saved[0]['state']);

	}//end testSettingAComponentStateStoresItAndAnswers201()

	/**
	 * A state the status page does not know is refused, and stores nothing.
	 *
	 * An unknown state stored would render as neither working nor broken, and
	 * a status page that cannot say which is worse than no status page.
	 */
	public function testAnUnknownStateIsRefusedAndStoresNothing(): void {
		$store = $this->readWriteStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['component' => 'zoeken', 'state' => 'on-fire', 'message' => '']);

		$response = $this->controller->setStatus();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('status-refused', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAnUnknownStateIsRefusedAndStoresNothing()

	/**
	 * The preflight answers the browser without reading anything.
	 */
	public function testThePreflightAnswersWithoutReadingAnything(): void {
		$response = $this->controller->preflightedCors();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testThePreflightAnswersWithoutReadingAnything()

	/**
	 * An OpenRegister double for the feed: answers the publication read with
	 * what the public read rules admit, and the board and notice reads by schema.
	 *
	 * @param array<int, array<string, mixed>> $published Rows the anonymous read returns.
	 * @param array<int, array<string, mixed>> $boards Notice boards.
	 * @param array<int, array<string, mixed>> $notices Notices.
	 *
	 * @return object The double; `calls` records every search with its flags and anonymous depth.
	 */
	private function feedObjectService(array $published, array $boards, array $notices): object {
		return new class ($published, $boards, $notices) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public int $depth = 0;

			public function __construct(private array $published, private array $boards, private array $notices) {
			}

			public function runAsAnonymous(callable $operation): mixed {
				$this->depth++;
				try {
					return $operation();
				} finally {
					$this->depth--;
				}
			}

			public function buildSearchQuery(array $params): array {
				return $params;
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['query' => $query, 'rbac' => $_rbac, 'anonymous' => ($this->depth > 0)];
				$schema = (string)($query['@self']['schema'] ?? '');
				if ($schema === '7') {
					return ['results' => $this->boards];
				}

				if ($schema === '8') {
					return ['results' => $this->notices];
				}

				return ['results' => $this->published, 'total' => count($this->published)];
			}
		};

	}//end feedObjectService()

	/**
	 * A reader without an account gets the catalogue's records and only its
	 * own notices, read as an anonymous reader under the schema's read rules.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testTheFeedCarriesWhatAnAnonymousReaderMayReadInTheCatalogue(): void {
		$this->catalogi->method('getCatalogBySlug')->with('zuiderdorp')->willReturn(
			['id' => 'cat-z', 'title' => 'Zuiderdorp', 'registers' => [1], 'schemas' => [1]]
		);
		$this->request->method('getRequestUri')->willReturn('/apps/opencatalogi/api/feeds/zuiderdorp');
		$or = $this->feedObjectService(
			published: [
				['id' => 'p1', 'title' => 'Besluit een', '@self' => ['id' => 'p1', 'updated' => '2026-09-01T10:00:00+00:00']],
				['id' => 'p2', 'title' => 'Besluit twee', '@self' => ['id' => 'p2', 'updated' => '2026-09-02T10:00:00+00:00']],
			],
			boards: [['id' => 'b1', 'catalog' => 'cat-z'], ['id' => 'b2', 'catalog' => 'cat-elders']],
			notices: [
				['id' => 'n1', 'board' => 'b1', 'title' => 'Storing balie', 'startDate' => '2020-01-01', 'endDate' => '2099-01-01'],
				['id' => 'n2', 'board' => 'b2', 'title' => 'Andere gemeente', 'startDate' => '2020-01-01', 'endDate' => '2099-01-01'],
			]
		);
		$this->objects->method('getObjectService')->willReturn($or);

		$response = $this->controller->feed(catalogSlug: 'zuiderdorp');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$atom = $response->render();
		$this->assertStringContainsString('<title>Zuiderdorp</title>', $atom);
		$this->assertStringContainsString('Besluit een', $atom);
		$this->assertStringContainsString('Besluit twee', $atom);
		$this->assertStringContainsString('Storing balie', $atom);
		$this->assertStringNotContainsString('Andere gemeente', $atom);

		$publicationRead = $or->calls[0];
		$this->assertTrue($publicationRead['anonymous'], 'the record read runs as an anonymous reader');
		$this->assertTrue($publicationRead['rbac'], 'the schema read rules are the filter');
		$this->assertSame([1], $publicationRead['query']['_schemas']);
		$this->assertSame(50, $publicationRead['query']['_limit']);

	}//end testTheFeedCarriesWhatAnAnonymousReaderMayReadInTheCatalogue()

	/**
	 * A catalogue schema without read rules never feeds the feed, so nothing
	 * an administrator left unruled reaches a reader without an account.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testASchemaWithoutReadRulesNeverReachesTheFeed(): void {
		$this->authorizationById = [1 => []];
		$this->catalogi->method('getCatalogBySlug')->willReturn(['id' => 'cat-z', 'registers' => [1], 'schemas' => [1]]);
		$or = $this->feedObjectService(published: [['id' => 'geheim', 'title' => 'Geheim']], boards: [], notices: []);
		$this->objects->method('getObjectService')->willReturn($or);

		$atom = $this->controller->feed(catalogSlug: 'zuiderdorp')->render();

		$this->assertStringNotContainsString('Geheim', $atom);
		foreach ($or->calls as $call) {
			$this->assertArrayNotHasKey('_schemas', $call['query'], 'no anonymous record read was made');
		}

	}//end testASchemaWithoutReadRulesNeverReachesTheFeed()

	/**
	 * A catalogue that does not exist, or that an anonymous reader may not see,
	 * answers 404 rather than an empty feed.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testAnUnknownCatalogueHasNoFeed(): void {
		$this->catalogi->method('getCatalogBySlug')->willReturn(null);
		$this->objects->expects($this->never())->method('getObjectService');

		$response = $this->controller->feed(catalogSlug: 'bestaat-niet');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnknownCatalogueHasNoFeed()

}//end class
