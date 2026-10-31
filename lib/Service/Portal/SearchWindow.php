<?php

/**
 * OpenCatalogi Search Window.
 *
 * The span a saved search looks at in one run: after its last run, up to
 * and including the moment of this run, narrowed by the saved period.
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
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\SearchQueryTranslator;

/**
 * The run window of one saved search.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
 */
final class SearchWindow {

	/**
	 * The most rows asked of one search.
	 */
	private const SEARCH_LIMIT = 100;

	/**
	 * The start of the saved period (inclusive), if any.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $periodFrom = null;

	/**
	 * The end of the saved period (inclusive), if any.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $periodTo = null;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $from  The last run (exclusive).
	 * @param DateTimeImmutable $until The moment of the run (inclusive).
	 */
	public function __construct(
		public readonly DateTimeImmutable $from,
		public readonly DateTimeImmutable $until,
	) {

	}//end __construct()

	/**
	 * The public search parameters of a saved query in this window, oldest
	 * first. The window replaces the saved period's range bounds.
	 *
	 * @param array<string, mixed> $query The saved query.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchQueryTranslator is a pure function over the query.
	 */
	public function searchParams(array $query): array {
		$params = SearchQueryTranslator::fromSavedQuery(query: $query);
		$range = (array)($params['publicationDate'] ?? []);
		unset($range['gte'], $range['lte']);
		$params['publicationDate'] = array_merge($range, ['gt' => $this->utc(moment: $this->from), 'lte' => $this->utc(moment: $this->until)]);
		$params['_limit'] = self::SEARCH_LIMIT;
		$params['_order'] = ['publicationDate' => 'asc'];
		return $params;

	}//end searchParams()

	/**
	 * This window, narrowed to a saved period.
	 *
	 * @param DateTimeImmutable|null $periodFrom The start of the period (inclusive), or null.
	 * @param DateTimeImmutable|null $periodTo   The end of the period (inclusive), or null.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function narrowedTo(?DateTimeImmutable $periodFrom, ?DateTimeImmutable $periodTo): self {
		$window = clone $this;
		$window->periodFrom = $periodFrom;
		$window->periodTo = $periodTo;
		return $window;

	}//end narrowedTo()

	/**
	 * Whether a publication date lies in the window and the saved period.
	 *
	 * @param DateTimeImmutable|null $date The publication date.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function contains(?DateTimeImmutable $date): bool {
		if ($date === null || $date <= $this->from || $date > $this->until) {
			return false;
		}

		if ($this->periodFrom !== null && $date < $this->periodFrom) {
			return false;
		}

		return $this->periodTo === null || $date <= $this->periodTo;

	}//end contains()

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
}//end class
