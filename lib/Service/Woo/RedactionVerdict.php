<?php

/**
 * OpenRegister's irreversibility verdict on a redacted file, reduced to what
 * may be stored.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\IL10N;

/**
 * The verdict OpenRegister's `anonymizeDocument()` reports through
 * `FileService::getLastVerification()` (REQ-RRS-001), or the summary an
 * assessment stored earlier, reduced to its verdict, the release flag, the
 * output mode, the routes it checked, and per finding the route and the count.
 * Nothing else is kept, so a value that was meant to be redacted can never
 * reach the assessment. Reading a stored summary again gives the same summary.
 *
 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
 */
final class RedactionVerdict {

	/**
	 * The engine found nothing of the redacted values in the output.
	 */
	public const CLEAN = 'clean';

	/**
	 * The engine could not check the output, or reported nothing at all.
	 */
	public const UNVERIFIABLE = 'unverifiable';

	/**
	 * The summary.
	 *
	 * @var array{verdict: string, mayBePublished: bool, outputMode: string, routesChecked: array<int, string>, findings: array<int, array{route: string, count: int}>}
	 */
	private array $summary;

	/**
	 * Read a verdict. Anything that is not a list with a verdict reads as
	 * `unverifiable`, and `clean` counts only with the engine's own release flag.
	 *
	 * @param mixed $verification What `getLastVerification()` returned, or a stored summary.
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public function __construct(mixed $verification) {
		if (is_array($verification) === false) {
			$verification = [];
		}

		$verdict = $verification['verdict'] ?? '';
		if (is_string($verdict) === false || $verdict === '') {
			$verdict = self::UNVERIFIABLE;
		}

		$releasable = (($verification['mayBePublished'] ?? false) === true);
		if ($verdict === self::CLEAN && $releasable === false) {
			$verdict = self::UNVERIFIABLE;
		}

		$this->summary = [
			'verdict' => $verdict,
			'mayBePublished' => ($releasable === true && $verdict === self::CLEAN),
			'outputMode' => $this->text(value: ($verification['outputMode'] ?? '')),
			'routesChecked' => $this->routesChecked(routes: ($verification['routesChecked'] ?? [])),
			'findings' => $this->findings(findings: ($verification['findings'] ?? [])),
		];
	}//end __construct()

	/**
	 * The verdict as it may be stored: routes and counts, never values.
	 *
	 * @return array{verdict: string, mayBePublished: bool, outputMode: string, routesChecked: array<int, string>, findings: array<int, array{route: string, count: int}>}
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public function summary(): array {
		return $this->summary;
	}//end summary()

	/**
	 * The verdict: clean, leaking, unverifiable or another the engine names.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public function verdict(): string {
		return $this->summary['verdict'];
	}//end verdict()

	/**
	 * Whether the verdict is clean, so the redaction may be verified.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public function isClean(): bool {
		return $this->summary['verdict'] === self::CLEAN;
	}//end isClean()

	/**
	 * Why this verdict refuses the redaction, naming the routes and counts and
	 * never a value; null when it is clean.
	 *
	 * @param IL10N $l10n The officer-facing reasons.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public function refusal(IL10N $l10n): ?string {
		if ($this->isClean() === true) {
			return null;
		}

		$parts = [];
		foreach ($this->summary['findings'] as $finding) {
			$parts[] = $finding['route'] . ' (' . $finding['count'] . ')';
		}

		if ($this->summary['verdict'] === self::UNVERIFIABLE || $parts === []) {
			return $l10n->t(
				'OpenRegister could not confirm that the redacted values are gone (verdict: %s). The document stays unpublished.',
				[$this->summary['verdict']]
			);
		}

		return $l10n->t(
			'OpenRegister found redacted values still recoverable in the redacted file: %s. The document stays unpublished.',
			[implode(', ', $parts)]
		);
	}//end refusal()

	/**
	 * The checked routes, as strings.
	 *
	 * @param mixed $routes The engine's list.
	 *
	 * @return array<int, string>
	 */
	private function routesChecked(mixed $routes): array {
		$out = [];
		foreach ((array)$routes as $route) {
			if (is_string($route) === true && $route !== '') {
				$out[] = $route;
			}
		}

		return $out;
	}//end routesChecked()

	/**
	 * The findings, each reduced to its route and count.
	 *
	 * @param mixed $findings The engine's findings.
	 *
	 * @return array<int, array{route: string, count: int}>
	 */
	private function findings(mixed $findings): array {
		$out = [];
		foreach ((array)$findings as $finding) {
			if (is_array($finding) === false) {
				continue;
			}

			$out[] = [
				'route' => $this->text(value: ($finding['route'] ?? '')),
				'count' => (int)($finding['count'] ?? 0),
			];
		}

		return $out;
	}//end findings()

	/**
	 * A scalar as a string, anything else as an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()
}//end class
