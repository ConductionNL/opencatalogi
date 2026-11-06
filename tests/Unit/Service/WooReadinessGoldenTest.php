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
use OCA\OpenCatalogi\Service\SitemapStandards;
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
 * @covers \OCA\OpenCatalogi\Service\SitemapStandards
 * @uses   \OCA\OpenCatalogi\Service\CatalogReadinessChecks
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
	 * The catalog searches the service ran, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $searched = [];

	/**
	 * The request options of every fetch, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $options = [];

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
			'settings unreadable' => [['settings' => 'throws']],
			'catalog register not configured' => [['settings' => 'no-register']],
			'catalog schema not configured' => [['settings' => 'no-schema']],
			'object service unavailable' => [['objects' => 'throws']],
			'catalog search fails' => [['search' => 'throws']],
			'catalog search without results' => [['search' => 'no-results']],
			'robots as a stream' => [['robots' => 'stream']],
			'catalog slug not text' => [['catalogs' => [null, 'demo']]],
			'document redirects' => [['document' => 302]],
			'robots answers no content' => [['robots' => 204]],
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
		$actual = ['report' => $report, 'fetched' => $this->fetched, 'searched' => $this->searched];

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
			function (string $url, array $options = []) use ($world): IResponse {
				$this->fetched[] = $url;
				$this->options[] = $options;
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
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use ($world, $catalogs): array {
				$this->searched[] = self::passedArguments(args: $args);
				return match ($world['search'] ?? 'ok') {
					'throws' => throw new \RuntimeException('search down'),
					'no-results' => ['total' => 0],
					default => ['results' => $catalogs],
				};
			}
		);
		$configuration = match ($world['settings'] ?? 'ok') {
			'no-register' => ['catalog_schema' => '5'],
			'no-schema' => ['catalog_register' => '1', 'catalog_schema' => ''],
			default => ['catalog_register' => '1', 'catalog_schema' => '5'],
		};
		$settings = $this->createMock(SettingsService::class);
		if (($world['settings'] ?? 'ok') === 'throws') {
			$settings->method('getSettings')->willThrowException(new \RuntimeException('settings down'));
		} else {
			$settings->method('getSettings')->willReturn(['configuration' => $configuration]);
		}

		if (($world['objects'] ?? 'ok') === 'throws') {
			$settings->method('getObjectService')->willThrowException(new \RuntimeException('no OpenRegister'));
		} else {
			$settings->method('getObjectService')->willReturn($objects);
		}

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

		return new WooReadinessService($clients, $directory, new SitemapStandards($sitemaps, $versions), $settings, $config, $urls);
	}//end service()

	/**
	 * What the world answers for a URL.
	 *
	 * @param string $url The URL.
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
		} elseif (str_contains($url, 'page=') === true) {
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
		} elseif (str_contains($url, '/sitemaps/') === true) {
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
		} elseif (str_contains($url, 'doc-') === true) {
			$status = ($world['document'] ?? 200);
			$body = 'pdf';
		} else {
			$status = 404;
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		if (($world['robots'] ?? null) === 'stream' && str_ends_with($url, '/robots.txt') === true) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, "Sitemap: x/sitemaps/y\n");
			rewind($stream);
			$body = $stream;
		}

		$response->method('getBody')->willReturn($body);

		return $response;
	}//end answer()

	/**
	 * Every fetch has the 10 second timeout, no exception on an error status, and follows no redirect.
	 *
	 * A followed redirect would reach an address the SSRF guard never saw.
	 *
	 * @return void
	 */
	public function testEveryFetchHasTheTimeoutAndFollowsNoRedirect(): void {
		$this->service(world: [])->runCheck();

		$this->assertNotEmpty($this->options);
		foreach ($this->options as $options) {
			$this->assertSame(['timeout' => 10, 'http_errors' => false, 'allow_redirects' => false], $options);
		}

	}//end testEveryFetchHasTheTimeoutAndFollowsNoRedirect()

	/**
	 * The arguments of a catalog search by parameter name, without those left at their default.
	 *
	 * Named, so the record follows what the service passed, not the loaded ObjectService's signature.
	 *
	 * @param array<int, mixed> $args The arguments the mock received.
	 *
	 * @return array<string, mixed> The passed arguments by name.
	 */
	private static function passedArguments(array $args): array {
		$named = [];
		$parameters = (new \ReflectionMethod(\OCA\OpenRegister\Service\ObjectService::class, 'searchObjectsPaginated'))->getParameters();
		foreach ($parameters as $position => $parameter) {
			$passed = array_key_exists($position, $args);
			if ($passed === true && ($parameter->isDefaultValueAvailable() === false || $args[$position] !== $parameter->getDefaultValue())) {
				$named[$parameter->getName()] = $args[$position];
			}
		}

		return $named;
	}//end passedArguments()
}//end class
