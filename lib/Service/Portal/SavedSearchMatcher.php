<?php

/**
 * OpenCatalogi Saved Search Matcher.
 *
 * Finds, for every due saved search, the publications that became public
 * since its last run, and hands the notice to portaliq (hydra
 * `woo-citizen-journey`, C2 and C3).
 *
 * - Due: `immediate` every run, `daily` once after 07:00, `weekly` on Monday
 *   after 07:00, judged in the time zone of the moment passed in.
 * - A match is a `publication` from the anonymous public search whose
 *   `publicationDate` lies after `lastRunAt` and at or before now, and that is
 *   public now.
 * - The notice is a message in the resident's portal inbox, written by
 *   SavedSearchNoticeWriter with the rule key `opencatalogi.savedSearch.matched`,
 *   which the portal manifest declares, so portaliq also sends the e-mail by
 *   the resident's preferences. The save after it records `lastNotifiedAt`,
 *   and `lastMatches` holds what was found. That save is bookkeeping: no
 *   change rule listens to it, so it tells the resident nothing.
 * - `lastRunAt` moves only in the save after the last message (or alone when
 *   there was nothing), so a failure sends late, never not at all.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\SavedSearchMatcherInterface;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Matches saved searches and hands their notices to portaliq.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */
class SavedSearchMatcher implements SavedSearchMatcherInterface {

	/**
	 * The most saved searches read per run.
	 */
	private const READ_LIMIT = 1000;

	/**
	 * The most due saved searches handled per run.
	 */
	private const DUE_LIMIT = 200;

	/**
	 * The most publications in one digest.
	 */
	private const DIGEST_SIZE = 20;

	/**
	 * The hour after which daily and weekly searches are due.
	 */
	private const DIGEST_HOUR = 7;

	/**
	 * The last `lastNotifiedAt` written, so two notices never share one.
	 *
	 * @var string
	 */
	private string $lastStamp = '';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore $store Reads and writes as the system.
	 * @param PublicSearchRunner $search The anonymous public search.
	 * @param PublicationLinker $linker The public link to a publication.
	 * @param PublicationQueryService $publications The public-ness rule.
	 * @param SavedSearchNoticeWriter $notices Writes the message to the resident.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly PublicSearchRunner $search,
		private readonly PublicationLinker $linker,
		private readonly PublicationQueryService $publications,
		private readonly SavedSearchNoticeWriter $notices,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle every due saved search.
	 *
	 * @param DateTimeImmutable $now The moment of the run, in the instance's time zone.
	 *
	 * @return array{handled: int, notices: int, baselined: int, failed: int}
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function run(DateTimeImmutable $now): array {
		$run = new SavedSearchMatchRun();
		$run->now = $now;
		$run->nowUtc = $this->utc(moment: $now);

		foreach ($this->store->findWhere(schema: SavedSearchService::SCHEMA, filters: [], limit: self::READ_LIMIT) as $saved) {
			if ($this->isActive(saved: $saved) === false) {
				continue;
			}

			if ($run->handled >= self::DUE_LIMIT) {
				break;
			}

			$this->handleSafely(run: $run, saved: $saved);
		}

		return $run->stats();
	}//end run()

	/**
	 * Whether a stored saved search is switched on and has an id.
	 *
	 * @param array<string, mixed> $saved The saved search.
	 *
	 * @return bool
	 */
	private function isActive(array $saved): bool {
		return ($saved['active'] ?? true) !== false && is_string($saved['id'] ?? null) === true;
	}//end isActive()

	/**
	 * Handle one saved search; a failure is counted and logged, and the next
	 * run tries again.
	 *
	 * @param SavedSearchMatchRun $run The run.
	 * @param array<string, mixed> $saved The saved search.
	 *
	 * @return void
	 */
	private function handleSafely(SavedSearchMatchRun $run, array $saved): void {
		try {
			$this->handle(run: $run, saved: $saved);
		} catch (Throwable $e) {
			$run->failed++;
			$this->logger->warning(
				'OpenCatalogi: a saved search was not handled; the next run tries again',
				['savedSearch' => $saved['id'], 'reason' => $e->getMessage()]
			);
		}
	}//end handleSafely()

	/**
	 * Give a new saved search its first last run, or notify a due one.
	 *
	 * @param SavedSearchMatchRun $run The run.
	 * @param array<string, mixed> $saved The saved search.
	 *
	 * @return void
	 */
	private function handle(SavedSearchMatchRun $run, array $saved): void {
		$lastRunAt = $this->moment(value: ($saved['lastRunAt'] ?? null));
		if ($lastRunAt === null) {
			$saved['lastRunAt'] = $run->nowUtc;
			$this->store->save(schema: SavedSearchService::SCHEMA, data: $saved, id: $saved['id']);
			$run->baselined++;
			return;
		}

		if ($this->isDue(frequency: (string)($saved['frequency'] ?? 'daily'), lastRunAt: $lastRunAt, now: $run->now) === false) {
			return;
		}

		$run->handled++;
		$run->notices += $this->notify(saved: $saved, window: new SearchWindow(from: $lastRunAt, until: $run->now));
	}//end handle()

