<?php

/**
 * The banners a user sees, without the ones that user dismissed.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\BannerServiceInterface;
use OCP\IAppConfig;

/**
 * Keeps each user's dismissed banner ids in the app config, under
 * `banner_dismissals_<uid>` as a JSON list, and filters the banners by them.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
 */
class BannerDismissals {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $config        Holds the dismissed ids per user.
	 * @param BannerServiceInterface $bannerService Decides which banners a user sees.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly BannerServiceInterface $bannerService,
	) {

	}//end __construct()

	/**
	 * The banners this user should see right now.
	 *
	 * @param array<int, array<string, mixed>> $banners The banner rows.
	 * @param string                           $uid     The user.
	 *
	 * @return array<int, array<string, mixed>> The banners to show.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function forUser(array $banners, string $uid): array {
		return $this->bannerService->forUser(
			banners: $banners,
			dismissedIds: array_map('strval', $this->dismissed(uid: $uid))
		);

	}//end forUser()

	/**
	 * Remember that this user dismissed a banner.
	 *
	 * @param string $uid      The user.
	 * @param string $bannerId The banner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function dismiss(string $uid, string $bannerId): void {
		$dismissed = $this->dismissed(uid: $uid);
		$dismissed[] = $bannerId;
		$this->config->setValueString(
			AppIdentity::APP_ID,
			'banner_dismissals_' . $uid,
			(string)json_encode(array_values(array_unique(array_map('strval', $dismissed))))
		);

	}//end dismiss()

	/**
	 * The ids this user dismissed, or none when the stored value is not a list.
	 *
	 * @param string $uid The user.
	 *
	 * @return array<mixed> The stored ids.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	private function dismissed(string $uid): array {
		$dismissed = json_decode($this->config->getValueString(AppIdentity::APP_ID, 'banner_dismissals_' . $uid, '[]'), true);
		if (is_array($dismissed) === false) {
			return [];
		}

		return $dismissed;

	}//end dismissed()
}//end class
