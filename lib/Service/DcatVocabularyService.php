<?php

/**
 * OpenCatalogi DCAT controlled-vocabulary binding service.
 *
 * Bundled reference data for the two DCAT axes the national open-data portal
 * data.overheid.nl (DONL) and the EU portal data.europa.eu require as controlled
 * authority URIs rather than free text: High-Value Dataset categories (the six
 * categories of the EU Open Data Directive Implementing Regulation (EU) 2023/138,
 * authority `http://data.europa.eu/bna/c_…`) and the EU MDR `data-theme` authority
 * (`http://publications.europa.eu/resource/authority/data-theme/*`). The resolvers
 * fail closed: an input that does not map to a value-list member returns null so
 * the caller omits the axis rather than leaking a literal.
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

/**
 * Resolves the DCAT HVD-category and theme axes to controlled authority URIs.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */
class DcatVocabularyService {

	/**
	 * Authority base for HVD categories (EU Open Data Directive).
	 *
	 * @var string
	 */
	public const HVD_BASE = 'http://data.europa.eu/bna/';

	/**
	 * ELI of the applicable HVD legislation, Regulation (EU) 2023/138.
	 *
	 * @var string
	 */
	public const HVD_LEGISLATION = 'http://data.europa.eu/eli/reg_impl/2023/138/oj';

	/**
	 * Authority base for EU MDR data themes.
	 *
	 * @var string
	 */
	public const DATA_THEME_BASE = 'http://publications.europa.eu/resource/authority/data-theme/';

	/**
	 * The six ODD High-Value Dataset categories → authority id + canonical label,
	 * keyed by lowercase match value. Sourced from the EU high-value-dataset-category
	 * authority table.
	 *
	 * @var array<string, array{id: string, label: string, aliases: array<int, string>}>
	 */
	private const HVD_CATEGORIES = [
		'geospatial' => ['id' => 'c_ac64a52d', 'label' => 'Geospatial', 'aliases' => ['geo', 'geografisch']],
		'earth observation and environment' => [
			'id' => 'c_dd313021',
			'label' => 'Earth observation and environment',
			'aliases' => ['earth observation', 'environment', 'milieu', 'aardobservatie'],
		],
		'meteorological' => ['id' => 'c_164e0bf5', 'label' => 'Meteorological', 'aliases' => ['meteo', 'weer', 'meteorologisch']],
		'statistics' => ['id' => 'c_e1da4e07', 'label' => 'Statistics', 'aliases' => ['statistical', 'statistiek']],
		'companies and company ownership' => [
			'id' => 'c_a9135398',
			'label' => 'Companies and company ownership',
			'aliases' => ['companies', 'bedrijven'],
		],
		'mobility' => ['id' => 'c_b79e35eb', 'label' => 'Mobility', 'aliases' => ['mobiliteit']],
	];

