<?php

/**
 * OpenCatalogi metadata-quality (MQA/FAIR) scoring service.
 *
 * Derives a per-publication metadata-quality score from the `dcat:Dataset` node
 * the DCAT layer already renders (no second mapping, no bespoke query layer). The
 * score breaks into the five DCAT-AP / EU-MQA dimensions — findability,
 * accessibility, interoperability, reusability, contextuality — each a 0–100
 * sub-score, combined into an admin-weighted 0–100 total, with a per-dimension
 * breakdown naming the missing/invalid properties. It reflects only what a
 * harvester sees in the emitted dataset; it is advisory and never gates
 * publishing (PQM-001/PQM-004).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\QualityServiceInterface;
use OCP\IAppConfig;

/**
 * Scores DCAT dataset metadata quality over the five MQA dimensions.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/publication-quality/spec.md
 */
class QualityService implements QualityServiceInterface {

	/**
	 * The five MQA dimensions.
	 *
	 * @var array<int, string>
	 */
	public const DIMENSIONS = ['findability', 'accessibility', 'interoperability', 'reusability', 'contextuality'];

	/**
	 * Default per-dimension weights (sum 100), approximating the EU MQA weighting.
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_WEIGHTS = [
		'findability' => 25,
		'accessibility' => 25,
		'interoperability' => 20,
		'reusability' => 20,
		'contextuality' => 10,
	];

	/**
	 * The roll-up tally before any dataset is scored.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_TALLY = [
		'scored' => [],
		'sum' => 0,
		'distribution' => ['0-25' => 0, '26-50' => 0, '51-75' => 0, '76-100' => 0],
		'missingBreakdown' => [],
	];

	/**
	 * Non-proprietary / machine-readable format tokens rewarded for interoperability.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_FORMATS = ['csv', 'json', 'xml', 'rdf', 'ttl', 'jsonld', 'geojson', 'ods', 'txt'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the admin-configurable dimension weights.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Resolve the admin-configured (or default EU MQA) dimension weights.
	 *
	 * @return array<string, int> Dimension → weight.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function weights(): array {
		$weights = [];
		foreach (self::DEFAULT_WEIGHTS as $dimension => $default) {
			$weights[$dimension] = $this->appConfig->getValueInt('opencatalogi', 'quality_weight_' . $dimension, $default);
		}

		return $weights;
	}//end weights()

	/**
	 * Score a single rendered `dcat:Dataset` node.
	 *
	 * @param array<string, mixed> $node The `dcat:Dataset` node (CURIE keys).
	 *
	 * @return array{total: int, dimensions: array<string, int>, missing: array<int, string>} The score.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function scoreDataset(array $node): array {
		$missing = [];
		$dimensions = [
			'findability' => $this->scoreFindability(node: $node, missing: $missing),
			'accessibility' => $this->scoreAccessibility(node: $node, missing: $missing),
			'interoperability' => $this->scoreInteroperability(node: $node, missing: $missing),
			'reusability' => $this->scoreReusability(node: $node, missing: $missing),
			'contextuality' => $this->scoreContextuality(node: $node, missing: $missing),
		];

		$total = $this->weightedTotal(dimensions: $dimensions);

		return [
			'total' => $total,
			'dimensions' => $dimensions,
			'missing' => array_values(array_unique($missing)),
		];

	}//end scoreDataset()

	/**
	 * Score findability: title, keywords, and a bound (authority) theme.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The 0–100 sub-score.
	 */
	private function scoreFindability(array $node, array &$missing): int {
		$checks = [
			[$this->present(node: $node, key: 'dct:title'), 33, 'dct:title'],
			[$this->present(node: $node, key: 'dcat:keyword'), 33, 'dcat:keyword'],
			// Theme only counts when bound to an authority URI (@id), never a literal.
			[$this->isIdRef(value: $node['dcat:theme'] ?? null), 34, 'dcat:theme'],
		];

		return $this->awarded(checks: $checks, missing: $missing);
	}//end scoreFindability()

	/**
	 * Score accessibility: at least one distribution with a resolvable URL.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The 0–100 sub-score.
	 */
	private function scoreAccessibility(array $node, array &$missing): int {
		foreach ($this->distributions(node: $node) as $distribution) {
			if ($this->isIdRef(value: $distribution['dcat:downloadURL'] ?? null) === true
				|| $this->isIdRef(value: $distribution['dcat:accessURL'] ?? null) === true
			) {
				return 100;
			}
		}

		$missing[] = 'dcat:distribution';
		return 0;
	}//end scoreAccessibility()

