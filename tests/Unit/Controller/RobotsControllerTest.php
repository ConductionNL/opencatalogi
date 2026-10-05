<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Http\TextResponse;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for RobotsController.
 */
class RobotsControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private SettingsService|MockObject $settingsService;
	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private IURLGenerator|MockObject $urlGenerator;
	private IL10N|MockObject $l10n;
	private RobotsController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->l10n->method('t')
			->willReturnCallback(fn (string $text) => $text);

		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->createMock(\OCA\OpenCatalogi\Service\SettingsService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$this->controller = new RobotsController(
			'opencatalogi',
			$this->request,
			$this->settingsService,
			$this->container,
			$this->appManager,
			$this->urlGenerator,
			$this->l10n,
			$categories
		);
	}

	public function testIndexReturns500WhenSettingsMissingCatalogRegister(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_schema' => '5',
				],
			]);

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testIndexReturns500WhenSettingsMissingCatalogSchema(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_register' => '1',
				],
			]);

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testGetObjectServiceReturnsServiceWhenInstalled(): void {
		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjService);

		$result = $this->controller->getObjectService();

		$this->assertNotNull($result);
	}

	public function testGetObjectServiceThrowsWhenNotInstalled(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');

		$this->controller->getObjectService();
	}

	public function testIndexReturns500WhenBothSettingsMissing(): void {
		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [],
			]);

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testIndexGeneratesSitemapReferencesForCatalogs(): void {
		// Create a mock object entity with slug.
		$mockCatalog = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->addMethods(['getSlug'])
			->getMock();
		$mockCatalog->method('getSlug')->willReturn('test-catalog');

		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => [$mockCatalog]]);

		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_register' => '1',
					'catalog_schema' => '5',
				],
			]);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com');

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testIndexHandlesCatalogWithFalseSlug(): void {
		// Create a mock object entity that returns false for getSlug (skipped).
		$mockCatalog = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->addMethods(['getSlug'])
			->getMock();
		$mockCatalog->method('getSlug')->willReturn(false);

		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => [$mockCatalog]]);

		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_register' => '1',
					'catalog_schema' => '5',
				],
			]);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com');

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testIndexHandlesNoCatalogs(): void {
		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => []]);

		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_register' => '1',
					'catalog_schema' => '5',
				],
			]);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com');

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	public function testIndexHandlesMultipleCatalogs(): void {
		$mockCatalog1 = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->addMethods(['getSlug'])
			->getMock();
		$mockCatalog1->method('getSlug')->willReturn('catalog-1');

		$mockCatalog2 = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->addMethods(['getSlug'])
			->getMock();
		$mockCatalog2->method('getSlug')->willReturn('catalog-2');

		$mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$mockObjService->method('searchObjectsPaginated')
			->willReturn(['results' => [$mockCatalog1, $mockCatalog2]]);

		$this->settingsService->method('getSettings')
			->willReturn([
				'configuration' => [
					'catalog_register' => '1',
					'catalog_schema' => '5',
				],
			]);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjService);

		$this->urlGenerator->method('getBaseUrl')
			->willReturn('https://example.com/');

		$response = $this->controller->index();

		$this->assertInstanceOf(TextResponse::class, $response);
	}

	/**
	 * A catalogue entity with a slug and its Woo flag.
	 *
	 * @param string $slug The slug.
	 * @param bool   $woo  Whether it publishes a Woo sitemap.
	 */
	private function catalogue(string $slug, bool $woo): \OCA\OpenRegister\Db\ObjectEntity {
		$catalogue = new \OCA\OpenRegister\Db\ObjectEntity();
		$catalogue->setSlug($slug);
		$catalogue->setObject(['slug' => $slug, 'hasWooSitemap' => $woo]);
		return $catalogue;
	}

	/**
	 * The robots.txt text for these catalogues, with the query the controller asked.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\ObjectEntity> $catalogues The search answer.
	 * @param array<string, mixed>|null                      $query      Filled with the query asked.
	 */
	private function robotsFor(array $catalogues, ?array &$query = null): string {
		$objectService = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjectsPaginated'])
			->getMock();
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $asked) use ($catalogues, &$query): array {
				$query = $asked;
				return ['results' => $catalogues];
			}
		);
		$this->settingsService->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '1', 'catalog_schema' => '5']]);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($objectService);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://example.com/');

		return $this->controller->index()->render();
	}

	/** REQ-WIH-001: two Woo catalogues and one other. */
	public function testTwoWooCataloguesAndOneOther(): void {
		$text = $this->robotsFor(
			[$this->catalogue('woo-a', true), $this->catalogue('news', false), $this->catalogue('woo-b', true)],
			$query
		);

		$this->assertTrue($query['hasWooSitemap'], 'OpenRegister is asked for Woo-enabled catalogues only.');
		$lines = array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_starts_with($line, 'Sitemap: ')));
		$this->assertCount(36, $lines);
		$this->assertSame('Sitemap: https://example.com/apps/opencatalogi/api/woo-a/sitemaps/sitemapindex-diwoo-infocat001.xml', $lines[0]);
		$this->assertSame('Sitemap: https://example.com/apps/opencatalogi/api/woo-b/sitemaps/sitemapindex-diwoo-infocat018.xml', $lines[35]);
		$this->assertStringNotContainsString('/news/', $text);
		$this->assertStringNotContainsString('\n', $text);
		$this->assertStringEndsWith("\n", $text);
	}

	/** REQ-WIH-001: the sitemap paths are allowed. */
	public function testTheSitemapPathsAreAllowed(): void {
		$text = $this->robotsFor([$this->catalogue('woo-a', true)]);

		$this->assertStringContainsString("User-agent: *\n", $text);
		$this->assertStringContainsString("Allow: /apps/opencatalogi/api/\n", $text);
		$this->assertStringContainsString("Allow: /index.php/apps/opencatalogi/api/\n", $text);
	}
}
