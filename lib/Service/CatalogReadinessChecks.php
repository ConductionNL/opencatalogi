<?php

/**
 * The readiness checks of one catalog, in the order they ran.
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
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Collects the checks of one catalog, each tagged with the catalog's slug.
 *
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */
final class CatalogReadinessChecks {

	/**
	 * The checks so far.
	 *
	 * @var list<array<string, string>>
	 */
	private array $checks = [];

	/**
	 * Constructor.
	 *
	 * @param string $slug The catalog slug.
	 */
	public function __construct(
		public readonly string $slug,
	) {

	}//end __construct()

	/**
	 * Record a passed check.
	 *
	 * @param string $id The check id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function pass(string $id): void {
		$this->checks[] = ['id' => $id, 'status' => 'pass', 'catalogSlug' => $this->slug];

	}//end pass()

	/**
	 * Record a failed check and why.
	 *
	 * @param string $id The check id.
	 * @param string $reason Why it failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function fail(string $id, string $reason): void {
		$this->checks[] = ['id' => $id, 'status' => 'fail', 'reason' => $reason, 'catalogSlug' => $this->slug];

	}//end fail()

	/**
	 * Record a skipped check and why.
	 *
	 * @param string $id The check id.
	 * @param string $reason Why it was skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function skip(string $id, string $reason): void {
		$this->checks[] = ['id' => $id, 'status' => 'skipped', 'reason' => $reason, 'catalogSlug' => $this->slug];

	}//end skip()

	/**
	 * The checks, in the order they were recorded.
	 *
	 * @return list<array<string, string>> The checks.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function all(): array {
		return $this->checks;
	}//end all()
}//end class