	/**
	 * Score interoperability: format + media type, rewarding open formats.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The 0–100 sub-score.
	 */
	private function scoreInteroperability(array $node, array &$missing): int {
		$distributions = $this->distributions(node: $node);
		if ($distributions === []) {
			$missing[] = 'dct:format';
			return 0;
		}

		$best = 0;
		foreach ($distributions as $distribution) {
			$best = max($best, $this->distributionScore(distribution: $distribution));
		}

		if ($best < 40) {
			$missing[] = 'dct:format';
		}

		return $best;
	}//end scoreInteroperability()

	/**
	 * Score reusability: license and publisher.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The 0–100 sub-score.
	 */
	private function scoreReusability(array $node, array &$missing): int {
		$checks = [
			[$this->present(node: $node, key: 'dct:license'), 50, 'dct:license'],
			[$this->present(node: $node, key: 'dct:publisher'), 50, 'dct:publisher'],
		];

		return $this->awarded(checks: $checks, missing: $missing);
	}//end scoreReusability()

	/**
	 * Contextuality: a date (modified or issued) and an identifier (or a landing page).
	 *
	 * @param array $node The dataset node.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The dimension score.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function scoreContextuality(array $node, array &$missing): int {
		$dated = $this->present(node: $node, key: 'dct:modified') === true || $this->present(node: $node, key: 'dct:issued') === true;
		$identified = $this->present(node: $node, key: 'dct:identifier') === true
			|| $this->isIdRef(value: $node['dcat:landingPage'] ?? null) === true;

		return $this->awarded(checks: [[$dated, 50, 'dct:modified'], [$identified, 50, 'dct:identifier']], missing: $missing);
	}//end scoreContextuality()

	/**
	 * The points of every passed check; each failed check appends its property to the missing list.
	 *
	 * @param array<int, array{0: bool, 1: int, 2: string}> $checks Passed, points, missing property.
	 * @param array<int, string> $missing Missing-property accumulator (by reference).
	 *
	 * @return int The points.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function awarded(array $checks, array &$missing): int {
		$score = 0;
		foreach ($checks as [$passed, $points, $property]) {
			if ($passed === true) {
				$score += $points;
				continue;
			}

			$missing[] = $property;
		}

		return $score;
	}//end awarded()

	/**
	 * One distribution's interoperability: a format, a media type and an open format.
	 *
	 * @param array $distribution The distribution node.
	 *
	 * @return int The score, 0 to 100.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function distributionScore(array $distribution): int {
		$score = 0;
		if (($distribution['dct:format'] ?? null) !== null && $distribution['dct:format'] !== []) {
			$score += 40;
		}

		if (($distribution['dcat:mediaType'] ?? null) !== null && $distribution['dcat:mediaType'] !== '') {
			$score += 30;
		}

		if ($this->isOpenFormat(distribution: $distribution) === true) {
			$score += 30;
		}

		return $score;
	}//end distributionScore()

	/**
	 * The weighted mean of the dimension scores under the configured weights; 0 when every weight is 0.
	 *
	 * @param array<string, int> $dimensions The dimension scores.
	 *
	 * @return int The total, rounded.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function weightedTotal(array $dimensions): int {
		$weights = $this->weights();
		$weightSum = array_sum($weights);
		if ($weightSum <= 0) {
			return 0;
		}

		$weighted = 0.0;
		foreach ($dimensions as $dimension => $sub) {
			$weighted += ($sub * ($weights[$dimension] ?? 0));
		}

		return (int)round($weighted / $weightSum);
	}//end weightedTotal()

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
	public function rollup(array $nodes, int $worst = 5): array {
		$tally = self::EMPTY_TALLY;
		foreach ($nodes as $node) {
			$tally = $this->withScored(tally: $tally, node: $node);
		}

		$scored = $tally['scored'];
		$count = count($scored);
		$average = 0;
		if ($count > 0) {
			$average = (int)round($tally['sum'] / $count);
		}

		usort($scored, static fn ($a, $b) => ($a['total'] <=> $b['total']));
		$distribution = $tally['distribution'];
		$missingBreakdown = $tally['missingBreakdown'];

		return [
			'count' => $count,
			'average' => $average,
			'distribution' => $distribution,
			'worst' => array_slice($scored, 0, max(0, $worst)),
			'missingBreakdown' => $missingBreakdown,
		];

	}//end rollup()

	/**
	 * The roll-up tally with one more dataset scored: its entry, total, band and missing properties.
	 *
	 * @param array<string, mixed> $tally The tally so far: scored, sum, distribution, missingBreakdown.
	 * @param array $node The dataset node.
	 *
	 * @return array<string, mixed> The tally.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function withScored(array $tally, array $node): array {
		$score = $this->scoreDataset(node: $node);
		$tally['scored'][] = ['iri' => ($node['@id'] ?? '')] + $score;
		$tally['sum'] += $score['total'];
		$tally['distribution'][$this->band(total: $score['total'])]++;
		foreach ($score['missing'] as $property) {
			$tally['missingBreakdown'][$property] = (($tally['missingBreakdown'][$property] ?? 0) + 1);
		}

		return $tally;
	}//end withScored()

	/**
	 * Build the W3C DQV `dqv:hasQualityMeasurement` nodes for a scored dataset.
	 *
	 * @param array{total: int, dimensions: array<string, int>} $score The score.
	 *
	 * @return array<int, array<string, mixed>> The DQV measurement nodes (dimensions + total).
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	public function dqvMeasurements(array $score): array {
		$measurements = [];
		foreach ($score['dimensions'] as $dimension => $value) {
			$measurements[] = $this->measurement(dimension: $dimension, value: $value);
		}

		$measurements[] = $this->measurement(dimension: 'total', value: $score['total']);

		return $measurements;
	}//end dqvMeasurements()

	/**
	 * One DQV quality measurement of a dimension (or the total).
	 *
	 * @param string $dimension The dimension name, or `total`.
	 * @param mixed $value The score.
	 *
	 * @return array<string, mixed> The measurement node.
	 *
	 * @spec openspec/specs/publication-quality/spec.md
	 */
	private function measurement(string $dimension, mixed $value): array {
		return [
			'@type' => 'dqv:QualityMeasurement',
			'dqv:isMeasurementOf' => ['@id' => 'https://data.overheid.nl/dqv/dimension/' . $dimension],
			'dqv:value' => (int)$value,
		];
	}//end measurement()

