<?php

/**
 * Unit tests for StatsController.
 *
 * Covers: per-publication stats authorization (no IDOR), anonymous/unauthorized
 * rejection, catalog roll-up + top-N, period-without-data zeros, and the CSV
 * export column/BOM contract.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\StatsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Controller\StatsController
 * @uses \OCA\OpenCatalogi\Service\QualityService
 */
class StatsControllerTest extends TestCase {

	private IRequest|MockObject $request;

	private UsageCounterService|MockObject $usage;

	private CatalogiService|MockObject $catalogi;

	private IAppManager|MockObject $appManager;

	private ContainerInterface|MockObject $container;

	private IL10N|MockObject $l10n;

	private IUserSession|MockObject $userSession;

	private LoggerInterface|MockObject $logger;

	private StatsController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->usage = $this->createMock(UsageCounterService::class);
		$this->catalogi = $this->createMock(CatalogiService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->l10n->method('t')->willReturnCallback(fn (string $t, array $p = []) => $t);
		$this->request->method('getParam')
			->willReturnCallback(fn (string $k, $d = null) => $d);

		// Default: an authenticated officer is present (override in anon tests).
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new StatsController(
			'opencatalogi',
			$this->request,
			$this->usage,
			$this->catalogi,
			$this->appManager,
			$this->container,
			$this->l10n,
			$this->userSession,
			$this->logger,
		);
	}//end setUp()

