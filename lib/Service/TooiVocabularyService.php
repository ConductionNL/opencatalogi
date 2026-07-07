<?php
/**
 * OpenCatalogi TOOI / DiWoo controlled-vocabulary binding service.
 *
 * Bundled reference data for the three DIWOO metadata axes the national Woo-index
 * (KOOP/DiWoo) requires as official value-list URIs rather than free text:
 * `informatiecategorie` (the 17 Woo categories), `publisher` (a TOOI organisatie
 * identifier), and `soortHandeling` (the DiWoo handling-type list). Values are the
 * official TOOI kern-thesaurus identifiers
 * (`https://identifier.overheid.nl/tooi/def/thes/kern/c_…`) and organisatie
 * identifiers (`https://identifier.overheid.nl/tooi/id/…`) sourced from the
 * `scw_woo_informatiecategorieen` and DiWoo documenthandelingen waardelijsten. The
 * resolvers fail closed: an input that does not map to a value-list member returns
 * null so the caller omits the axis rather than leaking a literal `@resource`.
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
 * Resolves the three DIWOO metadata axes to official TOOI/DiWoo value-list URIs.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class TooiVocabularyService
{

    /**
     * Base URI of the TOOI kern thesaurus.
     *
     * @var string
     */
    public const KERN_BASE = 'https://identifier.overheid.nl/tooi/def/thes/kern/';

    /**
     * Required prefix of a valid TOOI organisatie identifier.
     *
     * @var string
     */
    public const ORG_ID_BASE = 'https://identifier.overheid.nl/tooi/id/';

    /**
     * The 17 Woo informatiecategorieën → official TOOI kern identifier + canonical
     * label, keyed by the sitemap route code (WOO-003). Sourced from the
     * `scw_woo_informatiecategorieen` waardelijst.
     *
     * @var array<string, array{id: string, label: string, aliases: array<int, string>}>
     */
    private const INFORMATIECATEGORIEEN = [
        'infocat001' => ['id' => 'c_139c6280', 'label' => 'Wetten en algemeen verbindende voorschriften', 'aliases' => ['wet of algemeen verbindend voorschrift']],
        'infocat002' => ['id' => 'c_aab6bfc7', 'label' => 'Overige besluiten van algemene strekking', 'aliases' => ['overig besluit van algemene strekking']],
        'infocat003' => ['id' => 'c_759721e2', 'label' => 'Ontwerpen van wet- en regelgeving met adviesaanvraag', 'aliases' => ['ontwerp wet- en regelgeving']],
        'infocat004' => ['id' => 'c_40a05794', 'label' => 'Organisatie en werkwijze', 'aliases' => []],
        'infocat005' => ['id' => 'c_89ee6784', 'label' => 'Bereikbaarheidsgegevens', 'aliases' => []],
        'infocat006' => ['id' => 'c_8c840238', 'label' => 'Bij vertegenwoordigende organen ingekomen stukken', 'aliases' => ['bij vertegenwoordigend lichaam ingekomen stuk']],
        'infocat007' => ['id' => 'c_c76862ab', 'label' => 'Vergaderstukken Staten-Generaal', 'aliases' => ['vergaderstuk staten-generaal']],
        'infocat008' => ['id' => 'c_db4862c3', 'label' => 'Vergaderstukken decentrale overheden', 'aliases' => ['vergaderstuk lager vertegenwoordigend of openbaar lichaam']],
        'infocat009' => ['id' => 'c_3a248e3a', 'label' => "Agenda's en besluitenlijsten bestuurscolleges", 'aliases' => ['agenda of besluitenlijst bestuurscollege']],
        'infocat010' => ['id' => 'c_99a836c7', 'label' => 'Adviezen', 'aliases' => ['advies']],
        'infocat011' => ['id' => 'c_8fc2335c', 'label' => 'Convenanten', 'aliases' => ['convenant']],
        'infocat012' => ['id' => 'c_c6cd1213', 'label' => 'Jaarplannen en jaarverslagen', 'aliases' => ['jaarplan of jaarverslag']],
        'infocat013' => ['id' => 'c_cf268088', 'label' => 'Subsidieverplichtingen anders dan met beschikking', 'aliases' => ['subsidieverplichting']],
        'infocat014' => ['id' => 'c_3baef532', 'label' => 'Woo-verzoeken en -besluiten', 'aliases' => ['woo-verzoek']],
        'infocat015' => ['id' => 'c_fdaee95e', 'label' => 'Onderzoeksrapporten', 'aliases' => ['onderzoeksrapport']],
        'infocat016' => ['id' => 'c_46a81018', 'label' => 'Beschikkingen', 'aliases' => ['beschikking']],
        'infocat017' => ['id' => 'c_a870c43d', 'label' => 'Klachtoordelen', 'aliases' => ['klachtoordeel']],
    ];

    /**
     * The DiWoo soortHandeling waardelijst → official TOOI kern identifier.
     * Sourced from the DiWoo documenthandelingen value list.
     *
     * @var array<string, string>
     */
    private const SOORTHANDELINGEN = [
        'ontvangst'     => 'c_dfcee535',
        'vaststelling'  => 'c_641ecd76',
        'ondertekening' => 'c_e1ec050e',
    ];

    /**
     * The value-list member used when a publication declares no handling type.
     *
     * @var string
     */
    public const DEFAULT_SOORTHANDELING = 'ontvangst';

    /**
     * Resolve a category value to an official TOOI informatiecategorie member.
     *
     * Accepts the sitemap route code (`infocatNNN`), the canonical or alias label,
     * a bare `c_…` identifier, or a full kern URI. Returns null when the value maps
     * to no value-list member (the caller then omits the axis).
     *
     * @param string|null $value The publication's category value.
     *
     * @return array{uri: string, label: string}|null The resolved member, or null.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function resolveInformatiecategorie(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $needle = strtolower(trim($value));

        foreach (self::INFORMATIECATEGORIEEN as $code => $entry) {
            $candidates = array_map('strtolower', array_merge([$code, $entry['label'], $entry['id'], self::KERN_BASE.$entry['id']], $entry['aliases']));
            if (in_array($needle, $candidates, true) === true) {
                return ['uri' => self::KERN_BASE.$entry['id'], 'label' => $entry['label']];
            }
        }

        return null;

    }//end resolveInformatiecategorie()

    /**
     * Resolve a handling-type value to an official DiWoo soortHandeling member.
     *
     * Defaults to `ontvangst` when no value is supplied. Returns null when a
     * supplied value is not a value-list member.
     *
     * @param string|null $value The declared handling type, or null for the default.
     *
     * @return array{uri: string, label: string}|null The resolved member, or null.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function resolveSoortHandeling(?string $value): ?array
    {
        $needle = strtolower(trim((string) ($value ?? self::DEFAULT_SOORTHANDELING)));
        if ($needle === '') {
            $needle = self::DEFAULT_SOORTHANDELING;
        }

        // Accept a bare c_… id or a full kern URI too.
        foreach (self::SOORTHANDELINGEN as $label => $id) {
            if ($needle === $label || $needle === $id || $needle === strtolower(self::KERN_BASE.$id)) {
                return ['uri' => self::KERN_BASE.$id, 'label' => $label];
            }
        }

        return null;

    }//end resolveSoortHandeling()

    /**
     * Validate a candidate TOOI organisatie identifier.
     *
     * Returns the URI only when it is a well-formed TOOI organisatie identifier
     * (`https://identifier.overheid.nl/tooi/id/…`); an OpenRegister UUID or any
     * other value returns null so the caller omits `diwoo:publisher @resource`.
     *
     * @param string|null $tooiIdentifier The candidate identifier.
     *
     * @return string|null The valid TOOI organisatie URI, or null.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function resolveOrganisatie(?string $tooiIdentifier): ?string
    {
        if ($tooiIdentifier === null) {
            return null;
        }

        $uri = trim($tooiIdentifier);
        if ($uri === '' || str_starts_with($uri, self::ORG_ID_BASE) === false) {
            return null;
        }

        return $uri;

    }//end resolveOrganisatie()

    /**
     * The bundled informatiecategorie value list (code → uri + label).
     *
     * @return array<string, array{uri: string, label: string}> The value list.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function informatiecategorieList(): array
    {
        $out = [];
        foreach (self::INFORMATIECATEGORIEEN as $code => $entry) {
            $out[$code] = ['uri' => self::KERN_BASE.$entry['id'], 'label' => $entry['label']];
        }

        return $out;

    }//end informatiecategorieList()

    /**
     * The bundled soortHandeling value list (label → uri).
     *
     * @return array<string, string> The value list.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function soortHandelingList(): array
    {
        $out = [];
        foreach (self::SOORTHANDELINGEN as $label => $id) {
            $out[$label] = self::KERN_BASE.$id;
        }

        return $out;

    }//end soortHandelingList()
}//end class
