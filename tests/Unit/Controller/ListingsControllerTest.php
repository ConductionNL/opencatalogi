<?php

/**
 * Unit tests for OCA\OpenCatalogi\Controller\ListingsController.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ListingsController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ListingsController.
 */
class ListingsControllerTest extends TestCase {

	/**
	 * Mocked request object.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest|MockObject $request;

	/**
	 * Mocked app configuration.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig|MockObject $config;

	/**
	 * Mocked server container.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;

	/**
	 * Mocked app manager.
	 *
	 * @var IAppManager|MockObject
	 */
	private IAppManager|MockObject $appManager;

	/**
	 * Mocked directory service.
	 *
	 * @var DirectoryService|MockObject
	 */
	private DirectoryService|MockObject $directoryService;

	/**
	 * Mocked localization service.
	 *
	 * @var IL10N|MockObject
	 */
	private IL10N|MockObject $l10n;

	/**
	 * Mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Controller under test.
	 *
	 * @var ListingsController
	 */
	private ListingsController $controller;

	/**
	 * Set up mocks and a default authenticated-user controller for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->directoryService = $this->createMock(DirectoryService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->l10n->method('t')
			->willReturnCallback(fn (string $text) => $text);

		// Index() and friends guard on an authenticated user; default to logged-in.
		// Tests that verify the 401 path override this with willReturn(null).
		$this->userSession->method('getUser')
			->willReturn($this->createMock(\OCP\IUser::class));

		$this->controller = new ListingsController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->appManager,
			$this->directoryService,
			$this->l10n,
			$this->userSession
		);
	}//end setUp()

	/**
	 * Wire up the container/app-manager mocks so getObjectService() resolves.
	 *
	 * @return MockObject The mocked OpenRegister ObjectService.
	 */
	private function mockObjectService(): MockObject {
		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		// ResolvesRegisterConfiguration asks the container for two more things:
		// OpenRegister's RegisterResolverService (which has not landed yet, so the
		// trait's documented fallback path runs) and IAppConfig (which the fallback
		// reads the keys through). A `->with('...ObjectService')` constraint here
		// would turn either of those into a mock-expectation failure that looks
		// like a controller bug, so route by id instead.
		$this->container->method('get')
			->willReturnCallback(
				function (string $id) use ($mockObjService) {
					if ($id === 'OCA\OpenRegister\Service\ObjectService') {
						return $mockObjService;
					}

					if ($id === \OCP\IAppConfig::class) {
						return $this->config;
					}

					throw new \RuntimeException('not available: ' . $id);
				}
			);

		return $mockObjService;
	}//end mockObjectService()

