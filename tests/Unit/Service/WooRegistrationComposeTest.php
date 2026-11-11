<?php

/**
 * Pins how WooRegistrationService::compose() gathers the sitemaps and the
 * organisations of the Woo-enabled catalogs.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCA\OpenCatalogi\Service\WooRegistrationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for WooRegistrationService::compose().
 */
class WooRegistrationComposeTest extends TestCase {

	/**
	 * The organisation lookups made, in order.
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool, 2: bool}>
	 */
	private array $lookups = [];

	/**
	 * Catalogs without a slug are skipped, an organisation shared by two
	 * catalogs is listed once, and an organisation that cannot be read, is
	 * empty or is not an object is left out; a missing name falls back to the title.
	 *
	 * @return void
	 */
	public function testTheSitemapsAndTheOrganisationsOfEveryCatalog(): void {
		$request = $this->service(
			catalogs: [
				$this->catalog(slug: 'woo-a', organisation: 'org-1'),
				$this->catalog(slug: '', organisation: 'org-2'),
				$this->catalog(slug: 'woo-b', organisation: 'org-1'),
				$this->catalog(slug: 'woo-c', organisation: ''),
				$this->catalog(slug: 'woo-d', organisation: 'org-titled'),
				$this->catalog(slug: 'woo-e', organisation: 'org-broken'),
				$this->catalog(slug: 'woo-f', organisation: 'org-none'),
				$this->catalog(slug: 'woo-g', organisation: 'org-scalar'),
			]
		)->compose();

		$this->assertSame(
			[
				['name' => 'Gemeente Voorbeeld', 'tooiIdentifier' => 'gm9999'],
				['name' => 'Waterschap', 'tooiIdentifier' => ''],
			],
			$request['organisations']
		);
		$this->assertSame('https://woo.example.nl', $request['baseUrl']);
		$this->assertSame('https://woo.example.nl/robots.txt', $request['robotsTxt']);

		$slugs = array_values(array_unique(array_map(
			static fn (string $url): string => explode('/', substr($url, strlen('https://woo.example.nl/apps/opencatalogi/api/')))[0],
			$request['sitemapIndexes']
		)));
		$this->assertSame(['woo-a', 'woo-b', 'woo-c', 'woo-d', 'woo-e', 'woo-f', 'woo-g'], $slugs);
		$this->assertCount(7 * 18, $request['sitemapIndexes']);
		$this->assertSame(
			[
				[['@self' => ['uuid' => 'org-1']], false, false],
				[['@self' => ['uuid' => 'org-1']], false, false],
				[['@self' => ['uuid' => 'org-titled']], false, false],
				[['@self' => ['uuid' => 'org-broken']], false, false],
				[['@self' => ['uuid' => 'org-none']], false, false],
				[['@self' => ['uuid' => 'org-scalar']], false, false],
			],
			$this->lookups
		);
	}//end testTheSitemapsAndTheOrganisationsOfEveryCatalog()

	/**
	 * Without Woo-enabled catalogs the request lists nothing.
	 *
	 * @return void
	 */
	public function testWithoutCatalogsTheRequestListsNothing(): void {
		$request = $this->service(catalogs: [])->compose();

		$this->assertSame(
			[
				'organisations' => [],
				'baseUrl' => 'https://woo.example.nl',
				'robotsTxt' => 'https://woo.example.nl/robots.txt',
				'sitemapIndexes' => [],
			],
			$request
		);
	}//end testWithoutCatalogsTheRequestListsNothing()

	/**
	 * A Woo-enabled catalog.
	 *
	 * @param string $slug The catalog's slug.
	 * @param string $organisation The organisation's uuid.
	 *
	 * @return ObjectEntity
	 */
	private function catalog(string $slug, string $organisation): ObjectEntity {
		$catalog = new ObjectEntity();
		$catalog->setSlug($slug);
		$catalog->setObject(['slug' => $slug, 'hasWooSitemap' => true, 'organization' => $organisation]);

		return $catalog;
	}//end catalog()

	/**
	 * The service over the given catalogs and a register of organisations.
	 *
	 * @param list<ObjectEntity> $catalogs The Woo-enabled catalogs.
	 *
	 * @return WooRegistrationService
	 */
	private function service(array $catalogs): WooRegistrationService {
		$readiness = $this->getMockBuilder(WooReadinessService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getWooEnabledCatalogs'])
			->getMock();
		$readiness->method('getWooEnabledCatalogs')->willReturn($catalogs);

		$named = new ObjectEntity();
		$named->setObject(['name' => 'Gemeente Voorbeeld', 'title' => 'ignored', 'tooiIdentifier' => 'gm9999']);
		$organisations = [
			'org-1' => [$named],
			'org-titled' => [['title' => 'Waterschap']],
			'org-none' => [],
			'org-scalar' => ['text'],
		];

		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjects'])
			->getMock();
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac = true, bool $_multitenancy = true) use ($organisations): array {
				$this->lookups[] = [$query, $_rbac, $_multitenancy];
				$uuid = $query['@self']['uuid'];
				if ($uuid === 'org-broken') {
					throw new RuntimeException('register down');
				}

				return ($organisations[$uuid] ?? []);
			}
		);
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$settings->method('getObjectService')->willReturn($objectService);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://woo.example.nl/');

		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->createMock(SettingsService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new \OCA\OpenCatalogi\Service\TooiVocabularyService()),
		);

		return new WooRegistrationService(
			$readiness,
			$this->createMock(NationalIndexService::class),
			$settings,
			$this->createMock(IAppConfig::class),
			$urls,
			$categories
		);
	}//end service()
}//end class
