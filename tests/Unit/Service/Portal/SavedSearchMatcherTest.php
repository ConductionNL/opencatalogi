<?php

/**
 * Tests for matching saved searches and handing notices to portaliq.
 *
 * Every save the matcher makes is validated against the shipped savedSearch
 * schema, so it can never write what the register refuses.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

require_once __DIR__.'/FakePortalObjectStore.php';

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Portal\PublicSearchRunner;
use OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SavedSearchMatcher.
 */
class SavedSearchMatcherTest extends TestCase {

	private FakePortalObjectStore $store;

	/**
	 * Publications the fake search knows, as result rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The parameters of every search asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	private SavedSearchMatcher $matcher;

	private DateTimeZone $zone;

	protected function setUp(): void {
		$this->zone = new DateTimeZone('Europe/Amsterdam');
		$this->store = new FakePortalObjectStore();

		$search = $this->createMock(PublicSearchRunner::class);
		$search->method('search')->willReturnCallback(function (array $params): array {
			$this->asked[] = $params;
			return $this->rows;
		});
		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/'.$id);

		$this->matcher = new SavedSearchMatcher(
			store: $this->store,
			search: $search,
			linker: $linker,
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			logger: new NullLogger()
		);
	}

	/**
	 * A moment in Amsterdam time.
	 *
	 * @param string $when The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, $this->zone);
	}

	/**
	 * A saved search in the store.
	 *
	 * @param string      $id        The id.
	 * @param string      $frequency The frequency.
	 * @param string|null $lastRunAt The last run.
	 * @param bool        $active    Whether it is active.
	 *
	 * @return void
	 */
	private function saved(string $id, string $frequency, ?string $lastRunAt, bool $active=true): void {
		$this->store->objects['savedSearch'][$id] = array_filter([
			'id' => $id,
			'title' => 'Windpark',
			'owner' => 'subject-1',
			'query' => ['text' => 'windpark', 'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => [], 'periodFrom' => '', 'periodTo' => ''], 'catalog' => ''],
			'frequency' => $frequency,
			'active' => $active,
			'lastRunAt' => $lastRunAt,
		], static fn ($value): bool => $value !== null);
	}

	/**
	 * A public publication row as the search returns it.
	 *
	 * @param string $id   The id.
	 * @param string $date The publication date.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $id, string $date): array {
		return ['title' => 'Publicatie '.$id, 'publicationDate' => $date, '@self' => ['id' => $id, 'schema' => 'publication']];
	}

	/**
	 * Every save of a saved search passes the shipped schema.
	 *
	 * @return void
	 */
	private function assertSavesAreValid(): void {
		$root = dirname(__DIR__, 4);
		$fragment = json_decode((string)file_get_contents($root.'/lib/Settings/register.d/saved-searches-and-alerts.json'), true)['components']['schemas']['savedSearch'];
		$schema = (string)json_encode(['type' => 'object', 'properties' => $fragment['properties'], 'required' => $fragment['required']]);
		foreach ($this->store->saves as $save) {
			$data = $save['data'];
			unset($data['id']);
			$this->assertTrue((new Validator())->validate(json_decode((string)json_encode($data)), $schema)->isValid(), json_encode($data));
		}
	}

	/**
	 * The saves that were notices (a changed lastNotifiedAt).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function notices(): array {
		$notices = [];
		$previous = [];
		foreach ($this->store->saves as $save) {
			$id = $save['data']['id'];
			$stamp = ($save['data']['lastNotifiedAt'] ?? null);
			if ($stamp !== null && $stamp !== ($previous[$id] ?? null)) {
				$notices[] = $save['data'];
			}

			$previous[$id] = $stamp;
		}

		return $notices;
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function testADailyDigestListsTheNewPublicationsOnce(): void {
		$this->saved('s1', 'daily', '2026-09-29T07:05:00+02:00');
		$this->rows = [$this->row('p1', '2026-09-29T09:00:00+00:00'), $this->row('p2', '2026-09-29T12:00:00+00:00'), $this->row('p3', '2026-09-30T04:00:00+00:00')];

		$stats = $this->matcher->run(now: $this->at('2026-09-30 07:10'));

		$notices = $this->notices();
		$this->assertCount(1, $notices);
		$this->assertSame(['p1', 'p2', 'p3'], array_column($notices[0]['lastMatches'], 'publication'));
		$this->assertSame('https://example.org/p/p1', $notices[0]['lastMatches'][0]['url']);
		$this->assertSame(3, $notices[0]['matchCount']);
		$this->assertSame('2026-09-30T05:10:00+00:00', $this->store->objects['savedSearch']['s1']['lastRunAt']);
		$this->assertSame(1, $stats['notices']);
		$this->assertSavesAreValid();
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function testTheSearchAsksOnlyForTheWindowThroughThePublicPath(): void {
		$this->saved('s1', 'immediate', '2026-09-30T06:00:00+00:00');
		$this->matcher->run(now: $this->at('2026-09-30 10:00'));

		$this->assertSame('windpark', $this->asked[0]['_search']);
		$this->assertSame(['infocat014'], $this->asked[0]['wooCategory']);
		$this->assertSame(['gt' => '2026-09-30T06:00:00+00:00', 'lte' => '2026-09-30T08:00:00+00:00'], $this->asked[0]['publicationDate']);
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function testAPublicationThatIsNotPublicYetOrOutsideTheWindowIsNotInAnyNotice(): void {
		$this->saved('s1', 'immediate', '2026-09-30T06:00:00+00:00');
		$future = (new DateTimeImmutable('+1 day'))->format(DATE_ATOM);
		$this->rows = [
			$this->row('future', $future),
			$this->row('old', '2026-09-01T06:00:00+00:00'),
			['title' => 'A listing', 'publicationDate' => '2026-09-30T07:00:00+00:00', '@self' => ['id' => 'l1', 'schema' => 'listing']],
		];

		$this->matcher->run(now: $this->at('2026-09-30 10:00'));
		$this->assertSame([], $this->notices());
		$this->assertSame('2026-09-30T08:00:00+00:00', $this->store->objects['savedSearch']['s1']['lastRunAt']);
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function testImmediateSendsOneNoticePerPublication(): void {
		$this->saved('s1', 'immediate', '2026-09-30T06:00:00+00:00');
		$this->rows = [$this->row('p1', '2026-09-30T07:00:00+00:00'), $this->row('p2', '2026-09-30T07:30:00+00:00')];

		$this->matcher->run(now: $this->at('2026-09-30 10:00'));

		$notices = $this->notices();
		$this->assertCount(2, $notices);
		$this->assertSame('p1', $notices[0]['lastMatches'][0]['publication']);
		$this->assertSame('p2', $notices[1]['lastMatches'][0]['publication']);
		$this->assertSame(1, $notices[1]['matchCount']);
		$this->assertSavesAreValid();
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function testDailyAndWeeklyWaitForTheirMoment(): void {
		$this->saved('daily-early', 'daily', '2026-09-29T07:05:00+02:00');
		$this->saved('daily-done', 'daily', '2026-09-30T07:02:00+02:00');
		$this->saved('weekly-tuesday', 'weekly', '2026-09-28T07:05:00+02:00');
		$this->saved('paused', 'immediate', '2026-09-30T06:00:00+00:00', false);
		$this->rows = [$this->row('p1', '2026-09-29T12:00:00+00:00')];

		// 06:50 on Wednesday 30 September: nothing daily is due yet, weekly waits for Monday.
		$this->matcher->run(now: $this->at('2026-09-30 06:50'));
		$this->assertSame([], $this->store->saves);

		// 07:10: only the daily search that did not run today yet.
		$this->matcher->run(now: $this->at('2026-09-30 07:10'));
		$this->assertSame(['daily-early'], array_values(array_unique(array_column(array_column($this->store->saves, 'data'), 'id'))));

		// Monday 5 October 07:30: the weekly one too.
		$this->store->saves = [];
		$this->matcher->run(now: $this->at('2026-10-05 07:30'));
		$ids = array_column(array_column($this->store->saves, 'data'), 'id');
		$this->assertContains('weekly-tuesday', $ids);
		$this->assertNotContains('paused', $ids);
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function testANewSavedSearchIsBaselinedWithoutANotice(): void {
		$this->saved('s1', 'immediate', null);
		$this->rows = [$this->row('p1', '2026-09-30T07:00:00+00:00')];

		$stats = $this->matcher->run(now: $this->at('2026-09-30 10:00'));
		$this->assertSame([], $this->notices());
		$this->assertSame('2026-09-30T08:00:00+00:00', $this->store->objects['savedSearch']['s1']['lastRunAt']);
		$this->assertSame(1, $stats['baselined']);
		$this->assertSame([], $this->asked);
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	public function testAFailedNoticeLeavesLastRunAtSoTheNextRunFindsTheSameMatches(): void {
		$this->saved('s1', 'daily', '2026-09-29T07:05:00+02:00');
		$this->rows = [$this->row('p1', '2026-09-29T12:00:00+00:00')];
		$this->store->failSaves['savedSearch'] = true;

		$stats = $this->matcher->run(now: $this->at('2026-09-30 07:10'));
		$this->assertSame('2026-09-29T07:05:00+02:00', $this->store->objects['savedSearch']['s1']['lastRunAt']);
		$this->assertSame(1, $stats['failed']);

		$this->store->failSaves['savedSearch'] = false;
		$this->matcher->run(now: $this->at('2026-09-30 07:25'));
		$this->assertCount(1, $this->notices());
		$this->assertSame('p1', $this->notices()[0]['lastMatches'][0]['publication']);
	}

	public function testADigestHoldsAtMostTwentyAndCountsAll(): void {
		$this->saved('s1', 'weekly', '2026-09-28T07:05:00+02:00');
		for ($i = 1; $i <= 25; $i++) {
			$this->rows[] = $this->row('p'.$i, sprintf('2026-09-29T%02d:%02d:00+00:00', ($i % 20), $i));
		}

		$this->matcher->run(now: $this->at('2026-10-05 08:00'));
		$notice = $this->notices()[0];
		$this->assertCount(20, $notice['lastMatches']);
		$this->assertSame(25, $notice['matchCount']);
		$this->assertSavesAreValid();
	}
}
