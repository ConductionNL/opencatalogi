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
use OCA\OpenCatalogi\Contract\BannerServiceInterface;

/**
 * Decides which banners one user sees right now.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
class BannerService implements BannerServiceInterface {

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

		$this->assertReadablePeriod(banner: $banner);

		$banner['severity'] = $this->knownSeverityOf(banner: $banner);
		$banner['dismissable'] = (bool)($banner['dismissable'] ?? true);

		return $banner;
	}//end validate()

	/**
	 * Refuse a banner whose period cannot be read or ends before it starts.
	 *
	 * @param array<string, mixed> $banner The banner.
	 *
	 * @return void
	 *
	 * @throws DomainException When the period is unreadable or reversed.
	 */
	private function assertReadablePeriod(array $banner): void {
		try {
			$period = $this->periodOf(banner: $banner);
		} catch (\Throwable $e) {
			throw new DomainException(
				message: 'A banner needs a period this app can read. A banner with no readable end date never stops showing.',
				code: 0,
				previous: $e
			);
		}

		if ($period['end'] < $period['start']) {
			throw new DomainException(message: 'A banner cannot end before it starts.');
		}

	}//end assertReadablePeriod()

	/**
	 * A banner's severity, `info` when it names none.
	 *
	 * @param array<string, mixed> $banner The banner.
	 *
	 * @return string The severity.
	 *
	 * @throws DomainException When the severity is not one of SEVERITIES.
	 */
	private function knownSeverityOf(array $banner): string {
		$severity = (string)($banner['severity'] ?? 'info');
		if (in_array($severity, self::SEVERITIES, true) === false) {
			throw new DomainException(message: 'A banner cannot have severity "' . $severity . '".');
		}

		return $severity;

	}//end knownSeverityOf()

	/**
	 * A banner's start and end date.
	 *
	 * @param array<string, mixed> $banner The banner.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable} The period.
	 *
	 * @throws \Exception When either date cannot be read.
	 */
	private function periodOf(array $banner): array {
		return [
			'start' => new DateTimeImmutable((string)($banner['startDate'] ?? '')),
			'end' => new DateTimeImmutable((string)($banner['endDate'] ?? '')),
		];

	}//end periodOf()

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
			$period = $this->periodOf(banner: $banner);
		} catch (\Throwable $e) {
			return false;
		}

		return ($moment >= $period['start'] && $moment <= $period['end']);
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
		foreach ($this->currentBanners(banners: $banners, now: $now) as $banner) {
			if ($this->staysDismissed(banner: $banner, dismissed: $dismissed) === false) {
				$shown[] = $banner;
			}
		}

		return $shown;
	}//end forUser()

	/**
	 * The entries that are banners inside their period, in the given order.
	 *
	 * @param array<int, mixed> $banners Every banner.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The current banners.
	 */
	private function currentBanners(array $banners, ?DateTimeInterface $now): array {
		$current = [];
		foreach ($banners as $banner) {
			if (is_array($banner) === true && $this->isCurrent(banner: $banner, now: $now) === true) {
				$current[] = $banner;
			}
		}

		return $current;

	}//end currentBanners()

	/**
	 * Whether a user's dismissal hides a banner.
	 *
	 * A banner that is not dismissable keeps showing even to a user who once
	 * dismissed one: a critical maintenance notice is not something a click
	 * makes go away.
	 *
	 * @param array<string, mixed> $banner The banner.
	 * @param array<string, int> $dismissed The dismissed ids, as keys.
	 *
	 * @return boolean True when the banner stays hidden for this user.
	 */
	private function staysDismissed(array $banner, array $dismissed): bool {
		$id = (string)($banner['id'] ?? '');
		$dismissable = (bool)($banner['dismissable'] ?? true);

		return ($dismissable === true && isset($dismissed[$id]) === true);

	}//end staysDismissed()
}//end class
