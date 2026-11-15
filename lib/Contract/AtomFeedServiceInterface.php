<?php

/**
 * Contract of AtomFeedService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use DateTimeInterface;

/**
 * Contract of AtomFeedService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface AtomFeedServiceInterface {
	/**
	 * The entries a catalogue's feed carries.
	 *
	 * The publication rules decide which records appear and which of their
	 * properties do, per entry. A record with no rule is absent: a record
	 * nobody decided to publish is not published.
	 *
	 * @param array<int, array<string, mixed>> $records The catalogue's records.
	 * @param array<int, array<string, mixed>> $notices The catalogue's current notices.
	 * @param array<int, array<string, mixed>> $rules The publication rules.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function entries(array $records, array $notices, array $rules, ?DateTimeInterface $now = null): array;

	/**
	 * The feed as Atom.
	 *
	 * Everything that came from an entry is escaped. The feed is assembled from
	 * projections that already dropped every withheld property, so the escaping
	 * is about markup and not about access.
	 *
	 * @param string $catalogTitle The catalogue's title.
	 * @param string $selfUrl The feed's own URL.
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return string The Atom document.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function toAtom(string $catalogTitle, string $selfUrl, array $entries, ?DateTimeInterface $now = null): string;
}//end interface