	/**
	 * An ObjectService double that behaves like OpenRegister's searchObjects.
	 *
	 * It finds an object only when the query names its own register, schema and
	 * uuid together, with RBAC on, and the caller may read it. A uuid-only query,
	 * or one in another register or schema, finds nothing, as in OpenRegister.
	 *
	 * @param array<int, string> $readable The uuids the caller may read.
	 */
	private function objectService(array $readable): ObjectService|MockObject {
		$homes = [
			'pub-1' => '31/7',
			'pub-secret' => '31/7',
			'pub-elsewhere' => '99/99',
			'cat-public' => '31/1115',
			'cat-secret' => '31/1115',
		];
		$os = $this->createMock(ObjectService::class);
		$os->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($readable, $homes): array {
				$self = ($query['@self'] ?? []);
				if (empty($self['register']) === true || empty($self['schema']) === true || empty($self['uuid']) === true) {
					return [];
				}

				$uuid = $self['uuid'];
				if (($homes[$uuid] ?? null) !== $self['register'] . '/' . $self['schema']) {
					return [];
				}

				if ($_rbac !== true || in_array($uuid, $readable, true) === false) {
					return [];
				}

				return [['id' => $uuid]];
			}
		);
		return $os;
	}//end objectService()

	/**
	 * Wire OpenRegister and the container for the guard.
	 *
	 * The configured catalogs span registers 30 and 31 and schemas 6 and 7, so
	 * `pub-1` (31/7) is in scope and `pub-elsewhere` (99/99) is not.
	 *
	 * @param array<int, string>   $readable The uuids the caller may read.
	 * @param array<string, mixed> $extra    Further container entries.
	 */
	private function wire(array $readable, array $extra = []): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->catalogi->method('getCatalogFilters')->willReturn(['registers' => ['30', 31], 'schemas' => [6, '7']]);

		$services = $extra + [
			'OCA\OpenRegister\Service\ObjectService' => $this->objectService(readable: $readable),
		];
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				if (array_key_exists($id, $services) === false) {
					throw new \RuntimeException('unexpected: ' . $id);
				}

				return $services[$id];
			}
		);
	}//end wire()

	/**
	 * Make the slug lookup resolve to the catalog with this uuid.
	 */
	private function resolveCatalog(string $id): void {
		$this->catalogi->method('getCatalogBySlug')->willReturn(
			[
				'id' => $id,
				'slug' => 'woo',
				'hasDcat' => true,
				'@self' => ['id' => $id, 'register' => '31', 'schema' => '1115'],
			]
		);
	}//end resolveCatalog()

	/**
	 * A controller whose request carries forged identity and target parameters.
	 */
	private function controllerWithForgedRequest(): StatsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => match ($k) {
				'userId', 'requestedBy', 'owner' => 'admin',
				'id', 'catalog' => 'cat-public',
				'register', 'schema' => '99',
				default => $d,
			}
		);
		return new StatsController(
			'opencatalogi',
			$request,
			$this->usage,
			$this->catalogi,
			$this->appManager,
			$this->container,
			$this->l10n,
			$this->userSession,
			$this->logger,
		);
	}//end controllerWithForgedRequest()

	// ──────────────────────────────────────────────────────────
	// Per-publication stats authorization (ANA-004 / no-IDOR)
	// ──────────────────────────────────────────────────────────

	public function testPublicationStatsAuthorizedReturnsData(): void {
		$this->wire(readable: ['pub-1']);
		$this->usage->method('getPublicationStats')->willReturn(
			['views' => 120, 'downloads' => 40, 'series' => [], 'countingStart' => '2026-05-01']
		);

		$resp = $this->controller->publication('pub-1');
		$this->assertInstanceOf(JSONResponse::class, $resp);
		$this->assertSame(200, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(120, $data['views']);
		$this->assertSame('pub-1', $data['publication']);
		$this->assertSame('2026-05-01', $data['countingStart']);
	}//end testPublicationStatsAuthorizedReturnsData()

	public function testPublicationStatsDeniedForUnauthorizedUser(): void {
		// Object not readable under the caller's RBAC → 403, no stats (no IDOR).
		$this->wire(readable: ['pub-1']);
		$this->usage->expects($this->never())->method('getPublicationStats');

		$resp = $this->controller->publication('pub-secret');
		$this->assertSame(403, $resp->getStatus());
	}//end testPublicationStatsDeniedForUnauthorizedUser()

	public function testPublicationStatsDeniedOutsideTheCatalogScope(): void {
		$this->wire(readable: ['pub-elsewhere']);
		$this->usage->expects($this->never())->method('getPublicationStats');

		$resp = $this->controller->publication('pub-elsewhere');
		$this->assertSame(403, $resp->getStatus());
	}//end testPublicationStatsDeniedOutsideTheCatalogScope()

	public function testPublicationStatsIgnoresAForgedRegisterAndSchema(): void {
		$this->wire(readable: ['pub-elsewhere']);
		$this->usage->expects($this->never())->method('getPublicationStats');

		$resp = $this->controllerWithForgedRequest()->publication('pub-elsewhere');
		$this->assertSame(403, $resp->getStatus());
	}//end testPublicationStatsIgnoresAForgedRegisterAndSchema()

	public function testPublicationStatsDeniedWhenTheScopeLookupFails(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->catalogi->method('getCatalogFilters')->willThrowException(new \RuntimeException('config gone'));
		$this->usage->expects($this->never())->method('getPublicationStats');

		$resp = $this->controller->publication('pub-1');
		$this->assertSame(403, $resp->getStatus());
	}//end testPublicationStatsDeniedWhenTheScopeLookupFails()

	public function testPublicationStatsDeniedWhenOpenRegisterMissing(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$resp = $this->controller->publication('pub-1');
		$this->assertSame(403, $resp->getStatus());
	}//end testPublicationStatsDeniedWhenOpenRegisterMissing()

	// ──────────────────────────────────────────────────────────
	// Catalog roll-up + top-N (ANA-005)
	// ──────────────────────────────────────────────────────────

	public function testCatalogStatsReturnsRollup(): void {
		$this->resolveCatalog(id: 'cat-public');
		$this->wire(readable: ['cat-public']);
		$this->usage->method('getCatalogStats')->willReturn(
			[
				'views' => 200,
				'downloads' => 50,
				'topViewed' => [['publication' => 'a', 'views' => 100, 'downloads' => 0]],
				'topDownloaded' => [],
				'countingStart' => '2026-01-01',
			]
		);

		// The controller re-derives countingStart from the full series (ANA-005).
		// aggregateSeries() always returns this key in production, so the double
		// must too — an unstubbed mock returns [] and the read warns.
		$this->usage->method('aggregateSeries')->willReturn(
			[
				'views' => 200,
				'downloads' => 50,
				'series' => [],
				'countingStart' => '2026-01-01',
			]
		);

		$resp = $this->controller->catalog('woo');
		$this->assertSame(200, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(200, $data['views']);
		$this->assertSame('woo', $data['catalog']);
		$this->assertSame('a', $data['topViewed'][0]['publication']);
		$this->assertSame('2026-01-01', $data['countingStart']);
	}//end testCatalogStatsReturnsRollup()

	public function testCatalogStatsDeniedWhenCallerCannotReadCatalog(): void {
		$this->resolveCatalog(id: 'cat-secret');
		$this->wire(readable: ['cat-public']);
		$this->usage->expects($this->never())->method('getCatalogStats');
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controller->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsDeniedWhenCallerCannotReadCatalog()

	public function testCatalogStatsIgnoresForgedIdentityParameters(): void {
		$this->resolveCatalog(id: 'cat-secret');
		$this->wire(readable: ['cat-public']);
		$this->usage->expects($this->never())->method('getCatalogStats');
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controllerWithForgedRequest()->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsIgnoresForgedIdentityParameters()

	public function testCatalogStatsReadsTheCatalogInItsOwnRegisterAndSchema(): void {
		$this->resolveCatalog(id: 'cat-public');
		$this->wire(readable: ['cat-public']);
		$this->usage->method('getCatalogStats')->willReturn(['views' => 1, 'downloads' => 0, 'topViewed' => [], 'topDownloaded' => []]);
		$this->usage->method('aggregateSeries')->willReturn(['views' => 1, 'downloads' => 0, 'series' => [], 'countingStart' => null]);

		$resp = $this->controllerWithForgedRequest()->catalog('woo');
		$this->assertSame(200, $resp->getStatus());
	}//end testCatalogStatsReadsTheCatalogInItsOwnRegisterAndSchema()

	public function testCatalogStatsDeniedWhenTheReadThrows(): void {
		$this->resolveCatalog(id: 'cat-public');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$os = $this->createMock(ObjectService::class);
		$os->method('searchObjects')->willThrowException(new \RuntimeException('db gone'));
		$this->container->method('get')->willReturn($os);
		$this->usage->expects($this->never())->method('getCatalogStats');
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controller->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsDeniedWhenTheReadThrows()

	public function testCatalogStatsDeniedWhenTheContainerThrows(): void {
		$this->resolveCatalog(id: 'cat-public');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willThrowException(new \RuntimeException('no service'));
		$this->usage->expects($this->never())->method('getCatalogStats');
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controller->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsDeniedWhenTheContainerThrows()

	public function testCatalogStatsDeniedForCatalogWithoutRegisterAndSchema(): void {
		$this->catalogi->method('getCatalogBySlug')->willReturn(['id' => 'cat-public', 'slug' => 'woo']);
		$this->wire(readable: ['cat-public']);
		$this->usage->expects($this->never())->method('getCatalogStats');

		$resp = $this->controller->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsDeniedForCatalogWithoutRegisterAndSchema()

	public function testCatalogStatsDeniedForCatalogWithoutId(): void {
		$this->catalogi->method('getCatalogBySlug')->willReturn(['slug' => 'woo']);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->expects($this->never())->method('get');
		$this->usage->expects($this->never())->method('getCatalogStats');

		$resp = $this->controller->catalog('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testCatalogStatsDeniedForCatalogWithoutId()

	public function testCatalogStatsUnknownCatalog(): void {
		$this->catalogi->method('getCatalogBySlug')->willReturn(null);
		$resp = $this->controller->catalog('nope');
		$this->assertSame(404, $resp->getStatus());
	}//end testCatalogStatsUnknownCatalog()

	// ──────────────────────────────────────────────────────────
	// Instance-wide series for the dashboard usage card (ANA-009)
	// ──────────────────────────────────────────────────────────

	public function testSeriesReturnsInstanceSeries(): void {
		$payload = [
			'views' => 12,
			'downloads' => 2,
			'series' => [['date' => '2026-05-02', 'views' => 12, 'downloads' => 0]],
			'countingStart' => '2026-01-01',
			'from' => '2026-05-01',
			'to' => '2026-05-31',
		];
		$this->usage->expects($this->once())
			->method('aggregateInstanceSeries')
			->with(null, null)
			->willReturn($payload);

		$resp = $this->controller->series();
		$this->assertSame(200, $resp->getStatus());
		$this->assertSame($payload, $resp->getData());
	}//end testSeriesReturnsInstanceSeries()

	public function testSeriesPassesWindowThrough(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => match ($k) {
				'from' => '2026-05-01',
				'to' => '2026-05-31',
				default => $d,
			}
		);
		$controller = new StatsController(
			'opencatalogi',
			$request,
			$this->usage,
			$this->catalogi,
			$this->appManager,
			$this->container,
			$this->l10n,
			$this->userSession,
			$this->logger,
		);
		$this->usage->expects($this->once())
			->method('aggregateInstanceSeries')
			->with('2026-05-01', '2026-05-31')
			->willReturn(['views' => 0, 'downloads' => 0, 'series' => [], 'countingStart' => null, 'from' => '2026-05-01', 'to' => '2026-05-31']);

		$resp = $controller->series();
		$this->assertSame(200, $resp->getStatus());
	}//end testSeriesPassesWindowThrough()

	public function testSeriesRejectsAnonymous(): void {
		$anon = $this->createMock(IUserSession::class);
		$anon->method('getUser')->willReturn(null);
		$controller = new StatsController(
			'opencatalogi',
			$this->request,
			$this->usage,
			$this->catalogi,
			$this->appManager,
			$this->container,
			$this->l10n,
			$anon,
			$this->logger,
		);
		$this->usage->expects($this->never())->method('aggregateInstanceSeries');

		$resp = $controller->series();
		$this->assertSame(403, $resp->getStatus());
	}//end testSeriesRejectsAnonymous()

	public function testSeriesFailureIsA500NotALeak(): void {
		$this->usage->method('aggregateInstanceSeries')->willThrowException(new \RuntimeException('db gone'));

		$resp = $this->controller->series();
		$this->assertSame(500, $resp->getStatus());
		$this->assertArrayNotHasKey('series', $resp->getData());
		$this->assertStringNotContainsString('db gone', json_encode($resp->getData()));
	}//end testSeriesFailureIsA500NotALeak()

	// ──────────────────────────────────────────────────────────
	// CSV export (ANA-007)
	// ──────────────────────────────────────────────────────────

	public function testExportDeniedWhenCallerCannotReadCatalog(): void {
		$this->resolveCatalog(id: 'cat-secret');
		$this->wire(readable: ['cat-public']);
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controller->export('woo');
		$this->assertInstanceOf(JSONResponse::class, $resp);
		$this->assertSame(403, $resp->getStatus());
	}//end testExportDeniedWhenCallerCannotReadCatalog()

	public function testExportIgnoresForgedIdentityParameters(): void {
		$this->resolveCatalog(id: 'cat-secret');
		$this->wire(readable: ['cat-public']);
		$this->usage->expects($this->never())->method('getCountersForCatalog');

		$resp = $this->controllerWithForgedRequest()->export('woo');
		$this->assertSame(403, $resp->getStatus());
	}//end testExportIgnoresForgedIdentityParameters()

	public function testExportProducesCsvDownload(): void {
		$this->resolveCatalog(id: 'cat-public');
		$this->wire(readable: ['cat-public']);
		$this->usage->method('getCountersForCatalog')->willReturn(
			[
				['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'view', 'count' => 5],
				['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'download', 'count' => 2],
			]
		);

		$resp = $this->controller->export('woo');
		$this->assertInstanceOf(DataDownloadResponse::class, $resp);
	}//end testExportProducesCsvDownload()

	public function testBuildCsvBomAndColumns(): void {
		$csv = $this->controller->buildCsv(
			[
				['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'view', 'count' => 5],
				['publication' => 'a', 'category' => 'Besluiten', 'published' => '2026-01-01', 'kind' => 'download', 'count' => 2],
				['publication' => 'b', 'category' => 'Nota', 'published' => '2026-02-01', 'kind' => 'view', 'count' => 0],
			]
		);

		// UTF-8 BOM prefix.
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		// Header columns (the "Published date" cell may be quoted by fputcsv).
		$this->assertMatchesRegularExpression(
			'/Publication,Category,"?Published date"?,Views,Downloads/',
			$csv
		);
		// Publication 'a' rolled up to 5 views, 2 downloads.
		$this->assertMatchesRegularExpression('/a,Besluiten,2026-01-01,5,2/', $csv);
		// Zero-usage publication 'b' is still present with zeros (reach reporting).
		$this->assertMatchesRegularExpression('/b,Nota,2026-02-01,0,0/', $csv);
	}//end testBuildCsvBomAndColumns()

	// ──────────────────────────────────────────────────────────
	// Quality roll-up (PQM-003)
	// ──────────────────────────────────────────────────────────

	public function testQualityRejectsAnonymous(): void {
		// Rebuild the controller with an anonymous session.
		$anon = $this->createMock(IUserSession::class);
		$anon->method('getUser')->willReturn(null);

		$controller = new StatsController(
			'opencatalogi',
			$this->request,
			$this->usage,
			$this->catalogi,
			$this->appManager,
			$this->container,
			$this->l10n,
			$anon,
			$this->logger,
		);

		$response = $controller->quality('woo');
		$this->assertSame(403, $response->getStatus());
	}//end testQualityRejectsAnonymous()

	public function testQualityDeniedWhenCallerCannotReadCatalog(): void {
		$this->resolveCatalog(id: 'cat-secret');
		$this->wire(readable: ['cat-public']);

		$response = $this->controllerWithForgedRequest()->quality('woo');
		$this->assertSame(403, $response->getStatus());
	}//end testQualityDeniedWhenCallerCannotReadCatalog()

	public function testQualityReturnsRollup(): void {
		$this->resolveCatalog(id: 'cat-public');

		$dcat = $this->createMock(\OCA\OpenCatalogi\Service\DcatService::class);
		$dcat->method('buildCatalogDocument')->willReturn(
			[
				'@graph' => [
					['@type' => 'dcat:Catalog', '@id' => 'c'],
					['@type' => 'dcat:Dataset', '@id' => 'd1', 'dct:title' => 'x'],
					['@type' => 'dcat:Dataset', '@id' => 'd2', 'dct:title' => 'y'],
				],
			]
		);

		$quality = new \OCA\OpenCatalogi\Service\QualityService($this->createConfiguredMock(\OCP\IAppConfig::class, []));

		$this->wire(
			readable: ['cat-public'],
			extra: [
				\OCA\OpenCatalogi\Contract\DcatServiceInterface::class => $dcat,
				\OCA\OpenCatalogi\Contract\QualityServiceInterface::class => $quality,
			]
		);

		$response = $this->controller->quality('woo');
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['count']);
		$this->assertArrayHasKey('average', $data);
		$this->assertArrayHasKey('worst', $data);
		$this->assertSame('woo', $data['catalog']);
	}//end testQualityReturnsRollup()

}//end class