	/**
	 * Whether a node property is present (non-empty).
	 *
	 * @param array<string, mixed> $node The node.
	 * @param string $key The property key.
	 *
	 * @return boolean True when present and non-empty.
	 */
	private function present(array $node, string $key): bool {
		$value = ($node[$key] ?? null);
		return ($value !== null && $value !== '' && $value !== []);
	}//end present()

	/**
	 * Whether a value is an `{@id: …}` reference (a bound authority/URL, not a literal).
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean True when it is a non-empty `@id` reference.
	 */
	private function isIdRef(mixed $value): bool {
		return (is_array($value) === true && isset($value['@id']) === true && $value['@id'] !== '');
	}//end isIdRef()

	/**
	 * Extract the distribution list from a dataset node (normalised to a list).
	 *
	 * @param array<string, mixed> $node The dataset node.
	 *
	 * @return array<int, array<string, mixed>> The distributions.
	 */
	private function distributions(array $node): array {
		$distributions = ($node['dcat:distribution'] ?? []);
		if (is_array($distributions) === false) {
			return [];
		}

		// A single distribution may be an associative node rather than a list.
		if (isset($distributions['@type']) === true || isset($distributions['dcat:downloadURL']) === true) {
			return [$distributions];
		}

		return array_values($distributions);
	}//end distributions()

	/**
	 * Whether a distribution advertises a non-proprietary / machine-readable format.
	 *
	 * @param array<string, mixed> $distribution The distribution node.
	 *
	 * @return boolean True when an open format is detected.
	 */
	private function isOpenFormat(array $distribution): bool {
		$encoded = json_encode($distribution);
		if ($encoded === false) {
			return false;
		}

		$haystack = strtolower($encoded);
		foreach (self::OPEN_FORMATS as $format) {
			if (str_contains($haystack, '/' . $format) === true || str_contains($haystack, $format . '"') === true) {
				return true;
			}
		}

		return false;
	}//end isOpenFormat()

	/**
	 * The distribution band label for a 0–100 score.
	 *
	 * @param integer $total The score.
	 *
	 * @return string The band key.
	 */
	private function band(int $total): string {
		if ($total <= 25) {
			return '0-25';
		}

		if ($total <= 50) {
			return '26-50';
		}

		if ($total <= 75) {
			return '51-75';
		}

		return '76-100';
	}//end band()
}//end class
