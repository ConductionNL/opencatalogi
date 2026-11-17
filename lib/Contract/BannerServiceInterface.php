<?php

/**
 * Contract of BannerService, for callers in other components.
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
 * Contract of BannerService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface BannerServiceInterface {
	/**
	 * Check a banner before it is saved.
	 *
	 * @param array<string, mixed> $banner The banner.
	 *
	 * @return array<string, mixed> The banner, as it should be stored.
	 *
	 * @throws DomainException When it carries no body, no readable period, or an unknown severity.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function validate(array $banner): array;

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
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function isCurrent(array $banner, ?DateTimeInterface $now = null): bool;

	/**
	 * The banners one user should see.
	 *
	 * @param array<int, array<string, mixed>> $banners Every banner.
	 * @param array<int, string> $dismissedIds The banner ids this user dismissed.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The banners for this user.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function forUser(array $banners, array $dismissedIds, ?DateTimeInterface $now = null): array;
}//end interface
