<?php

/**
 * Tests for the daily reconciliation of the DiWoo sitemaps against the register.
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

use OCA\OpenCatalogi\BackgroundJob\DiwooSitemapReconciliation;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The register side and the rendered pages are doubles of SitemapService; the pages
 * are real XMLResponse documents, read back the way the job reads them.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-sitemaps-are-reconciled-against-the-register-daily-req-wno-003
 */
#[CoversClass(DiwooSitemapReconciliation::class)]
#[UsesClass(XMLResponse::class)]
class DiwooSitemapReconciliationTest extends TestCase {

	private const CATEGORY = 'sitemapindex-diwoo-infocat012.xml';

	/**
	 * The values the job stored, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The incidents the job raised, as [kind, subject, message].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $raised = [];

	/**
	 * A sitemap index naming the given pages.
	 *
	 * @param list<int> $pages The page numbers.
	 *
	 * @return XMLResponse
	 */
	private static function index(array $pages): XMLResponse {
		$index = ['@root' => 'sitemapindex', '@attributes' => ['xmlns' => StandardsVersionService::SITEMAP_NAMESPACE]];
		foreach ($pages as $page) {
			$index['sitemap'][] = ['loc' => 'https://example.com/apps/opencatalogi/api/woo/sitemaps/' . self::CATEGORY . '?page=' . $page, 'lastmod' => '2026-11-01'];
		}

		return new XMLResponse(data: $index);
	}

