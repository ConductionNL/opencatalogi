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
 * - The notice is a save that changes `lastNotifiedAt`. opencatalogi's portal
 *   manifest declares the change rule `opencatalogi.savedSearch.matched` on
 *   that field, so portaliq writes the inbox message and sends email and push
 *   by the resident's preferences. `lastMatches` holds what was found.
 * - `lastRunAt` moves only in the save of the last notice (or alone when there
 *   was nothing), so a failure sends late, never not at all.
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
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SearchQueryTranslator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Matches saved searches and hands their notices to portaliq.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */
class SavedSearchMatcher {

	/**
	 * The most saved searches read per run.
	 */
	private const READ_LIMIT = 1000;

	/**
	 * The most due saved searches handled per run.
	 */
	private const DUE_LIMIT = 200;

	/**
	 * The most rows asked of one search.
	 */
	private const SEARCH_LIMIT = 100;

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
	 * @param PortalObjectStore       $store        Reads and writes as the system.
	 * @param PublicSearchRunner      $search       The anonymous public search.
	 * @param PublicationLinker       $linker       The public link to a publication.
	 * @param PublicationQueryService $publications The public-ness rule.
	 * @param LoggerInterface         $logger       The logger.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly PublicSearchRunner $search,
		private readonly PublicationLinker $linker,
		private readonly PublicationQueryService $publications,
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
		$stats = ['handled' => 0, 'notices' => 0, 'baselined' => 0, 'failed' => 0];
		$nowUtc = $this->utc(moment: $now);

		foreach ($this->store->findWhere(schema: SavedSearchService::SCHEMA, filters: [], limit: self::READ_LIMIT) as $saved) {
			if (($saved['active'] ?? true) === false || is_string($saved['id'] ?? null) === false) {
				continue;
			}

			if ($stats['handled'] >= self::DUE_LIMIT) {
				break;
			}

			try {
				$lastRunAt = $this->moment(value: ($saved['lastRunAt'] ?? null));
				if ($lastRunAt === null) {
					$saved['lastRunAt'] = $nowUtc;
					$this->store->save(schema: SavedSearchService::SCHEMA, data: $saved, id: $saved['id']);
					$stats['baselined']++;
					continue;
				}

				if ($this->isDue(frequency: (string)($saved['frequency'] ?? 'daily'), lastRunAt: $lastRunAt, now: $now) === false) {
					continue;
				}

				$stats['handled']++;
				$matches = $this->matches(saved: $saved, from: $lastRunAt, until: $now);
				$stats['notices'] += $this->notify(saved: $saved, matches: $matches, until: $nowUtc);
			} catch (Throwable $e) {
				$stats['failed']++;
				$this->logger->warning('OpenCatalogi: a saved search was not handled; the next run tries again', ['savedSearch' => $saved['id'], 'reason' => $e->getMessage()]);
			}//end try
		}//end foreach

		return $stats;

	}//end run()

	/**
	 * Whether a saved search is due at this moment.
	 *
	 * @param string            $frequency immediate, daily or weekly.
	 * @param DateTimeImmutable $lastRunAt Its last run.
	 * @param DateTimeImmutable $now       The moment of the run.
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
	 * @param DateTimeImmutable    $from  The last run (exclusive).
	 * @param DateTimeImmutable    $until The moment of the run (inclusive).
	 *
	 * @return array<int, array{publication: string, title: string, url: string, publicationDate: string}>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchQueryTranslator is a pure function over the query.
	 */
	private function matches(array $saved, DateTimeImmutable $from, DateTimeImmutable $until): array {
		$params = SearchQueryTranslator::fromSavedQuery(query: (array)($saved['query'] ?? []));
		$range = (array)($params['publicationDate'] ?? []);
		unset($range['gte'], $range['lte']);
		$params['publicationDate'] = array_merge($range, ['gt' => $this->utc(moment: $from), 'lte' => $this->utc(moment: $until)]);
		$params['_limit'] = self::SEARCH_LIMIT;
		$params['_order'] = ['publicationDate' => 'asc'];

		$filters = (array)($saved['query']['filters'] ?? []);
		$periodFrom = $this->moment(value: ($filters['periodFrom'] ?? null));
		$periodTo = $this->moment(value: ($filters['periodTo'] ?? null));

		$found = [];
		foreach ($this->search->search(params: $params) as $row) {
			$self = (array)($row['@self'] ?? []);
			$id = (string)($self['id'] ?? ($row['id'] ?? ''));
			$date = $this->moment(value: ($row['publicationDate'] ?? null));
			if ($id === '' || ($self['schema'] ?? null) !== PortalObjectStore::PUBLICATION_SCHEMA || $date === null
				|| $date <= $from || $date > $until
				|| ($periodFrom !== null && $date < $periodFrom) || ($periodTo !== null && $date > $periodTo->setTime(23, 59, 59))
				|| $this->publications->isObjectPublic(objectData: $row) === false
			) {
				continue;
			}

			$found[$id] = [
				'publication' => $id,
				'title' => mb_substr((string)($row['title'] ?? ''), 0, 500),
				'url' => $this->linker->url(id: $id),
				'publicationDate' => $this->utc(moment: $date),
			];
		}//end foreach

		$found = array_values($found);
		usort($found, static fn (array $a, array $b): int => strcmp($a['publicationDate'], $b['publicationDate']));
		return $found;

	}//end matches()

	/**
	 * Save the notices, and move `lastRunAt` in the last save.
	 *
	 * @param array<string, mixed>             $saved   The saved search.
	 * @param array<int, array<string, mixed>> $matches The matches.
	 * @param string                           $until   The end of the window, UTC.
	 *
	 * @return int How many notices were handed over.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	private function notify(array $saved, array $matches, string $until): int {
		$id = (string)$saved['id'];
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
		return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);

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
