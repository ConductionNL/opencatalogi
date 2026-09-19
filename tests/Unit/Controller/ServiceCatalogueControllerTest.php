<?php

/**
 * Unit tests for ServiceCatalogueController.
 *
 * The controller's job on a publication surface is to keep two failures apart:
 * serving something that is not public, and reporting "nothing" when the truth
 * is "we could not look". Both are asserted here on the response the caller
 * actually gets, not on a service return value the response might discard.
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

use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\KnowledgeArticleService;
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
 * Unit tests for ServiceCatalogueController.
 */
class ServiceCatalogueControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IL10N|MockObject $l10n;
	private IUserSession|MockObject $userSession;
	private ServiceCatalogueService|MockObject $catalogueService;
	private CaseTypeCatalogueService|MockObject $caseTypeService;
	private ServiceCatalogueController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);

		// onlyMethods: a double that could invent a method the real service
		// lacks would let a green test cover a call that 500s in production.
		$this->catalogueService = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCatalogue', 'getObjectService'])
			->getMock();
		$this->caseTypeService = $this->getMockBuilder(CaseTypeCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['importDefinition', 'diffAgainstSource', 'applyResync', 'publishedLinks', 'syncedAt'])
			->getMock();

		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'cors_allowed_origins') {
					return '*';
				}

				// Both register keys are configured, so the trait resolves.
				return '42';
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === \OCP\IAppConfig::class) {
					return $this->config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$this->controller = new ServiceCatalogueController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->l10n,
			$this->userSession,
			$this->catalogueService,
			$this->caseTypeService,
			new KnowledgeArticleService(salt: 'test-salt')
		);

	}//end setUp()

	/**
	 * An unreadable catalogue answers 503 with a named error.
	 *
	 * The mirror failure of publishing too much: telling a resident their
	 * municipality offers nothing, when the truth is that this app could not
	 * read its own register.
	 */
	public function testAnUnreadableCatalogueAnswers503RatherThanAnEmptyList(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('catalogue-unreadable', $response->getData()['error']);

	}//end testAnUnreadableCatalogueAnswers503RatherThanAnEmptyList()

	public function testThePublicCatalogueCarriesCorsHeaders(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')->willReturn(
			['entries' => [], 'groups' => [], 'unavailable' => 0, 'total' => 0]
		);

		$response = $this->controller->index();

		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);

	}//end testThePublicCatalogueCarriesCorsHeaders()

	public function testAnUnavailableEntryIsServedRatherThanDropped(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')->willReturn(
			[
				'entries' => [
					['title' => 'Werkt', 'available' => true],
					['title' => 'Kapot', 'available' => false, 'unavailableReason' => 'unknown-case-type'],
				],
				'groups' => [],
				'unavailable' => 1,
				'total' => 2,
			]
		);

		$data = $this->controller->index()->getData();

		$this->assertCount(2, $data['entries']);
		$this->assertSame('unknown-case-type', $data['entries'][1]['unavailableReason']);

	}//end testAnUnavailableEntryIsServedRatherThanDropped()

	/**
	 * The national catalogue we could not ask is reported as unreachable.
	 *
	 * A 200 with an empty body here is the reading that sends an administrator
	 * to the wrong system, so the status itself is the assertion.
	 */
	public function testAnUnreachableSourceAnswers502AndNamesItself(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'sourceId' => 'i-navigator',
					'externalId' => 'verhuizing',
					default => $default,
				};
			}
		);
		$this->caseTypeService->method('importDefinition')
			->willThrowException(new ExternalCatalogueUnreachableException('the gateway is not installed'));

		$response = $this->controller->importCaseType();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('external-catalogue-unreachable', $response->getData()['error']);

	}//end testAnUnreachableSourceAnswers502AndNamesItself()

	public function testAnImportWithoutItsParametersIsRefused(): void {
		$this->request->method('getParam')->willReturn('');

		$response = $this->controller->importCaseType();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);

	}//end testAnImportWithoutItsParametersIsRefused()

	public function testAnExtractionByAnUnauthenticatedCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->extractArticle();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnExtractionByAnUnauthenticatedCallerIsRefused()

	/**
	 * A published case type is answered with the links it publishes.
	 *
	 * The wire contract of `GET /api/case-types/{id}`: the definition, plus the
	 * form and API links resolved for it, plus when it was last synchronised.
	 */
	public function testAPublishedCaseTypeIsAnsweredWithItsLinks(): void {
		$definition = ['identifier' => 'vergunning', 'title' => 'Vergunning', 'published' => true];
		$objectService = new class($definition) {
			public function __construct(private readonly array $definition) {
			}

			public function find(string $id, string $register, string $schema): array {
				return $this->definition;
			}
		};
		$this->catalogueService->method('getObjectService')->willReturn($objectService);
		$this->caseTypeService->method('publishedLinks')->willReturn(
			['form' => 'https://example.org/form', 'apiDescription' => null, 'complete' => false]
		);
		$this->caseTypeService->method('syncedAt')->willReturn('2026-09-01T00:00:00+00:00');

		$response = $this->controller->caseType('vergunning');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('vergunning', $data['identifier']);
		$this->assertSame('https://example.org/form', $data['links']['form']);
		$this->assertSame('2026-09-01T00:00:00+00:00', $data['syncedAt']);

	}//end testAPublishedCaseTypeIsAnsweredWithItsLinks()

	/**
	 * A case type nobody can read answers 503, never an empty definition.
	 */
	public function testACaseTypeInAnUnreadableCatalogueAnswers503(): void {
		$this->catalogueService->method('getObjectService')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->caseType('vergunning');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('catalogue-unreadable', $response->getData()['error']);

	}//end testACaseTypeInAnUnreadableCatalogueAnswers503()

	/**
	 * An object service stand-in that records what it was asked to store.
	 *
	 * A refusal that answers correctly and writes anyway is the failure these
	 * endpoints must not have, so the saves are observable rather than
	 * discarded.
	 *
	 * @param array<string, mixed> $stored  The object `find()` answers with.
	 * @param array<int, mixed>    $results The rows `searchObjects()` answers with.
	 *
	 * @return object The stand-in.
	 */
	private function objectStore(array $stored = [], array $results = []): object {
		return new class($stored, $results) {
			/**
			 * Every object handed to saveObject, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * The queries searchObjects was given.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @param array<string, mixed> $stored  The object to answer with.
			 * @param array<int, mixed>    $results The search rows.
			 */
			public function __construct(private array $stored, private array $results) {
			}

			public function find(string $id, string $register, string $schema): array {
				if ($this->stored === []) {
					throw new \RuntimeException('no such object: ' . $id);
				}

				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true): array {
				$this->queries[] = $query;

				return $this->results;
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
				if ($uuid !== '') {
					$object['id'] = $uuid;
				}

				$this->saved[] = $object;

				return $object;
			}
		};

	}//end objectStore()

	/**
	 * The repair list carries only the entries that are actually broken.
	 *
	 * An administrator opening this screen is asking "what do I have to fix",
	 * so an available entry appearing here would send them after nothing.
	 */
	public function testTheRepairListHoldsOnlyTheUnavailableEntries(): void {
		$this->catalogueService->method('readCatalogue')->willReturn(
			[
				'entries' => [
					['identifier' => 'ok', 'available' => true],
					['identifier' => 'broken', 'available' => false],
					['identifier' => 'also-broken', 'available' => false],
				],
			]
		);

		$response = $this->controller->unavailableEntries();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame(['broken', 'also-broken'], array_column($data['results'], 'identifier'));

	}//end testTheRepairListHoldsOnlyTheUnavailableEntries()

	/**
	 * A repair list that cannot be read answers 503, never "nothing to fix".
	 *
	 * The dangerous direction: an administrator told the catalogue is healthy
	 * because this app could not read it.
	 */
	public function testAnUnreadableRepairListAnswers503RatherThanNothingToFix(): void {
		$this->catalogueService->method('readCatalogue')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->unavailableEntries();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('catalogue-unreadable', $response->getData()['error']);

	}//end testAnUnreadableRepairListAnswers503RatherThanNothingToFix()

	/**
	 * A definition nobody imported has nothing to resynchronise against.
	 */
	public function testAPreviewOfADefinitionThatWasNeverImportedIsRefused(): void {
		$store = $this->objectStore(['identifier' => 'local-only']);
		$this->catalogueService->method('getObjectService')->willReturn($store);

		$response = $this->controller->previewResync('local-only');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('not-imported', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAPreviewOfADefinitionThatWasNeverImportedIsRefused()

	/**
	 * The preview hands back the difference and writes nothing.
	 *
	 * "Before anything applies" is the whole contract of this endpoint, so the
	 * empty save log is the assertion that matters as much as the body.
	 */
	public function testThePreviewAnswersTheDifferenceAndStoresNothing(): void {
		$store = $this->objectStore(
			['identifier' => 'vergunning', 'source' => ['sourceId' => 'vng', 'externalId' => 'ext-1']]
		);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->caseTypeService->method('diffAgainstSource')->willReturn(
			['changed' => ['title'], 'source' => ['title' => 'Vergunning aanvragen']]
		);

		$response = $this->controller->previewResync('vergunning');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['title'], $response->getData()['changed']);
		$this->assertSame([], $store->saved);

	}//end testThePreviewAnswersTheDifferenceAndStoresNothing()

	/**
	 * A source that cannot be reached answers 502 rather than an empty diff.
	 */
	public function testAPreviewAgainstAnUnreachableSourceAnswers502(): void {
		$store = $this->objectStore(
			['identifier' => 'vergunning', 'source' => ['sourceId' => 'vng', 'externalId' => 'ext-1']]
		);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->caseTypeService->method('diffAgainstSource')
			->willThrowException(new ExternalCatalogueUnreachableException('the national catalogue timed out'));

		$response = $this->controller->previewResync('vergunning');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testAPreviewAgainstAnUnreachableSourceAnswers502()

	/**
	 * Applying a resynchronisation stores exactly what the service produced.
	 */
	public function testApplyingAResyncStoresTheUpdatedDefinition(): void {
		$store = $this->objectStore(
			['identifier' => 'vergunning', 'source' => ['sourceId' => 'vng', 'externalId' => 'ext-1']]
		);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'accepted') {
					return ['title'];
				}

				return $default;
			}
		);
		$this->caseTypeService->method('diffAgainstSource')->willReturn(['changed' => ['title']]);
		$this->caseTypeService->method('applyResync')->willReturn(
			['identifier' => 'vergunning', 'title' => 'Vergunning aanvragen']
		);

		$response = $this->controller->applyResync('vergunning');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Vergunning aanvragen', $response->getData()['title']);
		$this->assertCount(1, $store->saved);
		$this->assertSame('Vergunning aanvragen', $store->saved[0]['title']);
		$this->assertSame('vergunning', $store->saved[0]['id']);

	}//end testApplyingAResyncStoresTheUpdatedDefinition()

	/**
	 * Applying a resynchronisation to something never imported writes nothing.
	 */
	public function testApplyingAResyncToADefinitionThatWasNeverImportedWritesNothing(): void {
		$store = $this->objectStore(['identifier' => 'local-only']);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturn([]);

		$response = $this->controller->applyResync('local-only');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('not-imported', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testApplyingAResyncToADefinitionThatWasNeverImportedWritesNothing()

	/**
	 * An OMITTED verdict is refused, and nothing is stored.
	 *
	 * This is the case the endpoint used to get wrong. `filter_var(null, ...)`
	 * returns false rather than null even with FILTER_NULL_ON_FAILURE, so a
	 * caller who sent no `helpful` at all had a "not helpful" recorded against
	 * the article in their name, and the refusal below was unreachable by
	 * omission. The raw value is now tested for absence first.
	 */
	/**
	 * A verdict nobody can read as yes or no is refused, and stores nothing.
	 */
	public function testAnUnreadableVerdictIsRefusedAndStoresNothing(): void {
		$store = $this->objectStore(['id' => 'art-1', 'draft' => false]);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'helpful') {
					return 'maybe';
				}

				return $default;
			}
		);

		$response = $this->controller->recordVerdict('art-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-verdict', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAnUnreadableVerdictIsRefusedAndStoresNothing()

	public function testAVerdictWithoutAnAnswerIsRefusedAndStoresNothing(): void {
		$store = $this->objectStore(['id' => 'art-1', 'draft' => false]);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $default
		);

		$response = $this->controller->recordVerdict('art-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-verdict', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAVerdictWithoutAnAnswerIsRefusedAndStoresNothing()

	/**
	 * A draft article is not there as far as an anonymous reader is concerned.
	 *
	 * The verdict endpoint is public, so answering anything but 404 on a draft
	 * would confirm the draft exists.
	 */
	public function testAVerdictOnADraftArticleIsNotFoundAndStoresNothing(): void {
		$store = $this->objectStore(['id' => 'art-1', 'draft' => true]);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'helpful') {
					return 'true';
				}

				return $default;
			}
		);
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.7');

		$response = $this->controller->recordVerdict('art-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $store->saved);

	}//end testAVerdictOnADraftArticleIsNotFoundAndStoresNothing()

	/**
	 * A first verdict counts, and both the verdict and the article are stored.
	 */
	public function testAFirstVerdictIsCountedAndBothHalvesAreStored(): void {
		$store = $this->objectStore(['id' => 'art-1', 'draft' => false, 'helpfulCount' => 2]);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'helpful') {
					return 'true';
				}

				if ($key === 'readerToken') {
					return 'reader-abc';
				}

				return $default;
			}
		);

		$response = $this->controller->recordVerdict('art-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['counted']);
		$this->assertSame(3, $data['helpful']);

		// The verdict row and the article, in that order, and the verdict
		// carries a hash rather than the token the reader sent.
		$this->assertCount(2, $store->saved);
		$this->assertArrayHasKey('readerHash', $store->saved[0]);
		$this->assertNotSame('reader-abc', $store->saved[0]['readerHash']);
		$this->assertSame(3, $store->saved[1]['helpfulCount']);

	}//end testAFirstVerdictIsCountedAndBothHalvesAreStored()

	/**
	 * The same reader voting twice changes nothing and stores nothing.
	 *
	 * The count is the published number, so a second vote that landed would
	 * let one reader move it as far as they liked.
	 */
	public function testASecondVerdictFromTheSameReaderIsNotCountedAndStoresNothing(): void {
		$articleService = new KnowledgeArticleService(salt: 'test-salt');
		$hash = $articleService->readerHash(readerToken: 'token:reader-abc');

		$store = $this->objectStore(
			['id' => 'art-1', 'draft' => false, 'helpfulCount' => 2],
			[['readerHash' => $hash, 'helpful' => true]]
		);
		$this->catalogueService->method('getObjectService')->willReturn($store);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'helpful') {
					return 'true';
				}

				if ($key === 'readerToken') {
					return 'reader-abc';
				}

				return $default;
			}
		);

		$response = $this->controller->recordVerdict('art-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['counted']);
		$this->assertSame(2, $data['helpful']);
		$this->assertSame([], $store->saved);

	}//end testASecondVerdictFromTheSameReaderIsNotCountedAndStoresNothing()

	/**
	 * The preflight answers the browser without touching the catalogue.
	 */
	public function testThePreflightAnswersWithoutReadingTheCatalogue(): void {
		$response = $this->controller->preflightedCors();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testThePreflightAnswersWithoutReadingTheCatalogue()

}//end class