	/**
	 * Configure `listing_register` / `listing_schema` on the IAppConfig mock.
	 *
	 * @param string $register Value for listing_register.
	 * @param string $schema Value for listing_schema.
	 *
	 * @return void
	 */
	private function configureListingScope(string $register = '3', string $schema = '5'): void {
		$this->config->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default = '') => match ($key) {
					'listing_register' => $register,
					'listing_schema' => $schema,
					default => $default,
				}
			);
	}//end configureListingScope()

	/**
	 * Index() returns a 200 JSON response when OpenRegister is available.
	 *
	 * @return void
	 */
	public function testIndexReturnsJsonResponse(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => [], 'total' => 0]);

		// This used to stub every config key to '' and still assert 200 — which
		// is precisely the unscoped-search state index() must now refuse. The
		// scope is configured here so the test exercises the normal path; the
		// unconfigured path has its own test below.
		$this->configureListingScope();

		$this->request->method('getParams')
			->willReturn([]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testIndexReturnsJsonResponse()

	/**
	 * Index() refuses to run an unscoped search when the listing register/schema
	 * are unconfigured, and does not reach OpenRegister at all.
	 *
	 * Regression test for the fail-open this PR closes: with both keys empty the
	 * old code added no `@self` filter, so searchObjectsPaginated() ran with an
	 * unconstrained query and returned every object the caller could read across
	 * every register and schema on the instance — from a #[NoAdminRequired] GET.
	 *
	 * The `never()` expectation is the point. Asserting only on the status code
	 * would still pass if the controller queried first and then returned a 503,
	 * which would leak into logs, metrics and any cache in between.
	 *
	 * @return void
	 */
	public function testIndexRefusesUnscopedSearchWhenRegisterConfigEmpty(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->expects($this->never())
			->method('searchObjectsPaginated');

		$this->configureListingScope(register: '', schema: '');

		$this->request->method('getParams')
			->willReturn([]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(503, $response->getStatus());
		$this->assertSame('register_not_configured', $response->getData()['error']);
	}//end testIndexRefusesUnscopedSearchWhenRegisterConfigEmpty()

	/**
	 * A half-configured context is refused too.
	 *
	 * The old condition was `if (empty($schema) === false || empty($register) === false)`
	 * — an OR — so a configured register with an empty schema produced a query
	 * scoped to the register and silently unscoped across schemas. That is a
	 * smaller leak than the both-empty case, not a safe one.
	 *
	 * @return void
	 */
	public function testIndexRefusesWhenOnlyOneOfRegisterOrSchemaIsConfigured(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->expects($this->never())
			->method('searchObjectsPaginated');

		$this->configureListingScope(register: '3', schema: '');

		$this->request->method('getParams')
			->willReturn([]);

		$response = $this->controller->index();

		$this->assertEquals(503, $response->getStatus());
	}//end testIndexRefusesWhenOnlyOneOfRegisterOrSchemaIsConfigured()

	/**
	 * The query handed to OpenRegister always carries the configured scope.
	 *
	 * Captures the actual argument rather than trusting the status code: a 200
	 * says the call happened, not that it was constrained.
	 *
	 * @return void
	 */
	public function testIndexAlwaysScopesTheQueryToTheConfiguredRegisterAndSchema(): void {
		$mockObjService = $this->mockObjectService();

		$captured = null;
		$mockObjService->method('searchObjectsPaginated')
			->willReturnCallback(
				function (array $query) use (&$captured) {
					$captured = $query;
					return ['results' => [], 'total' => 0];
				}
			);

		$this->configureListingScope(register: '3', schema: '5');

		$this->request->method('getParams')
			->willReturn([]);

		$this->controller->index();

		$this->assertIsArray($captured, 'searchObjectsPaginated was never called');
		$this->assertArrayHasKey('@self', $captured);
		$this->assertSame('3', $captured['@self']['register']);
		$this->assertSame('5', $captured['@self']['schema']);
	}//end testIndexAlwaysScopesTheQueryToTheConfiguredRegisterAndSchema()

	/**
	 * A caller cannot widen the scope back out through request filters.
	 *
	 * index() already drops `schema` / `register` from `filters`; this pins that
	 * behaviour against the new always-scoped query so a future refactor cannot
	 * reintroduce caller-controlled scope.
	 *
	 * @return void
	 */
	public function testIndexIgnoresCallerSuppliedRegisterAndSchemaFilters(): void {
		$mockObjService = $this->mockObjectService();

		$captured = null;
		$mockObjService->method('searchObjectsPaginated')
			->willReturnCallback(
				function (array $query) use (&$captured) {
					$captured = $query;
					return ['results' => [], 'total' => 0];
				}
			);

		$this->configureListingScope(register: '3', schema: '5');

		$this->request->method('getParams')
			->willReturn(['filters' => ['register' => '999', 'schema' => '888', 'status' => 'active']]);

		$this->controller->index();

		$this->assertSame('3', $captured['@self']['register']);
		$this->assertSame('5', $captured['@self']['schema']);
		$this->assertArrayNotHasKey('register', $captured);
		$this->assertArrayNotHasKey('schema', $captured);
		$this->assertSame('active', $captured['status']);
	}//end testIndexIgnoresCallerSuppliedRegisterAndSchemaFilters()

	/**
	 * Index() honours filters, limit and offset request parameters.
	 *
	 * @return void
	 */
	public function testIndexWithFilters(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => [['id' => 1]], 'total' => 1]);

		$this->config->method('getValueString')
			->willReturnMap(
				[
					['opencatalogi', 'listing_schema', '', '5'],
					['opencatalogi', 'listing_register', '', '3'],
				]
			);

		$this->request->method('getParams')
			->willReturn(['filters' => ['status' => 'active'], 'limit' => 10, 'offset' => 0]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
	}//end testIndexWithFilters()

	/**
	 * Index() throws a RuntimeException when the openregister app is not installed.
	 *
	 * @return void
	 */
	public function testIndexReturns503WhenOpenRegisterNotInstalled(): void {
		// Previously this asserted a raw RuntimeException escaping the controller,
		// i.e. a 500 with a stack trace. index() now resolves the register context
		// BEFORE touching OpenRegister, and an unavailable container surfaces as
		// the same operator-actionable 503 the sibling controllers return
		// (cf. CatalogiControllerTest::testIndexReturns503WhenOpenRegisterNotInstalled).
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$this->container->method('get')
			->willThrowException(new RuntimeException('not available'));

		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn([]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(503, $response->getStatus());
	}//end testIndexReturns503WhenOpenRegisterNotInstalled()

	/**
	 * Show() returns the requested listing's data as a 200 JSON response.
	 *
	 * @return void
	 */
	public function testShowReturnsListingData(): void {
		$mockObjService = $this->mockObjectService();

		$mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockEntity->method('jsonSerialize')
			->willReturn(['id' => '123', 'title' => 'Test Listing']);

		$mockObjService->method('find')
			->willReturn($mockEntity);

		$this->configureListingScope();

		$response = $this->controller->show('123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testShowReturnsListingData()

	/**
	 * Show() accepts an integer ID as well as a string ID.
	 *
	 * @return void
	 */
	public function testShowWithIntegerId(): void {
		$mockObjService = $this->mockObjectService();

		$mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockEntity->method('jsonSerialize')
			->willReturn(['id' => 42]);

		$mockObjService->method('find')
			->willReturn($mockEntity);

		$this->config->method('getValueString')
			->willReturn('');

		$response = $this->controller->show(42);

		$this->assertInstanceOf(JSONResponse::class, $response);
	}//end testShowWithIntegerId()

	/**
	 * Create() returns the newly created listing as a 200 JSON response.
	 *
	 * @return void
	 */
	public function testCreateReturnsNewListing(): void {
		$mockObjService = $this->mockObjectService();

		$mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockObjService->method('saveObject')
			->willReturn($mockEntity);

		$this->configureListingScope();

		$this->request->method('getParams')
			->willReturn(['title' => 'New Listing']);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testCreateReturnsNewListing()

	/**
	 * Assert a controller method carries `#[AuthorizedAdminSetting]` — the actual
	 * admin-vs-non-admin rejection is enforced by Nextcloud's
	 * AuthorizedAdminSettingMiddleware at HTTP-dispatch time, which a unit test that
	 * calls the method directly bypasses (see e2e-verify-listings-admin-gates for the
	 * end-to-end check). This asserts the gate is wired, mirroring how update() and
	 * destroy() are already known-good.
	 *
	 * @param string $method The controller method name to check.
	 *
	 * @return void
	 */
	private function assertHasAuthorizedAdminSettingGate(string $method): void {
		$reflection = new ReflectionMethod(ListingsController::class, $method);
		$attributes = $reflection->getAttributes(AuthorizedAdminSetting::class);

		$this->assertNotEmpty(
			$attributes,
			sprintf('Expected %s::%s to carry #[AuthorizedAdminSetting]', ListingsController::class, $method)
		);
	}//end assertHasAuthorizedAdminSettingGate()

	/**
	 * Create() carries the `#[AuthorizedAdminSetting]` admin gate (LST-003).
	 *
	 * @return void
	 */
	public function testCreateHasAuthorizedAdminSettingGate(): void {
		$this->assertHasAuthorizedAdminSettingGate('create');
	}//end testCreateHasAuthorizedAdminSettingGate()

	/**
	 * Create() only persists CREATABLE_LISTING_FIELDS; off-list fields
	 * (e.g. statusCode, lastSync, available) are silently dropped.
	 *
	 * @return void
	 */
	public function testCreateAllowListsFieldsAndDropsOffListFields(): void {
		$mockObjService = $this->mockObjectService();

		$captured = null;
		$mockObjService->method('saveObject')
			->willReturnCallback(
				function (...$args) use (&$captured) {
					$captured = $args['object'] ?? $args[0];
					return $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
				}
			);

		$this->configureListingScope();

		$this->request->method('getParams')
			->willReturn(
				[
					'title' => 'New Listing',
					'statusCode' => 500,
					'lastSync' => '2026-01-01',
					'available' => false,
				]
			);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$this->assertArrayHasKey('title', $captured);
		$this->assertArrayNotHasKey('statusCode', $captured);
		$this->assertArrayNotHasKey('lastSync', $captured);
		$this->assertArrayNotHasKey('available', $captured);
	}//end testCreateAllowListsFieldsAndDropsOffListFields()

	/**
	 * Create() rejects a `directory` URL that fails the SSRF guard with a 400
	 * and never persists the listing.
	 *
	 * @return void
	 */
	public function testCreateRejectsUnsafeDirectoryUrl(): void {
		$this->mockObjectService();

		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn(['title' => 'New Listing', 'directory' => 'http://169.254.169.254/']);

		$this->directoryService->method('validateOutboundUrl')
			->with('http://169.254.169.254/')
			->willThrowException(new \InvalidArgumentException('Directory URL resolves to a disallowed (internal) address'));

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
	}//end testCreateRejectsUnsafeDirectoryUrl()

	/**
	 * Create() rejects a malformed `directory` URL with a 400 before it ever
	 * reaches the SSRF guard.
	 *
	 * @return void
	 */
	public function testCreateRejectsMalformedDirectoryUrl(): void {
		$this->config->method('getValueString')
			->willReturn('');

		$this->request->method('getParams')
			->willReturn(['title' => 'New Listing', 'directory' => 'not-a-url']);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
	}//end testCreateRejectsMalformedDirectoryUrl()

	/**
	 * Create() persists a listing when the `directory` URL passes the SSRF guard.
	 *
	 * @return void
	 */
	public function testCreateAcceptsSafeDirectoryUrl(): void {
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('saveObject')
			->willReturn($this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class));

		$this->configureListingScope();

		$this->request->method('getParams')
			->willReturn(['title' => 'New Listing', 'directory' => 'https://federated-peer.example.com']);

		$this->directoryService->method('validateOutboundUrl')
			->with('https://federated-peer.example.com');

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testCreateAcceptsSafeDirectoryUrl()

	/**
	 * Update() returns the updated listing as a 200 JSON response.
	 *
	 * @return void
	 */
	public function testUpdateReturnsUpdatedListing(): void {
		$mockObjService = $this->mockObjectService();

		$mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockObjService->method('saveObject')
			->willReturn($mockEntity);

		$this->configureListingScope();

		$this->request->method('getParams')
			->willReturn(['title' => 'Updated Listing']);

		$response = $this->controller->update('123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testUpdateReturnsUpdatedListing()

	/**
	 * Wire up config mocks so the destroy() fail-closed guard on empty
	 * listing_register/listing_schema doesn't fire — the guard was added in
	 * WOO-515 to prevent silent unscoped delete when config is missing.
	 *
	 * @return void
	 */
	private function mockListingConfig(): void {
		$this->config->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') {
					if ($app === 'opencatalogi' && $key === 'listing_register') {
						return '1';
					}

					if ($app === 'opencatalogi' && $key === 'listing_schema') {
						return '2';
					}

					return $default;
				}
			);
	}//end mockListingConfig()

	/**
	 * Destroy() returns a success response when the listing is deleted.
	 *
	 * @return void
	 */
	public function testDestroyReturnsSuccessOnDeletion(): void {
		$this->mockListingConfig();
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('deleteObject')
			->with(uuid: '123', register: '1', schema: '2')
			->willReturn(true);

		$response = $this->controller->destroy('123');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testDestroyReturnsSuccessOnDeletion()

	/**
	 * Destroy() returns 404 with a message when the listing is not found.
	 *
	 * @return void
	 */
	public function testDestroyReturns404WhenNotFound(): void {
		$this->mockListingConfig();
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('deleteObject')
			->with(uuid: 'nonexistent', register: '1', schema: '2')
			->willReturn(false);

		$response = $this->controller->destroy('nonexistent');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
		// Response-shape parity with the DoesNotExistException path (PR #86 R3).
		$data = $response->getData();
		$this->assertArrayHasKey('message', $data);
	}//end testDestroyReturns404WhenNotFound()

	/**
	 * WOO-515: `deleteObject()` throws `DoesNotExistException` when the UUID
	 * exists in the OpenRegister store but under a DIFFERENT (register, schema)
	 * pair — e.g. a catalog row whose UUID coincides with a listing UUID after
	 * a self-sync incident. Controller catches it and returns HTTP 404 with a
	 * scope-specific message.
	 *
	 * @return void
	 */
	public function testDestroyReturns404OnScopeMismatch(): void {
		$this->mockListingConfig();
		$mockObjService = $this->mockObjectService();

		$mockObjService->method('deleteObject')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('scope miss'));

		$response = $this->controller->destroy('collided-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('message', $data);
	}//end testDestroyReturns404OnScopeMismatch()

	/**
	 * WOO-515 fail-closed: destroy() refuses the delete with HTTP 409 when
	 * either `listing_register` or `listing_schema` is empty (fresh /
	 * half-configured install). Prevents the scope-defence from silently
	 * evaporating.
	 *
	 * @return void
	 */
	public function testDestroyReturns409WhenListingRegisterConfigEmpty(): void {
		// Deliberately DON'T call mockListingConfig() — bare mock returns
		// empty string for getValueString, which triggers the fail-closed guard.
		// Also don't wire up the object service; the guard fires before that.
		$response = $this->controller->destroy('anything');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(409, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('message', $data);
	}//end testDestroyReturns409WhenListingRegisterConfigEmpty()

	/**
	 * Synchronise() carries the `#[AuthorizedAdminSetting]` admin gate (DIR-003).
	 *
	 * @return void
	 */
	public function testSynchroniseHasAuthorizedAdminSettingGate(): void {
		$this->assertHasAuthorizedAdminSettingGate('synchronise');
	}//end testSynchroniseHasAuthorizedAdminSettingGate()

	/**
	 * Synchronise() with no ID syncs all known directories via doCronSync().
	 *
	 * @return void
	 */
	public function testSynchroniseAllDirectories(): void {
		$this->directoryService->method('doCronSync')
			->willReturn(['synced' => 5, 'errors' => 0]);

		$response = $this->controller->synchronise(null);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testSynchroniseAllDirectories()

	/**
	 * Synchronise() returns 500 with a message when the sync throws.
	 *
	 * @return void
	 */
	public function testSynchroniseReturns500OnException(): void {
		$this->directoryService->method('doCronSync')
			->willThrowException(new \Exception('Sync failed'));

		$response = $this->controller->synchronise(null);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}//end testSynchroniseReturns500OnException()

	/**
	 * Add() carries the `#[AuthorizedAdminSetting]` admin gate (DIR-005).
	 *
	 * @return void
	 */
	public function testAddHasAuthorizedAdminSettingGate(): void {
		$this->assertHasAuthorizedAdminSettingGate('add');
	}//end testAddHasAuthorizedAdminSettingGate()

	/**
	 * Add() returns 400 when no `url` parameter is supplied.
	 *
	 * @return void
	 */
	public function testAddReturns400WhenNoUrl(): void {
		$this->request->method('getParam')
			->with('url')
			->willReturn(null);

		$response = $this->controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
	}//end testAddReturns400WhenNoUrl()

	/**
	 * Add() returns 400 when the `url` parameter is an empty string.
	 *
	 * @return void
	 */
	public function testAddReturns400WhenEmptyUrl(): void {
		$this->request->method('getParam')
			->with('url')
			->willReturn('');

		$response = $this->controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
	}//end testAddReturns400WhenEmptyUrl()

	/**
	 * Add() returns 200 and the sync result for a valid URL.
	 *
	 * @return void
	 */
	public function testAddReturnsSuccessWithValidUrl(): void {
		$this->request->method('getParam')
			->with('url')
			->willReturn('https://example.com/directory');

		$this->directoryService->method('syncDirectory')
			->with('https://example.com/directory')
			->willReturn(['synced' => 3]);

		$response = $this->controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
	}//end testAddReturnsSuccessWithValidUrl()

	/**
	 * Add() returns 400 when syncDirectory() throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testAddReturns400OnInvalidArgument(): void {
		$this->request->method('getParam')
			->with('url')
			->willReturn('invalid-url');

		$this->directoryService->method('syncDirectory')
			->willThrowException(new \InvalidArgumentException('Invalid URL'));

		$response = $this->controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
	}//end testAddReturns400OnInvalidArgument()

	/**
	 * Add() returns 500 on an unexpected exception from syncDirectory().
	 *
	 * @return void
	 */
	public function testAddReturns500OnGenericException(): void {
		$this->request->method('getParam')
			->with('url')
			->willReturn('https://example.com/dir');

		$this->directoryService->method('syncDirectory')
			->willThrowException(new \Exception('Server error'));

		$response = $this->controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
	}//end testAddReturns500OnGenericException()

	/**
	 * DIR-005 defence-in-depth: the session guard inside add() must still reject an
	 * anonymous caller with 403 even though `#[AuthorizedAdminSetting]` is the
	 * primary (dispatch-time) gate that a unit test bypasses.
	 *
	 * @return void
	 */
	public function testAddReturns403WhenAnonymous(): void {
		$anonymousSession = $this->createMock(IUserSession::class);
		$anonymousSession->method('getUser')->willReturn(null);

		$controller = new ListingsController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->appManager,
			$this->directoryService,
			$this->l10n,
			$anonymousSession
		);

		$response = $controller->add();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());
	}//end testAddReturns403WhenAnonymous()
}//end class
