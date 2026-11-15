<?php

/**
 * OpenCatalogi Woo Handover State.
 *
 * What the hand-over of the stored Woo requests to dossiq left behind: how many
 * requests are not moved yet, how many dossiq could not move, when that was
 * last counted, and whether the request schema is read-only here.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-every-stored-request-is-migrated-with-its-term-and-opencatalogis-timer-is-stopped-after-req-whd-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Reads and writes the hand-over counts and the read-only flag in app config.
 *
 * The counts are null until the hand-over step ran with dossiq installed. Once
 * a run leaves nothing unmoved the schema becomes read-only, and it stays so.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-every-stored-request-is-migrated-with-its-term-and-opencatalogis-timer-is-stopped-after-req-whd-003
 */
class WooHandoverState {

	/**
	 * How many requests are not moved to dossiq.
	 */
	public const KEY_UNMIGRATED = 'woo_requests_unmigrated';

	/**
	 * How many requests dossiq could not move.
	 */
	public const KEY_FAILED = 'woo_requests_failed';

	/**
	 * When the counts were taken.
	 */
	public const KEY_CHECKED_AT = 'woo_requests_migration_checked_at';

	/**
	 * Whether the request schema is read-only here.
	 */
	public const KEY_READ_ONLY = 'woo_requests_read_only';

	/**
	 * The app id.
	 */
	private const APP = 'opencatalogi';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig  $config     Holds the counts and the flag.
	 * @param IAppManager $appManager Tells whether dossiq is enabled.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Store the counts of a hand-over run, and make the schema read-only when nothing is left.
	 *
	 * @param int    $unmigrated How many requests are not moved.
	 * @param int    $failed     How many of those dossiq could not move.
	 * @param string $checkedAt  When (ISO 8601).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
	 */
	public function record(int $unmigrated, int $failed, string $checkedAt): void {
		$this->config->setValueString(self::APP, self::KEY_UNMIGRATED, (string)max(0, $unmigrated));
		$this->config->setValueString(self::APP, self::KEY_FAILED, (string)max(0, $failed));
		$this->config->setValueString(self::APP, self::KEY_CHECKED_AT, $checkedAt);
		if ($unmigrated === 0) {
			$this->config->setValueString(self::APP, self::KEY_READ_ONLY, '1');
		}
	}//end record()

	/**
	 * Whether the request schema is read-only here.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
	 */
	public function isReadOnly(): bool {
		return $this->config->getValueString(self::APP, self::KEY_READ_ONLY, '') === '1';
	}//end isReadOnly()

	/**
	 * Whether dossiq is enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
	 */
	public function dossiqInstalled(): bool {
		return $this->appManager->isEnabledForAnyone('dossiq') === true;
	}//end dossiqInstalled()

	/**
	 * The state as the readiness check and the Woo settings show it.
	 *
	 * @return array{unmigrated: int|null, failed: int|null, checkedAt: string, readOnly: bool, dossiqInstalled: bool}
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2
	 */
	public function report(): array {
		return [
			'unmigrated' => $this->count(key: self::KEY_UNMIGRATED),
			'failed' => $this->count(key: self::KEY_FAILED),
			'checkedAt' => $this->config->getValueString(self::APP, self::KEY_CHECKED_AT, ''),
			'readOnly' => $this->isReadOnly(),
			'dossiqInstalled' => $this->dossiqInstalled(),
		];
	}//end report()

	/**
	 * A stored count, or null when none was stored.
	 *
	 * @param string $key The config key.
	 *
	 * @return int|null The count.
	 */
	private function count(string $key): ?int {
		$value = $this->config->getValueString(self::APP, $key, '');
		if (ctype_digit($value) === false) {
			return null;
		}

		return (int)$value;
	}//end count()
}//end class
