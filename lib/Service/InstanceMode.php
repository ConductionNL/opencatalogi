<?php

/**
 * Instance mode: production or staging, read fail-closed.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads the app config `instance_mode` (REQ-STG-001) on every call. Anything
 * but an explicit `production`, including a read that throws, counts as
 * staging: sending is the unsafe side.
 *
 * @spec openspec/changes/instance-staging-mode
 */
class InstanceMode {

	public const KEY = 'instance_mode';

	public const PRODUCTION = 'production';

	public const STAGING = 'staging';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Why the instance is not in production, or null when it is.
	 *
	 * @return string|null `staging`, `unreadable: <message>`, or null in production.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
	 */
	public function stagingReason(): ?string {
		try {
			$mode = $this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, self::PRODUCTION);
		} catch (Throwable $e) {
			return 'unreadable: ' . $e->getMessage();
		}

		if ($mode === self::PRODUCTION) {
			return null;
		}

		return self::STAGING;
	}//end stagingReason()

	/**
	 * Whether the instance must behave as staging.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-the-public-surface-is-closed-to-anonymous-callers-in-staging-req-stg-003
	 */
	public function isStaging(): bool {
		return $this->stagingReason() !== null;
	}//end isStaging()
}//end class
