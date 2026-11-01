<?php

/**
 * Contract of NoticeBoardService, for callers in other components.
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
use DomainException;

/**
 * Contract of NoticeBoardService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface NoticeBoardServiceInterface {
	/**
	 * Check a board before it is saved.
	 *
	 * @param array<string, mixed> $board The board.
	 *
	 * @return array<string, mixed> The board, as it should be stored.
	 *
	 * @throws DomainException When comments are enabled and no moderator is named.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function validateBoard(array $board): array;

	/**
	 * Whether a reader is offered a comment form on this board.
	 *
	 * @param array<string, mixed> $board The board.
	 *
	 * @return boolean True only when comments are on and somebody moderates.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function commentsOffered(array $board): bool;

	/**
	 * Check a notice before it is saved.
	 *
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed> The notice, as it should be stored.
	 *
	 * @throws DomainException When it carries no board, title or readable period.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function validateNotice(array $notice): array;

	/**
	 * Whether a notice is inside its period.
	 *
	 * @param array<string, mixed> $notice The notice.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True while it should be shown.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function isCurrent(array $notice, ?DateTimeInterface $now = null): bool;

	/**
	 * Whether an entry belongs in the sitemap and the DiWoo feed.
	 *
	 * A notice does not. The check is here, as a function of the entry's own
	 * schema, so a caller that forgets the rule asks rather than assuming: a
	 * storingsmelding in the DiWoo feed is a notice published as if the Woo
	 * obliged us to hold it.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return boolean True when it may enter the sitemap.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function belongsInSitemap(array $entry): bool;

	/**
	 * A list of entries with every notice removed.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return array<int, array<string, mixed>> The entries that belong in the sitemap.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function excludeNotices(array $entries): array;
}//end interface