	/**
	 * A sitemap page listing the given document locations.
	 *
	 * @param list<string> $locs The locations.
	 *
	 * @return XMLResponse
	 */
	private static function page(array $locs): XMLResponse {
		$urlset = ['@root' => 'urlset', '@attributes' => ['xmlns' => StandardsVersionService::SITEMAP_NAMESPACE, 'xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE]];
		foreach ($locs as $loc) {
			$urlset['url'][] = ['loc' => $loc, 'lastmod' => '2026-11-01', 'diwoo:Document' => ['diwoo:DiWoo' => []]];
		}

		return new XMLResponse(data: $urlset);
	}

	/**
	 * A job over one catalogue `woo` with one category.
	 *
	 * @param array|\Throwable  $register The register's answer, or what it throws.
	 * @param XMLResponse       $index    The sitemap index.
	 * @param array<int, XMLResponse> $pages The pages by number.
	 *
	 * @return DiwooSitemapReconciliation
	 */
	private function job(array|\Throwable $register, XMLResponse $index, array $pages = []): DiwooSitemapReconciliation {
		$readiness = $this->createMock(WooReadinessService::class);
		$readiness->method('getWooEnabledCatalogs')->willReturn([['slug' => 'woo'], ['slug' => '']]);

		$sitemaps = $this->createMock(SitemapService::class);
		$sitemaps->method('sitemapFiles')->willReturn([self::CATEGORY => 'Jaarplannen en jaarverslagen']);
		$expectation = $sitemaps->method('publicDocumentLocations')->with('woo', self::CATEGORY);
		if ($register instanceof \Throwable) {
			$expectation->willThrowException($register);
		} else {
			$expectation->willReturn($register);
		}

		$sitemaps->method('buildSitemapIndex')->with('woo', self::CATEGORY)->willReturn($index);
		$sitemaps->method('buildSitemap')->willReturnCallback(
			fn (string $slug, string $category, int $page): XMLResponse => ($pages[$page] ?? new XMLResponse(data: 'no such page', status: 404))
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1763337600);

		$incidents = $this->createMock(PipelineIncidents::class);
		$incidents->method('raise')->willReturnCallback(
			function (string $kind, string $subject, string $message): void {
				$this->raised[] = [$kind, $subject, $message];
			}
		);

		return new DiwooSitemapReconciliation($time, $readiness, $sitemaps, $config, $incidents, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Run the job and return the stored results.
	 *
	 * @param DiwooSitemapReconciliation $job The job.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function runJob(DiwooSitemapReconciliation $job): array {
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		$this->assertArrayHasKey('opencatalogi/' . DiwooSitemapReconciliation::CONFIG_KEY, $this->stored);

		return json_decode($this->stored['opencatalogi/' . DiwooSitemapReconciliation::CONFIG_KEY], true);
	}

	public function testADocumentMissingFromTheSitemapIsReported(): void {
		$register = ['publications' => 2, 'locations' => ['https://example.com/a.pdf', 'https://example.com/b.pdf', 'https://example.com/c.pdf']];
		$job = $this->job($register, self::index([1, 2]), [
			1 => self::page(['https://example.com/a.pdf']),
			2 => self::page(['https://example.com/c.pdf']),
		]);

		$results = $this->runJob($job);

		$this->assertCount(1, $results);
		$this->assertSame('woo', $results[0]['catalog']);
		$this->assertSame(self::CATEGORY, $results[0]['category']);
		$this->assertSame(3, $results[0]['expected']);
		$this->assertSame(2, $results[0]['listed']);
		$this->assertSame(['https://example.com/b.pdf'], $results[0]['missing']);
		$this->assertSame([], $results[0]['extra']);
		$this->assertTrue($results[0]['complete']);
		$this->assertSame(1763337600, $results[0]['checkedAt']);
		$this->assertSame(
			[[DiwooSitemapReconciliation::INCIDENT_KIND, 'woo/' . self::CATEGORY, '1 public document(s) missing from the sitemap, 0 listed that the register does not hold publicly']],
			$this->raised
		);
	}

	public function testAnExtraEntryIsReported(): void {
		$register = ['publications' => 1, 'locations' => ['https://example.com/a.pdf']];
		$job = $this->job($register, self::index([1]), [1 => self::page(['https://example.com/a.pdf', 'https://example.com/ingetrokken.pdf'])]);

		$results = $this->runJob($job);

		$this->assertSame([], $results[0]['missing']);
		$this->assertSame(['https://example.com/ingetrokken.pdf'], $results[0]['extra']);
		$this->assertCount(1, $this->raised);
		$this->assertSame(DiwooSitemapReconciliation::INCIDENT_KIND, $this->raised[0][0]);
	}

	public function testAMatchingSitemapRaisesNothing(): void {
		$register = ['publications' => 1, 'locations' => ['https://example.com/a.pdf']];
		$results = $this->runJob($this->job($register, self::index([1]), [1 => self::page(['https://example.com/a.pdf'])]));

		$this->assertTrue($results[0]['complete']);
		$this->assertSame([], $results[0]['missing']);
		$this->assertSame([], $results[0]['extra']);
		$this->assertSame([], $this->raised);
	}

	public function testAFailedReadIsIncomplete(): void {
		$register = ['publications' => 1, 'locations' => ['https://example.com/a.pdf']];
		// The index names page 2, which cannot be built.
		$results = $this->runJob($this->job($register, self::index([1, 2]), [1 => self::page(['https://example.com/a.pdf'])]));

		$this->assertFalse($results[0]['complete']);
		$this->assertSame('sitemap page 2 answered HTTP 404', $results[0]['reason']);
		$this->assertSame([], $results[0]['missing']);
		$this->assertSame([], $this->raised);
	}

	public function testAnUnreadableRegisterIsIncomplete(): void {
		$results = $this->runJob($this->job(new \RuntimeException('OpenRegister is not available'), self::index([])));

		$this->assertFalse($results[0]['complete']);
		$this->assertSame('OpenRegister is not available', $results[0]['reason']);
	}

	public function testARefusedIndexIsIncomplete(): void {
		$register = ['publications' => 0, 'locations' => []];
		$results = $this->runJob($this->job($register, new XMLResponse(data: 'Invalid category code', status: 400)));

		$this->assertFalse($results[0]['complete']);
		$this->assertSame('the sitemap index answered HTTP 400', $results[0]['reason']);
	}

	public function testTheLastResultsAreReadBack(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnOnConsecutiveCalls('', '[{"catalog":"woo","complete":true}]');

		$this->assertNull(DiwooSitemapReconciliation::lastResults($config));
		$this->assertSame([['catalog' => 'woo', 'complete' => true]], DiwooSitemapReconciliation::lastResults($config));
	}

	public function testItRunsDailyAndNotInParallel(): void {
		$job = $this->job(['publications' => 0, 'locations' => []], self::index([]));

		$this->assertSame(86400, (new \ReflectionProperty($job, 'interval'))->getValue($job));
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>' . DiwooSitemapReconciliation::class . '</job>', $info);
	}
}
