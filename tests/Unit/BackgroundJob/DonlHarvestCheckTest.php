<?php

/**
 * Tests for the daily data.overheid.nl harvest check.
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

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\DonlHarvestCheck;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Service\DonlRegistrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The job counts the guarded feed, asks DONL, and keeps the last count when DONL is unreachable.
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\DonlHarvestCheck
 * @uses   \OCA\OpenCatalogi\Service\DonlRegistrationService
 */
class DonlHarvestCheckTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A stored count of 118 from last week and an API call that times out keep 118 and its date.
	 *
	 * @return void
	 */
	public function testAnUnreachableApiKeepsTheLastCountAndSaysSo(): void {
		$this->stored = [
			'donl_api_url' => 'https://ckan.example/api/3',
			'donl_source' => 'https://example.org/api/dcat',
			'donl_dataset_count' => '118',
			'donl_dataset_count_at' => '2026-11-06T03:00:00+00:00',
		];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('cURL error 28: Operation timed out after 20000 milliseconds'));

		$job = $this->job(client: $client, pages: [['count' => 2, 'hasNext' => false]]);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame('118', $this->stored['donl_dataset_count']);
		$this->assertSame('2026-11-06T03:00:00+00:00', $this->stored['donl_dataset_count_at']);
		$this->assertStringContainsString('timed out', $this->stored['donl_last_error']);
		$this->assertSame('2', $this->stored['donl_feed_dataset_count']);
		$this->assertNotSame('', $this->stored['donl_last_check_at']);
	}//end testAnUnreachableApiKeepsTheLastCountAndSaysSo()

	/**
	 * The feed is counted over every page of every guarded DCAT catalogue.
	 *
	 * @return void
	 */
	public function testTheFeedIsCountedOverItsPages(): void {
		$job = $this->job(client: $this->createMock(IClient::class), pages: [['count' => 1000, 'hasNext' => true], ['count' => 20, 'hasNext' => false]]);

		$this->assertSame(1020, $job->feedDatasetCount());
	}//end testTheFeedIsCountedOverItsPages()

	/**
	 * The job is registered in appinfo/info.xml and runs daily.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		$this->assertContains(DonlHarvestCheck::class, $jobs);
		$this->assertSame(86400, $this->job(client: $this->createMock(IClient::class), pages: [])->getInterval());
	}//end testTheJobIsRegistered()

	/**
	 * The job over a stored config, a client and a feed whose one catalogue answers $pages.
	 *
	 * @param IClient $client The HTTP client.
	 * @param list<array<string, mixed>> $pages The `_meta` of each page.
	 *
	 * @return DonlHarvestCheck The job.
	 */
	private function job(IClient $client, array $pages): DonlHarvestCheck {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$dcat = $this->createMock(DcatServiceInterface::class);
		$dcat->method('getDcatEnabledCatalogs')->willReturn([['slug' => 'woo', 'schemas' => [1, 2]], ['title' => 'no slug']]);
		$dcat->method('buildCatalogDocument')->willReturnCallback(
			function (array $catalog, string $catalogSlug, int $page) use ($pages): array {
				$this->assertSame([1], $catalog['schemas'], 'The feed is counted after the read-rule guard.');
				return ['_meta' => ($pages[$page - 1] ?? ['count' => 0, 'hasNext' => false])];
			}
		);
		$guard = $this->createMock(PublicationQueryServiceInterface::class);
		$guard->method('applyCatalogReadRuleGuard')->willReturnCallback(static fn (array $catalog): array => ['schemas' => [1]] + $catalog);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		return new DonlHarvestCheck($time, new DonlRegistrationService($config, $clients), $dcat, $guard, $this->createMock(LoggerInterface::class));
	}//end job()
}//end class
