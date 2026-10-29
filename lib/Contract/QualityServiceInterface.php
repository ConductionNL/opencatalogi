<?php

/**
 * Contract of QualityService, for callers in other components.
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

/**
 * Contract of QualityService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface QualityServiceInterface {
	/**
	 * Resolve the admin-configured (or default EU MQA) dimension weights.
	 *
	 * @return array<string, int> Dimension → weight.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function weights(): array;

	/**
	 * Score a single rendered `dcat:Dataset` node.
	 *
	 * @param array<string, mixed> $node The `dcat:Dataset` node (CURIE keys).
	 *
	 * @return array{total: int, dimensions: array<string, int>, missing: array<int, string>} The score.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function scoreDataset(array $node): array;

	/**
	 * Aggregate a catalog roll-up over the scored dataset nodes.
	 *
	 * @param array<int, array<string, mixed>> $nodes The `dcat:Dataset` nodes.
	 * @param integer $worst The number of worst datasets to return.
	 *
	 * @return array<string, mixed> The roll-up: count, average, distribution, worst, missingBreakdown.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function rollup(array $nodes, int $worst = 5): array;

	/**
	 * Build the W3C DQV `dqv:hasQualityMeasurement` nodes for a scored dataset.
	 *
	 * @param array{total: int, dimensions: array<string, int>} $score The score.
	 *
	 * @return array<int, array<string, mixed>> The DQV measurement nodes (dimensions + total).
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function dqvMeasurements(array $score): array;
}//end interface
