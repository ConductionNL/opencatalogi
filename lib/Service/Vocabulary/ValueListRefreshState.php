<?php

/**
 * OpenCatalogi value-list refresh state: the configured sources, the last results and the clock.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Vocabulary
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Vocabulary;

use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Reads the sources from, and writes the results to, this app's config.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
class ValueListRefreshState {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig   $config Holds the sources and the results.
	 * @param ITimeFactory $time   The clock.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The configured sources as decoded, or null when none or unreadable.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function configuredSources(): mixed {
		return json_decode($this->config->getValueString(app: AppIdentity::APP_ID, key: ValueListRefresh::SOURCES_KEY, default: ''), true);
	}//end configuredSources()

	/**
	 * The stored results, so a scheme not refreshed this run keeps its last one.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function results(): array {
		$results = json_decode($this->config->getValueString(app: AppIdentity::APP_ID, key: ValueListRefresh::STATE_KEY, default: ''), true);
		if (is_array($results) === false) {
			return [];
		}

		return $results;
	}//end results()

	/**
	 * Store the results.
	 *
	 * @param array<string, mixed> $results Per scheme key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function store(array $results): void {
		$this->config->setValueString(
			app: AppIdentity::APP_ID,
			key: ValueListRefresh::STATE_KEY,
			value: (string)json_encode($results, JSON_UNESCAPED_SLASHES)
		);
	}//end store()

	/**
	 * The current moment as ISO 8601, UTC.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function now(): string {
		return $this->time->getDateTime('now', new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