	/**
	 * The EU MDR data-theme authority members, keyed by their authority code, with
	 * lowercase alias match values (code + English/Dutch synonyms).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const DATA_THEMES = [
		'AGRI' => ['agri', 'agriculture', 'landbouw', 'visserij', 'voedsel'],
		'ECON' => ['econ', 'economy', 'finance', 'economie', 'financien'],
		'EDUC' => ['educ', 'education', 'culture', 'sport', 'onderwijs', 'cultuur'],
		'ENER' => ['ener', 'energy', 'energie'],
		'ENVI' => ['envi', 'environment', 'milieu'],
		'GOVE' => ['gove', 'government', 'public sector', 'bestuur', 'overheid'],
		'HEAL' => ['heal', 'health', 'gezondheid', 'zorg'],
		'INTR' => ['intr', 'international', 'internationaal'],
		'JUST' => ['just', 'justice', 'legal', 'public safety', 'justitie', 'veiligheid'],
		'REGI' => ['regi', 'regions', 'cities', 'regio', 'steden'],
		'SOCI' => ['soci', 'population', 'society', 'bevolking', 'maatschappij', 'samenleving'],
		'TECH' => ['tech', 'science', 'technology', 'wetenschap', 'technologie'],
		'TRAN' => ['tran', 'transport', 'vervoer'],
		'OP_DATPRO' => ['op_datpro', 'provisional'],
	];

	/**
	 * Resolve an HVD category value to an official authority URI + label.
	 *
	 * Accepts the canonical or alias label, a bare `c_…` id, or a full authority URI.
	 *
	 * @param string|null $value The declared HVD category.
	 *
	 * @return array{uri: string, label: string}|null The resolved category, or null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function resolveHvdCategory(?string $value): ?array {
		$needle = self::needleOf(value: $value);
		if ($needle === null) {
			return null;
		}

		return self::hvdCategoryOf(needle: $needle);
	}//end resolveHvdCategory()

	/**
	 * The HVD category whose key, id, URI or alias is the needle.
	 *
	 * @param string $needle The trimmed, lowercased value.
	 *
	 * @return array{uri: string, label: string}|null
	 */
	private static function hvdCategoryOf(string $needle): ?array {
		foreach (self::HVD_CATEGORIES as $key => $entry) {
			$candidates = array_merge([$key, $entry['id'], strtolower(self::HVD_BASE . $entry['id'])], $entry['aliases']);
			if (in_array($needle, $candidates, true) === true) {
				return ['uri' => self::HVD_BASE . $entry['id'], 'label' => $entry['label']];
			}
		}

		return null;
	}//end hvdCategoryOf()

	/**
	 * Resolve a source theme value to an EU MDR data-theme authority URI.
	 *
	 * Accepts the authority code (e.g. `TRAN`), a known synonym, or the full URI.
	 *
	 * @param string|null $value The source theme value.
	 *
	 * @return string|null The MDR data-theme authority URI, or null when unresolved.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function resolveDataTheme(?string $value): ?string {
		$needle = self::needleOf(value: $value);
		if ($needle === null) {
			return null;
		}

		return self::dataThemeOf(needle: $needle);
	}//end resolveDataTheme()

	/**
	 * The data-theme URI whose code, URI or synonym is the needle.
	 *
	 * @param string $needle The trimmed, lowercased value.
	 *
	 * @return string|null
	 */
	private static function dataThemeOf(string $needle): ?string {
		foreach (self::DATA_THEMES as $code => $aliases) {
			$candidates = array_merge([strtolower($code), strtolower(self::DATA_THEME_BASE . $code)], $aliases);
			if (in_array($needle, $candidates, true) === true) {
				return self::DATA_THEME_BASE . $code;
			}
		}

		return null;
	}//end dataThemeOf()

	/**
	 * A declared value as the resolvers compare it: trimmed and lowercased, or null when blank.
	 *
	 * @param string|null $value The declared value.
	 *
	 * @return string|null
	 */
	private static function needleOf(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		return strtolower(trim($value));
	}//end needleOf()

