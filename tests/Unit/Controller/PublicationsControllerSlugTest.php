<?php

/**
 * A publication resolves by UUID or by slug within its catalogue (#1592).
 *
 * The UUID lookup runs first and unchanged; only when it finds nothing is the
 * identifier looked up as `@self.slug` across every register × schema of the
 * catalogue. One match is returned; more than one is refused with the plain
 * 404 and a warning naming the matching UUIDs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/publications/spec.md#requirement-retrieve-a-single-publication-by-catalog-slug-and-object-uuid-or-slug-pub-002
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the slug route of PublicationsController::show().
 */
class PublicationsControllerSlugTest extends TestCase {

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';
	private const UUID_B = '00000000-0000-0000-0000-00000000000b';

	private MockObject $objectService;

	private CatalogiService|MockObject $catalogiService;

	private LoggerInterface|MockObject $logger;

	private UsageCounterService|MockObject $usageCounter;

	private IRequest|MockObject $request;

	private SchemaOrgService|MockObject $schemaOrg;

	private WithheldFromPublication|MockObject $withheld;

	/** @var list<array> Every query searchObjects() received, in order. */
	private array $queries = [];

	/** @var list<bool> The _rbac flag of every searchObjects() call, in order. */
	private array $rbacFlags = [];

	/** @var list<string> Warnings logged. */
	private array $warnings = [];

	private PublicationsController $controller;

