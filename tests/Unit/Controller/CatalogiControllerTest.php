<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Unit\Support\ServerAwareRequest;

/**
 * Unit tests for CatalogiController.
 */
class CatalogiControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private CatalogiService|MockObject $catalogiService;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private CatalogiController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(ServerAwareRequest::class);
		$this->catalogiService = $this->createMock(CatalogiService::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->controller = new CatalogiController(
			$this->request,
			$this->catalogiService,
			$this->config,
			new OpenRegisterLookup($this->container, $this->appManager)
		);
	}

	public function testPreflightedCorsReturnsResponseWithOriginHeader(): void {
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

	/**
	 * Make OpenRegister's register resolver answer register 3 and schema 5.
	 *
	 * @return void
	 */
	private function resolveCatalogScope(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')
			->willReturnCallback(
				static function (string $id) {
					if ($id !== 'OCA\OpenRegister\Service\RegisterResolverService') {
						throw new RuntimeException('unexpected container lookup: ' . $id);
					}

					// RegisterResolverService is final; use a hand-rolled double.
					return new class {
						public function lookupId(\OCA\OpenRegister\Service\ConfigLookup $lookup): string {
							if (str_ends_with($lookup->configKey, '_register') === true) {
								return '3';
							}

							return '5';
						}
					};
				}
			);
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
	}//end resolveCatalogScope()

	/**
	 * The list hands the request parameters and the resolved catalog scope to the
	 * service and answers its result as JSON.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	public function testIndexReturnsJsonResponse(): void {
		$this->resolveCatalogScope();
		$this->request->method('getParams')->willReturn(['_search' => 'woo']);
		$this->request->server = [];
		$this->catalogiService->expects($this->once())->method('listCatalogs')
			->with(['_search' => 'woo'], ['register' => '3', 'schema' => '5'])
			->willReturn(['results' => [['id' => 1]], 'total' => 1]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 1]], 'total' => 1], $response->getData());
	}

	/**
	 * A cross-origin list answer carries the CORS headers.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	public function testIndexWithCatalogConfiguration(): void {
		$this->resolveCatalogScope();
		$this->catalogiService->method('listCatalogs')->willReturn(['results' => [['id' => 1]], 'total' => 1]);
		$this->request->method('getParams')->willReturn([]);
		$this->request->server = ['HTTP_ORIGIN' => 'https://test.com'];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertArrayHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}

	/**
	 * WOO-581: the caller's scope keys reach the service untouched; the service drops
	 * them and pins the resolved scope (CatalogiServiceTest covers that half).
	 *
	 * @return void
	 */
	public function testIndexStripsTheCallersScopeKeys(): void {
		$this->resolveCatalogScope();
		$caller = ['_schemas' => [56], '@self' => ['schemas' => [56], 'owner' => 'alice']];
		$this->request->method('getParams')->willReturn($caller);
		$this->request->server = [];
		$this->catalogiService->expects($this->once())->method('listCatalogs')
			->with($caller, ['register' => '3', 'schema' => '5'])
			->willReturn(['results' => [], 'total' => 0]);

		$this->assertEquals(200, $this->controller->index()->getStatus());
	}

	/**
	 * An unconfigured catalog scope answers 503 without reaching the service.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	public function testIndexDoesNotSearchWithoutAResolvedScope(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$this->container->method('get')->willThrowException(new RuntimeException('not available'));
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$this->request->server = [];
		$this->catalogiService->expects($this->never())->method('listCatalogs');

		$this->assertSame(503, $this->controller->index()->getStatus());
	}

	public function testIndexReturns503WhenOpenRegisterNotInstalled(): void {
		// With OpenRegister unavailable the resolver cannot be obtained; the
		// controller degrades gracefully to a 503 instead of a raw 500.
		$this->appManager->method('getInstalledApps')
			->willReturn([]);
		$this->container->method('get')
			->willThrowException(new RuntimeException('not available'));

		$this->config->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $default);

		$this->request->method('getParams')
			->willReturn([]);

		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(503, $response->getStatus());
	}

	public function testShowReturnsJsonResponse(): void {
		$expectedResponse = new JSONResponse(['id' => '123', 'title' => 'Test']);

		$this->catalogiService->method('index')
			->with('123')
			->willReturn($expectedResponse);

		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('https://example.com');

		$response = $this->controller->show('123');

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testShowWithNoOriginUsesWildcard(): void {
		$expectedResponse = new JSONResponse(['id' => '456']);

		$this->catalogiService->method('index')
			->with('456')
			->willReturn($expectedResponse);

		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('');

		$response = $this->controller->show('456');

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testShowWithIntegerId(): void {
		$expectedResponse = new JSONResponse(['id' => 42]);

		$this->catalogiService->method('index')
			->with(42)
			->willReturn($expectedResponse);

		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('');

		$response = $this->controller->show(42);

		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	/**
	 * Security (#735): an attacker-controlled Origin header must NOT be reflected
	 * back in Access-Control-Allow-Origin when the allowlist is not '*'. The
	 * controller must fall back to the configured allowlist entry instead.
	 */
	public function testShowDoesNotReflectArbitraryOriginWhenAllowlistConfigured(): void {
		$expectedResponse = new JSONResponse(['id' => '123']);

		$this->catalogiService->method('index')
			->with('123')
			->willReturn($expectedResponse);

		$this->config->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				return match ($key) {
					'cors_allowed_origins' => 'https://trusted.example',
					default => $default,
				};
			});

		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('https://evil.attacker.test');

		$response = $this->controller->show('123');

		$this->assertSame(
			'https://trusted.example',
			$response->getHeaders()['Access-Control-Allow-Origin']
		);
	}

	/**
	 * When the configured allowlist contains the caller's Origin, that exact value
	 * may be echoed back (CORS by-design); otherwise the first allowlist entry wins.
	 */
	public function testShowEchoesOriginOnlyWhenOnAllowlist(): void {
		$expectedResponse = new JSONResponse(['id' => '123']);

		$this->catalogiService->method('index')
			->with('123')
			->willReturn($expectedResponse);

		$this->config->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				return match ($key) {
					'cors_allowed_origins' => 'https://trusted.example,https://other.example',
					default => $default,
				};
			});

		$this->request->method('getHeader')
			->with('Origin')
			->willReturn('https://other.example');

		$response = $this->controller->show('123');

		$this->assertSame(
			'https://other.example',
			$response->getHeaders()['Access-Control-Allow-Origin']
		);
	}

	/**
	 * A controller over this test's doubles with a language-negotiating query service.
	 *
	 * @param PublicationQueryServiceInterface $queryService The query service.
	 *
	 * @return CatalogiController The controller.
	 */
	private function controllerWithLanguage(PublicationQueryServiceInterface $queryService): CatalogiController {
		return new CatalogiController(
			request: $this->request,
			catalogiService: $this->catalogiService,
			config: $this->config,
			registers: new OpenRegisterLookup($this->container, $this->appManager),
			queryService: $queryService
		);
	}

	/**
	 * The catalogue list hands the visitor's language to OpenRegister before it
	 * searches, and its answer names the language served (REQ-PTR-002).
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testIndexPassesTheVisitorsLanguageBeforeTheSearch(): void {
		$order = [];
		$this->catalogiService->method('listCatalogs')
			->willReturnCallback(static function () use (&$order): array {
				$order[] = 'search';
				return ['results' => [], 'total' => 0];
			});
		$this->resolveCatalogScope();
		$this->request->method('getParams')->willReturn(['_lang' => 'en']);
		$this->request->server = ['HTTP_ACCEPT_LANGUAGE' => 'de'];

		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->expects($this->once())
			->method('applyVisitorLanguage')
			->with(['_lang' => 'en'], 'de')
			->willReturnCallback(static function () use (&$order): string {
				$order[] = 'language';
				return 'en';
			});
		$queryService->method('languageHeaders')
			->willReturn(['Content-Language' => 'en', 'X-Source-Language' => 'nl']);

		$response = $this->controllerWithLanguage(queryService: $queryService)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['language', 'search'], $order);
		$this->assertSame('en', $response->getHeaders()['Content-Language']);
		$this->assertSame('nl', $response->getHeaders()['X-Source-Language']);
	}

	/**
	 * A catalogue's publication list is read in the visitor's language and says
	 * when a field fell back to Dutch (REQ-PTR-002).
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testShowPassesTheVisitorsLanguageAndNamesTheFallback(): void {
		$order = [];
		$this->catalogiService->method('index')
			->with('123')
			->willReturnCallback(static function () use (&$order): JSONResponse {
				$order[] = 'read';
				return new JSONResponse(['results' => []]);
			});
		$this->request->method('getParams')->willReturn([]);
		$this->request->server = ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.8'];

		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->expects($this->once())
			->method('applyVisitorLanguage')
			->with([], 'en-GB,en;q=0.8')
			->willReturnCallback(static function () use (&$order): string {
				$order[] = 'language';
				return 'en-GB';
			});
		$queryService->method('languageHeaders')->willReturn(
			['Content-Language' => 'en-GB', 'X-Source-Language' => 'nl', 'X-Content-Language-Fallback' => 'true']
		);

		$headers = $this->controllerWithLanguage(queryService: $queryService)->show('123')->getHeaders();

		$this->assertSame(['language', 'read'], $order);
		$this->assertSame('en-GB', $headers['Content-Language']);
		$this->assertSame('true', $headers['X-Content-Language-Fallback']);
	}

	/**
	 * A controller built without a query service answers without language headers.
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testWithoutAQueryServiceTheAnswerCarriesNoLanguageHeaders(): void {
		$this->catalogiService->method('index')->willReturn(new JSONResponse([]));
		$this->request->method('getParams')->willReturn([]);

		$headers = $this->controller->show('1')->getHeaders();

		$this->assertArrayNotHasKey('Content-Language', $headers);
		$this->assertArrayNotHasKey('X-Source-Language', $headers);
	}

	/**
	 * The 503 for an unconfigured catalogue register carries the CORS headers, so a cross-origin caller can read it.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	public function testThe503CarriesTheCorsHeaders(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$this->container->method('get')->willThrowException(new RuntimeException('not available'));
		$this->config->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $default);
		$this->request->method('getParams')->willReturn([]);
		$this->request->server = [];

		$response = $this->controller->index();

		$this->assertSame(503, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
		$this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
		$this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
	}

	/**
	 * The preflight answers 200 with the origin, methods and headers a browser checks.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-5
	 */
	public function testThePreflightAnswers200WithTheCorsHeaders(): void {
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->preflightedCors();

		$this->assertSame(200, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
		$this->assertNotSame('', $headers['Access-Control-Allow-Methods']);
		$this->assertNotSame('', $headers['Access-Control-Allow-Headers']);
	}

	/**
	 * The public catalogue routes declare their posture as attributes: public, without CSRF, without admin.
	 *
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	public function testThePublicRoutesDeclareTheirAuthPosture(): void {
		$expected = [
			'index' => [PublicPage::class, NoCSRFRequired::class, NoAdminRequired::class],
			'show' => [PublicPage::class, NoCSRFRequired::class, NoAdminRequired::class],
			'preflightedCors' => [PublicPage::class, NoCSRFRequired::class],
		];
		foreach ($expected as $method => $attributes) {
			$declared = array_map(
				static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
				(new \ReflectionMethod(CatalogiController::class, $method))->getAttributes()
			);
			foreach ($attributes as $attribute) {
				$this->assertContains($attribute, $declared, $method . ' lacks ' . $attribute);
			}
		}
	}
}
