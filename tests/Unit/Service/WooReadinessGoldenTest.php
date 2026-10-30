<?php

/**
 * Golden test for WooReadinessService::runCheck.
 *
 * Every case is recorded once and replayed from the fixture: the report (all
 * checks, in order, without the run time) and every URL fetched.
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

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for WooReadinessService::runCheck.
 *
 * @covers \OCA\OpenCatalogi\Service\WooReadinessService
 */
class WooReadinessGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/woo-readiness-golden.json';

	private const BASE = 'https://example.org';

	/**
	 * Every URL fetched, in order.
	 *
	 * @var array<int, string>
	 */
	private array $fetched = [];

	/**
	 * The recorded cases: what the world answers.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'all pass' => [[]],
			'robots unreachable' => [['robots' => 404]],
			'robots without sitemap' => [['robots' => 'no-sitemap']],
			'index unreachable' => [['index' => 500]],
			'index not xml' => [['index' => 'bad-xml']],
			'index without pages' => [['index' => 'empty']],
			'pages mixed' => [['pages' => ['ok', 'no-diwoo', 'bad-xml', 404]]],
			'pages without documents' => [['pages' => ['no-diwoo']]],
			'document unreachable' => [['document' => 404]],
			'validator invalid' => [['validator' => 'invalid']],
			'validator error' => [['validator' => 'error']],
			'validator throws' => [['validator' => 'throws']],
			'version behind' => [['version' => StandardsVersionService::STATUS_BEHIND]],
			'version unknown' => [['version' => StandardsVersionService::STATUS_UNKNOWN]],
			'ssrf blocks the index' => [['ssrf' => '/sitemaps/']],
			'network error on a page' => [['pages' => ['throw', 'ok']]],
			'registration mismatch' => [['registration' => ['registered', 'https://other.org']]],
			'registration requested' => [['registration' => ['requested', '']]],
			'no catalogs' => [['catalogs' => []]],
			'request cap reached' => [['catalogs' => ['c1', 'c2', 'c3', 'c4', 'c5'], 'pages' => ['ok', 'ok', 'ok', 'ok']]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReportMatchesTheRecording(array $world): void {
		$name = $this->dataName();
		$report = $this->service(world: $world)->runCheck();
		unset($report['checkedAt']);
		$actual = ['report' => $report, 'fetched' => $this->fetched];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testReportMatchesTheRecording()

	/**
	 * Build the service over the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return WooReadinessService The service.
	 */
	private function service(array $world): WooReadinessService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($world): IResponse {
				$this->fetched[] = $url;
				return $this->answer(url: $url, world: $world);
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$directory = $this->createMock(DirectoryService::class);
		$directory->method('validateOutboundUrl')->willReturnCallback(
			static function (string $url) use ($world): void {
				if (isset($world['ssrf']) === true && str_contains($url, $world['ssrf']) === true && str_contains($url, 'page=') === false) {
					throw new InvalidArgumentException('blocked');
				}
			}
		);

		$sitemaps = $this->createMock(SitemapService::class);
		$sitemaps->method('sitemapFiles')->willReturn(['sitemapindex-diwoo-infocat001.xml' => 'Wetten']);
		$sitemaps->method('validateDiwooOutput')->willReturnCallback(
			static function (string $catalogSlug, string $categoryCode, int $page) use ($world): array {
				return match ($world['validator'] ?? 'valid') {
					'invalid' => ['valid' => false, 'violations' => ['x']],
					'error' => ['error' => 'nope'],
					'throws' => throw new \RuntimeException('validator down'),
					default => ['valid' => true, 'violations' => [], 'catalogSlug' => $catalogSlug, 'categoryCode' => $categoryCode, 'page' => $page],
				};
			}
		);

		$catalogs = [];
		foreach (($world['catalogs'] ?? ['demo', '', 'second']) as $slug) {
			$catalog = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
				->disableOriginalConstructor()
				->addMethods(['getSlug'])
				->getMock();
			$catalog->method('getSlug')->willReturn($slug);
			$catalogs[] = $catalog;
		}

		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => $catalogs]);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '1', 'catalog_schema' => '5']]);
		$settings->method('getObjectService')->willReturn($objects);

		$registration = ($world['registration'] ?? ['registered', self::BASE]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'woo_index_registration_status' => $registration[0],
				'woo_index_registration_url' => $registration[1],
				'woo_index_registration_at' => '2026-01-01',
				default => $default,
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn(self::BASE . '/');

		$versions = $this->createMock(StandardsVersionService::class);
		$versions->method('diwooVersion')->willReturn(
			['declared' => '0.9.8', 'published' => '0.9.9', 'status' => ($world['version'] ?? StandardsVersionService::STATUS_CURRENT)]
		);

		return new WooReadinessService($clients, $directory, $sitemaps, $settings, $config, $urls, $versions);

	}//end service()

	/**
	 * What the world answers for a URL.
	 *
	 * @param string               $url   The URL.
	 * @param array<string, mixed> $world The world.
	 *
	 * @return IResponse The answer.
	 */
	private function answer(string $url, array $world): IResponse {
		$status = 200;
		$body = '';
		if (str_ends_with($url, '/robots.txt') === true) {
			$robots = ($world['robots'] ?? 200);
			$status = (is_int($robots) === true ? $robots : 200);
			$body = ($robots === 'no-sitemap' ? "User-agent: *\n" : "Sitemap: x/sitemaps/y\n");
		} else if (str_contains($url, 'page=') === true) {
			$index = (int)substr($url, (int)strrpos($url, '=') + 1) - 1;
			$page = (($world['pages'] ?? ['ok'])[$index] ?? 'ok');
			if ($page === 'throw') {
				throw new \RuntimeException('network');
			}

			$status = (is_int($page) === true ? $page : 200);
			$body = match ($page) {
				'no-diwoo' => '<urlset><url><loc>https://example.org/x</loc></url></urlset>',
				'bad-xml' => '<urlset',
				default => '<docs xmlns:diwoo="d"><diwoo:Document><loc>https://example.org/doc-' . ($index + 1) . '.pdf</loc></diwoo:Document></docs>',
			};
		} else if (str_contains($url, '/sitemaps/') === true) {
			$index = ($world['index'] ?? 200);
			$status = (is_int($index) === true ? $index : 200);
			$count = count($world['pages'] ?? ['ok']);
			$locs = '';
			for ($i = 1; $i <= $count; $i++) {
				$locs .= '<sitemap><loc>' . $url . '/publications?page=' . $i . '</loc></sitemap>';
			}

			$body = match ($index) {
				'bad-xml' => '<sitemapindex',
				'empty' => '<sitemapindex></sitemapindex>',
				default => '<sitemapindex>' . $locs . '</sitemapindex>',
			};
		} else if (str_contains($url, 'doc-') === true) {
			$status = ($world['document'] ?? 200);
			$body = 'pdf';
		} else {
			$status = 404;
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;

	}//end answer()
}//end class
