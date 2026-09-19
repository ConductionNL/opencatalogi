<?php

/**
 * OpenCatalogi Banner Service.
 *
 * An instance banner: a body, a period, a severity and a dismissable flag,
 * shown to every signed-in user between its dates and to nobody outside them.
 * A banner belongs to the instance rather than to a catalogue, because planned
 * maintenance is not per catalogue.
 *
 * A dismissable banner a user dismissed is not shown to that user again, and is
 * still shown to everyone else: a dismissal is one person's, never the
 * banner's.
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
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Decides which banners one user sees right now.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
class BannerService {

	/**
	 * The severities a banner may carry.
	 *
	 * @var array<int, string>
	 */
	public const SEVERITIES = ['info', 'warning', 'critical'];

	/**
	 * Check a banner before it is saved.
	 *
	 * @param array<string, mixed> $banner The banner.
	 *
	 * @return array<string, mixed> The banner, as it should be stored.
	 *
	 * @throws DomainException When it carries no body, no readable period, or an unknown severity.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function validate(array $banner): array {
		if (trim((string)($banner['body'] ?? '')) === '') {
			throw new DomainException(message: 'A banner needs something to say.');
		}

		try {
			$start = new DateTimeImmutable((string)($banner['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($banner['endDate'] ?? ''));
		} catch (\Throwable $e) {
			throw new DomainException(
				message: 'A banner needs a period this app can read. A banner with no readable end date never stops showing.',
				code: 0,
				previous: $e
			);
		}

		if ($end < $start) {
			throw new DomainException(message: 'A banner cannot end before it starts.');
		}

		$severity = (string)($banner['severity'] ?? 'info');
		if (in_array($severity, self::SEVERITIES, true) === false) {
			throw new DomainException(message: 'A banner cannot have severity "' . $severity . '".');
		}

		$banner['severity'] = $severity;
		$banner['dismissable'] = (bool)($banner['dismissable'] ?? true);

		return $banner;

	}//end validate()

	/**
	 * Whether a banner is inside its period.
	 *
	 * A banner whose dates cannot be read is not shown. A banner that never
	 * stops is the one failure a maintenance banner must not have.
	 *
	 * @param array<string, mixed> $banner The banner.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True while it should be shown.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function isCurrent(array $banner, ?DateTimeInterface $now = null): bool {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		try {
			$start = new DateTimeImmutable((string)($banner['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($banner['endDate'] ?? ''));
		} catch (\Throwable $e) {
			return false;
		}

		return ($moment >= $start && $moment <= $end);

	}//end isCurrent()

	/**
	 * The banners one user should see.
	 *
	 * @param array<int, array<string, mixed>> $banners Every banner.
	 * @param array<int, string> $dismissedIds The banner ids this user dismissed.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The banners for this user.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function forUser(array $banners, array $dismissedIds, ?DateTimeInterface $now = null): array {
		$dismissed = array_flip(array_map('strval', $dismissedIds));

		$shown = [];
		foreach ($banners as $banner) {
			if (is_array($banner) === false) {
				continue;
			}

			if ($this->isCurrent(banner: $banner, now: $now) === false) {
				continue;
			}

			$id = (string)($banner['id'] ?? '');
			$dismissable = (bool)($banner['dismissable'] ?? true);

			// A banner that is not dismissable keeps showing even to a user who
			// once dismissed one: a critical maintenance notice is not
			// something a click makes go away.
			if ($dismissable === true && isset($dismissed[$id]) === true) {
				continue;
			}

			$shown[] = $banner;
		}

		return $shown;

	}//end forUser()
}//end class