	/**
	 * The bundled HVD-category value list (id → uri + label).
	 *
	 * @return array<string, array{uri: string, label: string}> The value list.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function hvdCategoryList(): array {
		$out = [];
		foreach (self::HVD_CATEGORIES as $entry) {
			$out[$entry['id']] = ['uri' => self::HVD_BASE . $entry['id'], 'label' => $entry['label']];
		}

		return $out;
	}//end hvdCategoryList()

	/**
	 * The bundled EU MDR data-theme authority codes → URIs.
	 *
	 * @return array<string, string> The value list.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function dataThemeList(): array {
		$out = [];
		foreach (array_keys(self::DATA_THEMES) as $code) {
			$out[$code] = self::DATA_THEME_BASE . $code;
		}

		return $out;
	}//end dataThemeList()

	/**
	 * Resolve a licence to its EU licences authority IRI.
	 *
	 * Accepts the authority IRI, the authority code (`CC0`), the SPDX id, an
	 * IRI the authority lists as an exact match (the Creative Commons deed,
	 * with or without `deed.nl`), or the exact English, Dutch or
	 * data.overheid.nl label, all case-insensitive. Anything else answers null,
	 * so the caller omits `dct:license` rather than emitting free text.
	 *
	 * @param string|null $value The declared licence.
	 *
	 * @return string|null The authority IRI, or null when the value is not on the list.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function resolveLicence(?string $value): ?string {
		$needle = self::needleOf(value: $value);
		if ($needle === null) {
			return null;
		}

		$needle = self::withoutIriNoise(value: $needle);
		foreach (self::licenceMembers() as $code => $member) {
			if (in_array($needle, self::licenceCandidates(code: (string)$code, member: $member), true) === true) {
				return (string)$member['iri'];
			}
		}

		return null;
	}//end resolveLicence()

	/**
	 * The bundled licence list: code → IRI, English and Dutch label.
	 *
	 * @return array<string, array{iri: string, label: string, labelNl: string}> The value list.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function licenceList(): array {
		$out = [];
		foreach (self::licenceMembers() as $code => $member) {
			$out[(string)$code] = [
				'iri' => (string)$member['iri'],
				'label' => (string)$member['label'],
				'labelNl' => (string)$member['labelNl'],
			];
		}

		return $out;
	}//end licenceList()

	/**
	 * The licence list as a picker reads it: one row per member with its code, and the list's provenance.
	 *
	 * @return array{results: array<int, array{code: string, iri: string, label: string, labelNl: string}>, total: int, source: string, version: string}
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 */
	public function licencePickerList(): array {
		$rows = [];
		foreach ($this->licenceList() as $code => $member) {
			$rows[] = ['code' => (string)$code] + $member;
		}

		return ['results' => $rows, 'total' => count($rows)] + $this->licenceListSource();
	}//end licencePickerList()

	/**
	 * Where the bundled licence list comes from: the authority and its version.
	 *
	 * @return array{source: string, version: string} The provenance.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function licenceListSource(): array {
		$list = self::bundledLists()['licences'];

		return ['source' => (string)$list['source'], 'version' => (string)$list['version']];
	}//end licenceListSource()

	/**
	 * The comparable values that name one licence.
	 *
	 * @param string $code The authority code.
	 * @param array<string, mixed> $member The bundled member.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private static function licenceCandidates(string $code, array $member): array {
		$names = [$code, $member['iri'], $member['label'], $member['labelNl'], ($member['spdx'] ?? ''), ($member['donlLabel'] ?? '')];
		$names = array_merge($names, ($member['matches'] ?? []));

		$out = [];
		foreach ($names as $name) {
			if (is_string($name) === true && $name !== '') {
				$out[] = self::withoutIriNoise(value: strtolower($name));
			}
		}

		return $out;
	}//end licenceCandidates()

	/**
	 * A lowercased value without the parts that do not tell licences apart:
	 * the scheme, a trailing `deed.<lang>` and a trailing slash.
	 *
	 * @param string $value The lowercased value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private static function withoutIriNoise(string $value): string {
		$value = (string)preg_replace('#^https?://#', '', $value);
		$value = (string)preg_replace('#/deed\.[a-z-]+$#', '/', $value);

		return rtrim($value, '/');
	}//end withoutIriNoise()

	/**
	 * The bundled licence members, keyed by authority code.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private static function licenceMembers(): array {
		return self::bundledLists()['licences']['members'];
	}//end licenceMembers()

	/**
	 * The bundled value lists in `lib/Settings/dcat_waardelijsten.json`, read once.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private static function bundledLists(): array {
		static $lists = null;
		if ($lists === null) {
			$raw = file_get_contents(__DIR__ . '/../Settings/dcat_waardelijsten.json');
			$lists = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
		}

		return $lists;
	}//end bundledLists()
}//end class
