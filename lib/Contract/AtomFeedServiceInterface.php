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
	 * The records are what an anonymous reader may read inside the catalogue;
	 * the read rules were applied before they reached this method.
	 *
	 * @param array<int, mixed> $records The records an anonymous reader may read.
	 * @param array<int, mixed> $notices The catalogue's notices.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function entries(array $records, array $notices, ?DateTimeInterface $now = null): array;

	/**
	 * The notices posted on the boards of one catalogue.
	 *
	 * @param string $catalogId The catalogue's id.
	 * @param array<int, mixed> $boards Every notice board.
	 * @param array<int, mixed> $notices Every notice.
	 *
	 * @return array<int, array<string, mixed>> The catalogue's notices.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function noticesOfCatalogue(string $catalogId, array $boards, array $notices): array;

	/**
	 * The feed as Atom.
	 *
	 * Everything that came from an entry is escaped. The feed is assembled from
	 * records an anonymous reader may already read, so the escaping is about
	 * markup and not about access.
	 *
	 * @param string $catalogTitle The catalogue's title.
	 * @param string $selfUrl The feed's own URL.
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return string The Atom document.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function toAtom(string $catalogTitle, string $selfUrl, array $entries, ?DateTimeInterface $now = null): string;
}//end interface
