<?php

/**
 * OpenCatalogi value-list refresh: each scheme from its national source, guarded.
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

use OCA\OpenCatalogi\Contract\ValueListRefreshInterface;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use RuntimeException;
use Throwable;

/**
 * Downloads each value list OpenCatalogi uses and imports it into OpenRegister's
 * vocabulary register, so a new TOOI entry is usable the next day without a release.
 *
 * A failed or unreadable download imports nothing, and neither does one that would
 * deprecate more than a third of the scheme's active concepts: a truncated answer
 * must not empty a list that publications point at. Both keep the scheme as it is,
 * store the reason and raise a pipeline incident.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
class ValueListRefresh implements ValueListRefreshInterface {

	/**
	 * The app config key of the sources, a JSON list of `{key, scheme, url}`.
	 *
	 * @var string
	 */
	public const SOURCES_KEY = 'tooi_scheme_sources';

	/**
	 * The app config key of the last result per scheme.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'tooi_scheme_refresh';

	/**
	 * The incident kind of a refresh that imported nothing.
	 *
	 * @var string
	 */
	public const INCIDENT_KIND = 'value-list-refresh';

	/**
	 * The download location of the TOOI repository.
	 *
	 * @var string
	 */
	private const REPOSITORY = 'https://repository.officiele-overheidspublicaties.nl/waardelijsten/';

	/**
	 * The seeded sources: the versions DiWoo 0.9.8 binds, as the TOOI publication lists their JSON downloads.
	 *
	 * @var array<int, array{key: string, scheme: string, url: string}>
	 */
	public const DEFAULT_SOURCES = [
		[
			'key' => 'informatiecategorieen',
			'scheme' => 'https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen',
			'url' => self::REPOSITORY . 'scw_woo_informatiecategorieen/4/json/scw_woo_informatiecategorieen_4.json',
		],
		[
			'key' => 'documentsoorten',
			'scheme' => 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten',
			'url' => self::REPOSITORY . 'ccw_plooi_documentsoorten/7/json/ccw_plooi_documentsoorten_7.json',
		],
		[
			'key' => 'soortHandeling',
			'scheme' => 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen',
			'url' => self::REPOSITORY . 'ccw_plooi_documenthandelingen/2/json/ccw_plooi_documenthandelingen_2.json',
		],
		[
			'key' => 'talen',
			'scheme' => 'https://identifier.overheid.nl/tooi/def/thes/xtrn/taal',
			'url' => self::REPOSITORY . 'scw_talen/1/json/scw_talen_1.json',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ValueListDownload           $download   Fetches a list as an import document.
	 * @param VocabularyRegisterInterface $vocabulary OpenRegister's vocabulary register.
	 * @param ValueListRefreshState       $state      The sources, the results and the clock.
	 * @param PipelineIncidents           $incidents  Tells the incident group about a refusal.
	 */
	public function __construct(
		private readonly ValueListDownload $download,
		private readonly VocabularyRegisterInterface $vocabulary,
		private readonly ValueListRefreshState $state,
		private readonly PipelineIncidents $incidents,
	) {
	}//end __construct()

	/**
	 * Refresh every source and store the result per scheme.
	 *
	 * @return array<string, array{lastRefreshAt: string, created: int, updated: int, deprecated: int, error: string|null}> The results.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function refreshAll(): array {
		$results = $this->state->results();
		foreach ($this->sources() as $source) {
			$results[$source['key']] = $this->refresh(source: $source);
		}

		$this->state->store(results: $results);

		return $results;
	}//end refreshAll()

	/**
	 * The sources: the configured list when it is a valid one, else the seeded one.
	 *
	 * @return array<int, array{key: string, scheme: string, url: string}> The sources.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function sources(): array {
		$configured = $this->state->configuredSources();
		if (is_array($configured) === false || $configured === []) {
			return self::DEFAULT_SOURCES;
		}

		$sources = [];
		foreach ($configured as $source) {
			if (is_array($source) === true
				&& is_string($source['key'] ?? null) === true
				&& is_string($source['scheme'] ?? null) === true
				&& is_string($source['url'] ?? null) === true
			) {
				$sources[] = ['key' => $source['key'], 'scheme' => $source['scheme'], 'url' => $source['url']];
			}
		}

		if ($sources === []) {
			return self::DEFAULT_SOURCES;
		}

		return $sources;
	}//end sources()

	/**
	 * Refresh one scheme: download, guard, import.
	 *
	 * @param array{key: string, scheme: string, url: string} $source The source.
	 *
	 * @return array{lastRefreshAt: string, created: int, updated: int, deprecated: int, error: string|null} The result.
	 */
	private function refresh(array $source): array {
		$result = ['lastRefreshAt' => $this->state->now(), 'created' => 0, 'updated' => 0, 'deprecated' => 0, 'error' => null];

		try {
			$built = $this->download->fetch(url: $source['url'], schemeUri: $source['scheme']);
			$this->guardMassDeprecation(schemeUri: $source['scheme'], incoming: $built['conceptUris']);
			$counts = $this->vocabulary->import(jsonLd: $built['jsonLd']);
		} catch (Throwable $e) {
			$result['error'] = $e->getMessage();
			$this->incidents->raise(
				kind: self::INCIDENT_KIND,
				subject: $source['key'],
				message: 'The value list ' . $source['key'] . ' was not refreshed and stays as it was: ' . $e->getMessage()
			);
			return $result;
		}

		$result['created'] = (int)($counts['created'] ?? 0);
		$result['updated'] = (int)($counts['updated'] ?? 0);
		$result['deprecated'] = (int)($counts['deprecated'] ?? 0);

		return $result;
	}//end refresh()

	/**
	 * Refuse an import that would deprecate more than a third of the active concepts.
	 *
	 * @param string             $schemeUri The scheme.
	 * @param array<int, string> $incoming  The concept uris of the download.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When it would.
	 */
	private function guardMassDeprecation(string $schemeUri, array $incoming): void {
		$active = $this->vocabulary->activeConceptUris(schemeUri: $schemeUri);
		$missing = array_diff($active, $incoming);
		if ($active !== [] && (count($missing) * 3) > count($active)) {
			throw new RuntimeException(
				'the download would deprecate ' . count($missing) . ' of ' . count($active) . ' active concepts'
			);
		}
	}//end guardMassDeprecation()
}//end class
