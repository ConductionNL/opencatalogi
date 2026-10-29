<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\GlossaryController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for GlossaryController.
 */
class GlossaryControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private IL10N|MockObject $l10n;
	private GlossaryController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->l10n->method('t')
			->willReturnCallback(fn (string $text) => $text);

		$this->controller = new GlossaryController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->appManager,
			$this->l10n
		);
	}

	/**
	 * Build a JsonSerializable result row mirroring an OpenRegister entity.
	 *
	 * Production results from the magic-mapper backend are entity objects; the SOLR
	 * backend returns plain arrays. show() now checks is_array() before calling
	 * jsonSerialize() (#736), so both shapes are handled. This helper exercises the
	 * entity path; testShowAcceptsArrayShape exercises the SOLR array path.
	 *
	 * @param array<string,mixed> $data Term payload.
	 *
	 * @return \JsonSerializable
	 */
	private function serializableObject(array $data): \JsonSerializable {
		return new class($data) implements \JsonSerializable {
			public function __construct(
				private array $data,
			) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}

	/**
	 * Build a test double for OpenRegister's (final) RegisterResolverService.
	 *
	 * @param IAppConfig $config The (mocked) app config the double reads from.
	 *
	 * @return object A double exposing resolveRegisterId / resolveSchemaId.
	 */
	private function makeResolverDouble(IAppConfig $config): object {
		return new class($config) {
			public function __construct(
				private IAppConfig $config,
			) {
			}

			public function resolveRegisterId(string $appId, string $key, ?string $default = null): string {
				return $this->resolve($appId, $key);
			}

			public function resolveSchemaId(string $appId, string $key, ?string $default = null): string {
				return $this->resolve($appId, $key);
			}

			private function resolve(string $appId, string $key): string {
				$value = $this->config->getValueString($appId, $key, '');
				if ($value === '') {
					throw new \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException($appId, $key);
				}
				return $value;
			}
		};
	}

	private function mockObjectService(): MockObject {
		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		// The controller resolves register/schema via RegisterResolverService
		// before hitting ObjectService; the resolver reads the same app-config
		// keys (throwing MissingConfigException on empty).
		// RegisterResolverService is declared final and cannot be mocked, so we
		// pass a hand-rolled double mirroring its contract.
		$resolver = $this->makeResolverDouble($this->config);

		$this->container->method('get')
			->willReturnCallback(
				static function (string $id) use ($mockObjService, $resolver) {
					if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
						return $resolver;
					}
					return $mockObjService;
				}
			);

		return $mockObjService;
	}

	public function testPreflightedCorsReturnsResponse(): void {
		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('https://example.com');

		$response = $this->controller->preflightedCors();

		$this->assertInstanceOf(Response::class, $response);
	}

	public function testPreflightedCorsUsesWildcardWhenNoOrigin(): void {
		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('');

		$response = $this->controller->preflightedCors();

		$this->assertInstanceOf(Response::class, $response);
	}

	public function testIndexReturnsJsonResponse(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [['id' => 1, 'term' => 'API']],
				'total' => 1,
				'limit' => 20,
				'offset' => 0,
				'page' => 1,
				'pages' => 1,
			]);

		$this->config->method('getValueString')
			->willReturnMap([
				['opencatalogi', 'glossary_schema', '', '5'],
				['opencatalogi', 'glossary_register', '', '1'],
				['opencatalogi', 'cors_allowed_origins', '*', '*'],
			]);

		$this->request->method('getParams')
			->willReturn([]);

		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * WOO-581 review round 2 (f4): the configured `@self.schema` won for the rows,
	 * but OR's facet path reads `@self.schemas ?? _schemas` first, so a caller's
	 * scope keys steered the facets to any schema. None reaches OR now; a
	 * non-scope `@self` filter does.
	 *
	 * @return void
	 */
	public function testIndexStripsTheCallersScopeKeys(): void {
		$mockObjService = $this->mockObjectService();
		$mockObjService->expects($this->once())
			->method('searchObjectsPaginated')
			->with($this->callback(static fn (array $q): bool => array_intersect(['_schemas', '_registers', 'schema', 'register'], array_keys($q)) === []
				&& isset($q['@self']['schemas'], $q['@self']['registers']) === false
				&& ($q['@self']['owner'] ?? null) === 'alice'
				&& ($q['@self']['schema'] ?? null) === '5'))
			->willReturn(['results' => [], 'total' => 0]);

		$this->config->method('getValueString')
			->willReturnMap([
				['opencatalogi', 'glossary_schema', '', '5'],
				['opencatalogi', 'glossary_register', '', '1'],
				['opencatalogi', 'cors_allowed_origins', '*', '*'],
			]);
		$this->request->method('getParams')->willReturn([
				'_schemas' => [56],
				'_registers' => [99],
				'_schema' => 56,
				'schema' => 56,
				'@self' => ['schemas' => [56], 'registers' => [99], 'owner' => 'alice'],
			]);
		$this->request->server = [];

		$this->assertEquals(200, $this->controller->index()->getStatus());
	}

	public function testIndexWithPaginationLinks(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [],
				'total' => 100,
				'limit' => 10,
				'offset' => 0,
				'page' => 1,
				'pages' => 10,
				'next' => '/api/glossary?page=2',
				'prev' => null,
			]);

		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn([]);

		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testIndexWithFacets(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [],
				'total' => 0,
				'facets' => ['category' => ['A' => 5]],
				'facetable' => ['category'],
			]);

		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn([]);

		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testIndexReturns503WhenOpenRegisterNotInstalled(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn([]);
		$this->container->method('get')
			->willThrowException(new RuntimeException('not available'));

		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn([]);

		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(503, $response->getStatus());
	}

	/**
	 * Configure the glossary register (3) and schema (11).
	 */
	private function configureGlossary(): void {
		$this->config->method('getValueString')
			->willReturnMap([
				['opencatalogi', 'glossary_schema', '', '11'],
				['opencatalogi', 'glossary_register', '', '3'],
				['opencatalogi', 'cors_allowed_origins', '*', '*'],
			]);
	}

	public function testShowReturnsGlossaryTerm(): void {
		$mockObjService = $this->mockObjectService();
		$this->configureGlossary();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [$this->serializableObject(['id' => 1, 'term' => 'API', 'definition' => 'Application Programming Interface'])],
			]);

		$this->request->server = [];

		$response = $this->controller->show('1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * The lookup must be scoped to the glossary register and schema.
	 *
	 * Unscoped, OpenRegister answers an `_ids` search from every magic table on the
	 * instance, and this route is @PublicPage: an anonymous caller could read any
	 * object whose schema declares no authorization block.
	 */
	public function testShowScopesTheLookupToTheGlossaryRegister(): void {
		$mockObjService = $this->mockObjectService();
		$this->configureGlossary();

		$mockObjService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(static fn (array $q): bool => ($q['@self']['register'] ?? null) === '3'
					&& ($q['@self']['schema'] ?? null) === '11'
					&& $q['_ids'] === ['an-object-in-another-register']),
				true,
				false
			)
			->willReturn(['results' => []]);

		$this->request->server = [];

		$response = $this->controller->show('an-object-in-another-register');

		$this->assertEquals(404, $response->getStatus());
	}

	public function testShowReturns503WhenTheGlossaryIsNotConfigured(): void {
		$mockObjService = $this->mockObjectService();
		$this->config->method('getValueString')->willReturn('');

		$mockObjService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller->show('term-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(503, $response->getStatus());
	}

	public function testShowReturns404WhenNotFound(): void {
		$mockObjService = $this->mockObjectService();
		$this->configureGlossary();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => []]);

		$response = $this->controller->show('nonexistent');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
	}

	public function testShowWithIntegerId(): void {
		$mockObjService = $this->mockObjectService();
		$this->configureGlossary();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [$this->serializableObject(['id' => 42, 'term' => 'Test'])],
			]);

		$this->request->server = [];

		$response = $this->controller->show(42);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * #736: the SOLR backend returns plain array shapes (no jsonSerialize()).
	 * show() must accept them without fataling now that the is_array() guard
	 * precedes the jsonSerialize() call.
	 */
	public function testShowAcceptsArrayShape(): void {
		$mockObjService = $this->mockObjectService();
		$this->configureGlossary();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn([
				'results' => [['id' => 7, 'term' => 'SOLR', 'definition' => 'Search backend']],
			]);

		$this->request->server = [];

		$response = $this->controller->show('7');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}
}
