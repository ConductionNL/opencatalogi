<?php

/**
 * OpenCatalogi Notice Board Service.
 *
 * A publication is a document the Woo obliges us to hold. A notice is
 * something a municipality wants to say this week. They have different
 * lifetimes, different obligations and different readers, so a notice is its
 * own thing and never enters the sitemap or the DiWoo feed. Reusing
 * `publication` for a storingsmelding would put it in both.
 *
 * Comments on a notice are a moderation duty, so they are off by default, and
 * a board that enables them names a moderator or the save is refused.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Community
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Notice boards, their notices, and the line between a notice and a publication.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
 */
class NoticeBoardService {

	/**
	 * The schema slug a notice lives under.
	 *
	 * Named here so the sitemap exclusion below is a comparison against one
	 * constant rather than a string repeated in three places.
	 *
	 * @var string
	 */
	public const NOTICE_SCHEMA = 'notice';

	/**
	 * Check a board before it is saved.
	 *
	 * @param array<string, mixed> $board The board.
	 *
	 * @return array<string, mixed> The board, as it should be stored.
	 *
	 * @throws DomainException When comments are enabled and no moderator is named.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function validateBoard(array $board): array {
		if (trim((string)($board['title'] ?? '')) === '') {
			throw new DomainException(message: 'A notice board needs a title.');
		}

		$commentsEnabled = (bool)($board['commentsEnabled'] ?? false);
		$moderator = trim((string)($board['moderator'] ?? ''));

		if ($commentsEnabled === true && $moderator === '') {
			throw new DomainException(
				message: 'A board with comments has to name a moderator. Comments nobody is responsible for are a duty nobody accepted.'
			);
		}

		$board['commentsEnabled'] = $commentsEnabled;
		$board['moderator'] = ($moderator === '' ? null : $moderator);

		return $board;

	}//end validateBoard()

	/**
	 * Whether a reader is offered a comment form on this board.
	 *
	 * @param array<string, mixed> $board The board.
	 *
	 * @return boolean True only when comments are on and somebody moderates.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function commentsOffered(array $board): bool {
		return ((bool)($board['commentsEnabled'] ?? false) === true
			&& trim((string)($board['moderator'] ?? '')) !== '');

	}//end commentsOffered()

	/**
	 * Check a notice before it is saved.
	 *
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed> The notice, as it should be stored.
	 *
	 * @throws DomainException When it carries no board, title or readable period.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function validateNotice(array $notice): array {
		if (trim((string)($notice['board'] ?? '')) === '') {
			throw new DomainException(message: 'A notice belongs to a board.');
		}

		if (trim((string)($notice['title'] ?? '')) === '') {
			throw new DomainException(message: 'A notice needs a title.');
		}

		try {
			$start = new DateTimeImmutable((string)($notice['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($notice['endDate'] ?? ''));
		} catch (\Throwable $e) {
			throw new DomainException(
				message: 'A notice needs a period this app can read.',
				code: 0,
				previous: $e
			);
		}

		if ($end < $start) {
			throw new DomainException(message: 'A notice cannot end before it starts.');
		}

		return $notice;

	}//end validateNotice()

	/**
	 * Whether a notice is inside its period.
	 *
	 * @param array<string, mixed> $notice The notice.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True while it should be shown.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function isCurrent(array $notice, ?DateTimeInterface $now = null): bool {
		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		try {
			$start = new DateTimeImmutable((string)($notice['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($notice['endDate'] ?? ''));
		} catch (\Throwable $e) {
			return false;
		}

		return ($moment >= $start && $moment <= $end);

	}//end isCurrent()

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
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function belongsInSitemap(array $entry): bool {
		$schema = (string)($entry['@type'] ?? ($entry['schema'] ?? ($entry['recordType'] ?? '')));

		return ($schema !== self::NOTICE_SCHEMA);

	}//end belongsInSitemap()

	/**
	 * A list of entries with every notice removed.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return array<int, array<string, mixed>> The entries that belong in the sitemap.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	public function excludeNotices(array $entries): array {
		return array_values(
			array_filter(
				$entries,
				fn (array $entry): bool => $this->belongsInSitemap(entry: $entry)
			)
		);

	}//end excludeNotices()
}//end class