	protected function setUp(): void {
		$this->objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$this->catalogiService = $this->createMock(CatalogiService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$this->withheld = $this->createMock(WithheldFromPublication::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				WithheldFromPublicationInterface::class => $this->withheld,
				SchemaOrgServiceInterface::class => $this->schemaOrg,
				default => $this->objectService,
			}
		);
		$this->schemaOrg = $this->createMock(SchemaOrgService::class);
		$this->usageCounter = $this->createMock(UsageCounterService::class);

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applyCatalogReadRuleGuard')->willReturnCallback(fn (array $catalog) => $catalog);
		$queryService->method('findObjectLocation')->willReturn(null);
		$queryService->method('stripEmptyValues')->willReturnCallback(fn (array $data) => $data);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []) => $text);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $default);

		$request = $this->request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->server = [];

		$this->objectService->method('renderEntity')->willReturnCallback(
			static fn ($entity) => ['id' => $entity->getUuid()]
		);

		$this->controller = new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationService::class),
			$this->catalogiService,
			$queryService,
			$container,
			$appManager,
			$this->logger,
			$l10n,
			$this->usageCounter,
			$appConfig
		);
	}//end setUp()

	/**
	 * A publication double with a uuid in a register and schema.
	 *
	 * @param string $uuid     The uuid.
	 * @param int    $register The register id.
	 * @param int    $schema   The schema id.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The double.
	 */
	private function publication(string $uuid, int $register=1, int $schema=1): \OCA\OpenRegister\Db\ObjectEntity {
		$mock = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchema', 'getRegister', 'getUuid'])
			->addMethods(['getId'])
			->getMock();
		$mock->method('getId')->willReturn(42);
		$mock->method('getUuid')->willReturn($uuid);
		$mock->method('getSchema')->willReturn((string)$schema);
		$mock->method('getRegister')->willReturn((string)$register);
		return $mock;
	}//end publication()

	/**
	 * Answer searchObjects() from a map keyed by "<field>:<value>:<register>:<schema>".
	 *
	 * @param array<string, list<object>> $answers The canned answers.
	 * @param array                       $catalog The catalogue show() resolves.
	 *
	 * @return void
	 */
	private function given(array $answers, array $catalog=['schemas' => [1], 'registers' => [1]]): void {
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['title' => 'Woo'] + $catalog);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac=true) use ($answers): array {
				$this->queries[] = $query;
				$this->rbacFlags[] = $_rbac;
				$self = $query['@self'] ?? [];
				$field = isset($self['slug']) === true ? 'slug' : (isset($self['id']) === true ? 'id' : 'uuid');
				$key = $field . ':' . ($self[$field] ?? '') . ':' . ($self['register'] ?? '') . ':' . ($self['schema'] ?? '');
				return $answers[$key] ?? [];
			}
		);
	}//end given()

	public function testAUuidUrlIsAnsweredByTheUuidLookupAlone(): void {
		$this->given(['uuid:' . self::UUID_A . ':1:1' => [$this->publication(self::UUID_A)]]);

		$response = $this->controller->show('woo', self::UUID_A);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
		foreach ($this->queries as $query) {
			$this->assertArrayNotHasKey('slug', $query['@self'], 'a UUID hit must not trigger the slug route');
		}
	}

	public function testASlugResolvesWithinTheCatalogue(): void {
		$this->given(['slug:2026-0042:1:1' => [$this->publication(self::UUID_A)]]);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
	}

	public function testASlugHitOnAShowWithheldCatalogueContinuesWithTheUuid(): void {
		$this->given(
			['slug:2026-0042:1:1' => [$this->publication(self::UUID_A)]],
			['schemas' => [1], 'registers' => [1], 'showWithheld' => true]
		);
		$this->withheld->expects($this->once())->method('addForCatalog')->willReturnCallback(
			function (array $response, array $catalog, string $publicationId): array {
				$this->assertSame(self::UUID_A, $publicationId);
				return $response;
			}
		);
		$this->usageCounter->expects($this->once())->method('increment')->with(
			self::UUID_A,
			UsageCounterService::KIND_VIEW,
			$this->anything(),
			'woo'
		);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(200, $response->getStatus());
	}

	public function testAUuidHitStillPassesTheUrlUuidToTheWithheldListAndTheCounter(): void {
		$this->given(
			['uuid:' . self::UUID_A . ':1:1' => [$this->publication(self::UUID_A)]],
			['schemas' => [1], 'registers' => [1], 'showWithheld' => true]
		);
		$this->withheld->expects($this->once())->method('addForCatalog')->willReturnCallback(
			function (array $response, array $catalog, string $publicationId): array {
				$this->assertSame(self::UUID_A, $publicationId);
				return $response;
			}
		);
		$this->usageCounter->expects($this->once())->method('increment')->with(
			self::UUID_A,
			UsageCounterService::KIND_VIEW,
			$this->anything(),
			'woo'
		);

		$this->assertSame(200, $this->controller->show('woo', self::UUID_A)->getStatus());
	}

	public function testASlugHitOnTheSchemaOrgPathCountsTheViewUnderTheUuid(): void {
		$this->given(['slug:2026-0042:1:1' => [$this->publication(self::UUID_A)]]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'format' ? 'schema' : $default
		);
		$this->schemaOrg->method('buildPublicationNode')->willReturn(['@type' => 'Dataset']);
		$this->usageCounter->expects($this->once())->method('increment')->with(
			self::UUID_A,
			UsageCounterService::KIND_VIEW,
			$this->anything(),
			'woo'
		);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Dataset', $response->getData()['@type']);
	}

	public function testASlugHeldInTwoSchemasOfTheCatalogueIsRefused(): void {
		$this->given(
			[
				'slug:2026-0042:1:1' => [$this->publication(self::UUID_A, 1, 1)],
				'slug:2026-0042:1:2' => [$this->publication(self::UUID_B, 1, 2)],
			],
			['schemas' => [1, 2], 'registers' => [1]]
		);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(404, $response->getStatus());
		$this->assertStringNotContainsString(self::UUID_A, json_encode($response->getData()));
		$this->assertStringNotContainsString(self::UUID_B, json_encode($response->getData()));
		$ambiguous = array_values(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'is ambiguous')));
		$this->assertCount(1, $ambiguous);
		$this->assertStringContainsString(self::UUID_A, $ambiguous[0]);
		$this->assertStringContainsString(self::UUID_B, $ambiguous[0]);
	}

	public function testAnUnknownSlugIs404(): void {
		$this->given([]);

		$response = $this->controller->show('woo', 'does-not-exist');

		$this->assertSame(404, $response->getStatus());
	}

	public function testANumericSlugIsLookedUpAsASlugNotAnId(): void {
		$this->given(['slug:123456:1:1' => [$this->publication(self::UUID_A)]]);

		$response = $this->controller->show('woo', '123456');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
		$slugQueries = array_values(array_filter($this->queries, static fn (array $q): bool => isset($q['@self']['slug'])));
		$this->assertSame('123456', $slugQueries[0]['@self']['slug']);
		$this->assertArrayNotHasKey('id', $slugQueries[0]['@self']);
	}

	public function testAnObjectWithTheNumericIdOfTheSlugIsNotReturned(): void {
		// An object with numeric id 123456 exists, but no publication has that slug.
		$this->given(['id:123456:1:1' => [$this->publication(self::UUID_B)]]);

		$response = $this->controller->show('woo', '123456');

		$this->assertSame(404, $response->getStatus());
		$this->assertStringNotContainsString(self::UUID_B, json_encode($response->getData()));
	}

	public function testASlugOfAPublicationInAnotherCatalogueIsNotFound(): void {
		// The slug exists in register 2 / schema 9, which this catalogue does not span.
		$this->given(['slug:2026-0042:2:9' => [$this->publication(self::UUID_A, 2, 9)]]);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(404, $response->getStatus());
		foreach ($this->queries as $query) {
			$this->assertNotSame(2, $query['@self']['register'] ?? null, 'only the catalogue scope is searched');
		}
	}

	public function testTheUuidOfOneOfTwoAmbiguousPublicationsStillResolves(): void {
		$this->given(
			[
				'uuid:' . self::UUID_A . ':1:1' => [$this->publication(self::UUID_A, 1, 1)],
				'slug:2026-0042:1:1' => [$this->publication(self::UUID_A, 1, 1)],
				'slug:2026-0042:1:2' => [$this->publication(self::UUID_B, 1, 2)],
			],
			['schemas' => [1, 2], 'registers' => [1]]
		);

		$response = $this->controller->show('woo', self::UUID_A);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
	}

	public function testTheSlugSearchIsRbacScopedAndCapsEachSearchAtTwo(): void {
		$this->given(['slug:2026-0042:1:1' => [$this->publication(self::UUID_A)]]);

		$this->controller->show('woo', '2026-0042');

		$slugCalls = [];
		foreach ($this->queries as $i => $query) {
			if (isset($query['@self']['slug']) === true) {
				$slugCalls[] = $i;
				$this->assertSame(2, $query['_limit'] ?? null);
				$this->assertTrue($this->rbacFlags[$i], 'the slug search must run with RBAC');
			}
		}

		$this->assertNotEmpty($slugCalls);
	}

	public function testAHiddenDuplicateDoesNotMakeTheSlugAmbiguous(): void {
		// RBAC hides the copy in schema 2 from this reader: the search returns nothing for it.
		$this->given(
			[
				'slug:2026-0042:1:1' => [$this->publication(self::UUID_A, 1, 1)],
				'slug:2026-0042:1:2' => [],
			],
			['schemas' => [1, 2], 'registers' => [1]]
		);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
		$this->assertSame([], array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'is ambiguous')));
	}

	public function testTheSlugLoopStopsOnceTwoMatchesAreFound(): void {
		$this->given(
			[
				'slug:2026-0042:1:1' => [$this->publication(self::UUID_A, 1, 1)],
				'slug:2026-0042:1:2' => [$this->publication(self::UUID_B, 1, 2)],
				'slug:2026-0042:1:3' => [$this->publication('00000000-0000-0000-0000-00000000000c', 1, 3)],
			],
			['schemas' => [1, 2, 3], 'registers' => [1]]
		);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(404, $response->getStatus());
		$searchedSchemas = array_map(
			static fn (array $q) => $q['@self']['schema'],
			array_values(array_filter($this->queries, static fn (array $q): bool => isset($q['@self']['slug'])))
		);
		$this->assertSame([1, 2], $searchedSchemas, 'the third schema is not searched once the slug is ambiguous');
	}

	public function testAPublicationWithoutASlugStaysReachableByUuid(): void {
		// The double carries no slug; only the UUID lookup knows it.
		$this->given(['uuid:' . self::UUID_B . ':1:1' => [$this->publication(self::UUID_B)]]);

		$response = $this->controller->show('woo', self::UUID_B);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_B, $response->getData()['id']);
	}

	public function testACatalogueWithoutScopeNeverRunsTheSlugRoute(): void {
		$this->given([], ['schemas' => [], 'registers' => []]);

		$response = $this->controller->show('woo', '2026-0042');

		$this->assertSame(404, $response->getStatus());
		foreach ($this->queries as $query) {
			$this->assertArrayNotHasKey('slug', $query['@self'] ?? []);
		}
	}

	public function testTheUrlIdIsNormalisedBeforeTheSlugLookup(): void {
		$this->given(['slug:zaak-2026-0042:1:1' => [$this->publication(self::UUID_A)]]);

		$response = $this->controller->show('woo', 'Zaak_2026-0042');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::UUID_A, $response->getData()['id']);
		$slugQueries = array_values(array_filter($this->queries, static fn (array $q): bool => isset($q['@self']['slug'])));
		$this->assertSame('zaak-2026-0042', $slugQueries[0]['@self']['slug']);
	}

	public function testAnIdThatNormalisesToNothingRunsNoSlugSearch(): void {
		$this->given([]);

		$response = $this->controller->show('woo', '..é.');

		$this->assertSame(404, $response->getStatus());
		foreach ($this->queries as $query) {
			$this->assertArrayNotHasKey('slug', $query['@self'] ?? []);
		}
	}
}//end class
