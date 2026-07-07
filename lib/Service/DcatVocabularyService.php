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
class DcatVocabularyService
{

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
        'geospatial'                        => ['id' => 'c_ac64a52d', 'label' => 'Geospatial', 'aliases' => ['geo', 'geografisch']],
        'earth observation and environment' => [
            'id'      => 'c_dd313021',
            'label'   => 'Earth observation and environment',
            'aliases' => ['earth observation', 'environment', 'milieu', 'aardobservatie'],
        ],
        'meteorological'                    => ['id' => 'c_164e0bf5', 'label' => 'Meteorological', 'aliases' => ['meteo', 'weer', 'meteorologisch']],
        'statistics'                        => ['id' => 'c_e1da4e07', 'label' => 'Statistics', 'aliases' => ['statistical', 'statistiek']],
        'companies and company ownership'   => [
            'id'      => 'c_a9135398',
            'label'   => 'Companies and company ownership',
            'aliases' => ['companies', 'bedrijven'],
        ],
        'mobility'                          => ['id' => 'c_b79e35eb', 'label' => 'Mobility', 'aliases' => ['mobiliteit']],
    ];

    /**
     * The EU MDR data-theme authority members, keyed by their authority code, with
     * lowercase alias match values (code + English/Dutch synonyms).
     *
     * @var array<string, array<int, string>>
     */
    private const DATA_THEMES = [
        'AGRI'      => ['agri', 'agriculture', 'landbouw', 'visserij', 'voedsel'],
        'ECON'      => ['econ', 'economy', 'finance', 'economie', 'financien'],
        'EDUC'      => ['educ', 'education', 'culture', 'sport', 'onderwijs', 'cultuur'],
        'ENER'      => ['ener', 'energy', 'energie'],
        'ENVI'      => ['envi', 'environment', 'milieu'],
        'GOVE'      => ['gove', 'government', 'public sector', 'bestuur', 'overheid'],
        'HEAL'      => ['heal', 'health', 'gezondheid', 'zorg'],
        'INTR'      => ['intr', 'international', 'internationaal'],
        'JUST'      => ['just', 'justice', 'legal', 'public safety', 'justitie', 'veiligheid'],
        'REGI'      => ['regi', 'regions', 'cities', 'regio', 'steden'],
        'SOCI'      => ['soci', 'population', 'society', 'bevolking', 'maatschappij', 'samenleving'],
        'TECH'      => ['tech', 'science', 'technology', 'wetenschap', 'technologie'],
        'TRAN'      => ['tran', 'transport', 'vervoer'],
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
    public function resolveHvdCategory(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $needle = strtolower(trim($value));
        foreach (self::HVD_CATEGORIES as $key => $entry) {
            $candidates = array_merge([$key, $entry['id'], strtolower(self::HVD_BASE.$entry['id'])], $entry['aliases']);
            if (in_array($needle, $candidates, true) === true) {
                return ['uri' => self::HVD_BASE.$entry['id'], 'label' => $entry['label']];
            }
        }

        return null;

    }//end resolveHvdCategory()

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
    public function resolveDataTheme(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $needle = strtolower(trim($value));
        foreach (self::DATA_THEMES as $code => $aliases) {
            $candidates = array_merge([strtolower($code), strtolower(self::DATA_THEME_BASE.$code)], $aliases);
            if (in_array($needle, $candidates, true) === true) {
                return self::DATA_THEME_BASE.$code;
            }
        }

        return null;

    }//end resolveDataTheme()

    /**
     * The bundled HVD-category value list (id → uri + label).
     *
     * @return array<string, array{uri: string, label: string}> The value list.
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md
     */
    public function hvdCategoryList(): array
    {
        $out = [];
        foreach (self::HVD_CATEGORIES as $entry) {
            $out[$entry['id']] = ['uri' => self::HVD_BASE.$entry['id'], 'label' => $entry['label']];
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
    public function dataThemeList(): array
    {
        $out = [];
        foreach (array_keys(self::DATA_THEMES) as $code) {
            $out[$code] = self::DATA_THEME_BASE.$code;
        }

        return $out;

    }//end dataThemeList()
}//end class