	/**
	 * Whether a saved search is due at this moment.
	 *
	 * @param string $frequency immediate, daily or weekly.
	 * @param DateTimeImmutable $lastRunAt Its last run.
	 * @param DateTimeImmutable $now The moment of the run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function isDue(string $frequency, DateTimeImmutable $lastRunAt, DateTimeImmutable $now): bool {
		if ($frequency === 'immediate') {
			return true;
		}

		$moment = $now->setTime(self::DIGEST_HOUR, 0);
		if ($frequency === 'weekly') {
			$moment = $now->modify('monday this week')->setTime(self::DIGEST_HOUR, 0);
		}

		return $now >= $moment && $lastRunAt < $moment;
	}//end isDue()

	/**
	 * The publications that became public in the window and fit the query,
	 * oldest first.
	 *
	 * @param array<string, mixed> $saved The saved search.
	 * @param SearchWindow $window The run window.
	 *
	 * @return array<int, array{publication: string, title: string, url: string, publicationDate: string}>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	private function matches(array $saved, SearchWindow $window): array {
		$params = $window->searchParams(query: (array)($saved['query'] ?? []));
		$window = $this->narrowedToPeriod(window: $window, filters: (array)($saved['query']['filters'] ?? []));

		$found = [];
		foreach ($this->search->search(params: $params) as $row) {
			if ($this->isNewPublicMatch(row: $row, window: $window) === true) {
				$found[$this->rowId(row: $row)] = $this->noticeEntry(row: $row);
			}
		}

		$found = array_values($found);
		usort($found, static fn (array $a, array $b): int => strcmp($a['publicationDate'], $b['publicationDate']));
		return $found;
	}//end matches()

	/**
	 * The run window narrowed by the saved period, its end day included whole.
	 *
	 * @param SearchWindow $window The run window.
	 * @param array<string, mixed> $filters The saved query's filters.
	 *
	 * @return SearchWindow The narrowed window.
	 */
	private function narrowedToPeriod(SearchWindow $window, array $filters): SearchWindow {
		return $window->narrowedTo(
			periodFrom: $this->moment(value: ($filters['periodFrom'] ?? null)),
			periodTo: $this->moment(value: ($filters['periodTo'] ?? null))?->setTime(23, 59, 59)
		);
	}//end narrowedToPeriod()

	/**
	 * Whether a search row is a publication with an id, dated in the window,
	 * and public.
	 *
	 * @param array<string, mixed> $row The search row.
	 * @param SearchWindow $window The narrowed window.
	 *
	 * @return bool
	 */
	private function isNewPublicMatch(array $row, SearchWindow $window): bool {
		$self = (array)($row['@self'] ?? []);
		if ($this->rowId(row: $row) === '' || ($self['schema'] ?? null) !== PortalObjectStore::PUBLICATION_SCHEMA) {
			return false;
		}

		return $window->contains(date: $this->moment(value: ($row['publicationDate'] ?? null))) === true
			&& $this->publications->isObjectPublic(objectData: $row) === true;
	}//end isNewPublicMatch()

	/**
	 * A search row's publication id: its `@self` id, else its own id.
	 *
	 * @param array<string, mixed> $row The search row.
	 *
	 * @return string The id, or ''.
	 */
	private function rowId(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return (string)($self['id'] ?? ($row['id'] ?? ''));
	}//end rowId()

	/**
	 * One matched publication as a notice lists it.
	 *
	 * @param array<string, mixed> $row The matched search row.
	 *
	 * @return array{publication: string, title: string, url: string, publicationDate: string}
	 */
	private function noticeEntry(array $row): array {
		$id = $this->rowId(row: $row);
		return [
			'publication' => $id,
			'title' => mb_substr((string)($row['title'] ?? ''), 0, 500),
			'url' => $this->linker->url(id: $id),
			'publicationDate' => $this->utc(moment: $this->moment(value: ($row['publicationDate'] ?? null))),
		];
	}//end noticeEntry()

	/**
	 * Match a saved search in its window, write each notice's message, then
	 * record it on the saved search, and move `lastRunAt` in the save after
	 * the last message.
	 *
	 * @param array<string, mixed> $saved The saved search.
	 * @param SearchWindow $window The run window.
	 *
	 * @return int How many notices were handed over.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	private function notify(array $saved, SearchWindow $window): int {
		$id = (string)$saved['id'];
		$until = $this->utc(moment: $window->until);
		$matches = $this->matches(saved: $saved, window: $window);
		if ($matches === []) {
			$saved['lastRunAt'] = $until;
			$this->store->save(schema: SavedSearchService::SCHEMA, data: $saved, id: $id);
			return 0;
		}

		$batches = [array_slice($matches, 0, self::DIGEST_SIZE)];
		$counts = [count($matches)];
		if (($saved['frequency'] ?? '') === 'immediate') {
			$batches = array_map(static fn (array $match): array => [$match], $matches);
			$counts = array_fill(0, count($matches), 1);
		}

		$last = (count($batches) - 1);
		foreach ($batches as $index => $batch) {
			// The message first: when it fails, nothing below moves, and the
			// next run finds the same matches.
			$this->notices->write(saved: $saved, batch: $batch, count: $counts[$index]);
			$saved['lastMatches'] = $batch;
			$saved['matchCount'] = $counts[$index];
			$saved['lastNotifiedAt'] = $this->stamp();
			if ($index === $last) {
				$saved['lastRunAt'] = $until;
			}

			$saved = $this->store->save(schema: SavedSearchService::SCHEMA, data: $saved, id: $id);
		}

		return count($batches);
	}//end notify()

	/**
	 * A fresh `lastNotifiedAt`, with microseconds, never equal to the one before.
	 *
	 * @return string
	 */
	private function stamp(): string {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$stamp = $moment->format('Y-m-d\TH:i:s.uP');
		while ($stamp <= $this->lastStamp) {
			$moment = $moment->modify('+1 microsecond');
			$stamp = $moment->format('Y-m-d\TH:i:s.uP');
		}

		$this->lastStamp = $stamp;
		return $stamp;
	}//end stamp()

	/**
	 * A moment as ISO 8601 in UTC.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return string
	 */
	private function utc(DateTimeImmutable $moment): string {
		return $moment->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
	}//end utc()

	/**
	 * A stored date or date-time as a moment, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end moment()
}//end class
