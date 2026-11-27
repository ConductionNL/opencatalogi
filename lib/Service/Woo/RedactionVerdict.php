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

/**
 * The verdict OpenRegister's `anonymizeDocument()` reports through
 * `FileService::getLastVerification()` (REQ-RRS-001), reduced to its verdict,
 * output mode, the routes it checked, and per finding the route and the count.
 * Nothing else is kept, so a value that was meant to be redacted can never
 * reach the assessment.
 *
 * @phpstan-type VerdictSummary array{
 *     verdict: string,
 *     outputMode: string,
 *     routesChecked: array<int, string>,
 *     findings: array<int, array{route: string, count: int}>
 * }
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
	 * The verdict as it may be stored: routes and counts, never values.
	 *
	 * An answer that is not a list with a verdict reads as `unverifiable`.
	 *
	 * @param mixed $verification What `getLastVerification()` returned.
	 *
	 * @return VerdictSummary
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public static function summary(mixed $verification): array {
		if (is_array($verification) === false) {
			$verification = [];
		}

		$verdict = $verification['verdict'] ?? '';
		if (is_string($verdict) === false || $verdict === '') {
			$verdict = self::UNVERIFIABLE;
		}

		// A clean verdict counts only with the engine's own release flag.
		if ($verdict === self::CLEAN && ($verification['mayBePublished'] ?? false) !== true) {
			$verdict = self::UNVERIFIABLE;
		}

		return [
			'verdict' => $verdict,
			'outputMode' => self::text(value: ($verification['outputMode'] ?? '')),
			'routesChecked' => self::routesChecked(routes: ($verification['routesChecked'] ?? [])),
			'findings' => self::findings(findings: ($verification['findings'] ?? [])),
		];
	}//end summary()

	/**
	 * Whether a stored summary is a clean verdict.
	 *
	 * @param mixed $summary The stored summary.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public static function isClean(mixed $summary): bool {
		return is_array($summary) === true && ($summary['verdict'] ?? '') === self::CLEAN;
	}//end isClean()

	/**
	 * The verdict of a stored summary, or an empty string when there is none.
	 *
	 * @param mixed $summary The stored summary.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public static function verdictOf(mixed $summary): string {
		if (is_array($summary) === false) {
			return '';
		}

		return self::text(value: ($summary['verdict'] ?? ''));
	}//end verdictOf()

	/**
	 * The findings of a summary as "route (count)", comma separated.
	 *
	 * @param VerdictSummary $summary The summary.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
	 */
	public static function routeCounts(array $summary): string {
		$parts = [];
		foreach ($summary['findings'] as $finding) {
			$parts[] = $finding['route'] . ' (' . $finding['count'] . ')';
		}

		return implode(', ', $parts);
	}//end routeCounts()

	/**
	 * The checked routes, as strings.
	 *
	 * @param mixed $routes The engine's list.
	 *
	 * @return array<int, string>
	 */
	private static function routesChecked(mixed $routes): array {
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
	private static function findings(mixed $findings): array {
		$out = [];
		foreach ((array)$findings as $finding) {
			if (is_array($finding) === false) {
				continue;
			}

			$out[] = [
				'route' => self::text(value: ($finding['route'] ?? '')),
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
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()
}//end class
