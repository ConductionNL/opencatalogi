<?php

/**
 * OpenCatalogi TOOI / DiWoo controlled-vocabulary binding service.
 *
 * Resolves the DiWoo metadata axes the national Woo-index (KOOP/DiWoo) requires
 * as official value-list URIs rather than free text: `informatiecategorie`,
 * `soortHandeling`, `documentsoort`, `taal` and `publisher` (a TOOI organisatie
 * identifier). The lists are read from OpenRegister's vocabulary register, which
 * the daily refresh keeps in step with the TOOI publication (REQ-WVC-001,
 * REQ-WVC-002); there is no bundled copy. The resolvers fail closed: an input
 * that does not map to a value-list member returns null so the caller omits the
 * axis rather than leaking a literal `@resource`, and an unreadable register
 * throws, so no stale list is ever used.
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

use OCA\OpenCatalogi\Contract\DocumentTypeVocabularyInterface;
use OCA\OpenCatalogi\Contract\OrganisationActivationInterface;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves the DiWoo metadata axes to official TOOI/DiWoo value-list URIs, from the vocabulary register.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
 */
class TooiVocabularyService implements DocumentTypeVocabularyInterface {

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
	 * The activated TOOI organisations, once read in this request: null while activation is not in use.
	 *
	 * @var list<string>|null
	 */
	private ?array $activated = null;

	/**
	 * Whether the activated organisations were read in this request.
	 *
	 * @var bool
	 */
	private bool $activatedRead = false;

	/**
	 * The scheme of the Woo informatiecategorieën in the vocabulary register.
	 *
	 * @var string
	 */
	public const CATEGORIES_SCHEME = 'https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen';

	/**
	 * The scheme of the DiWoo documenthandelingen (soort handeling).
	 *
	 * @var string
	 */
	public const HANDLING_SCHEME = 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen';

	/**
	 * The scheme of the DiWoo documentsoorten.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE_SCHEME = 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten';

	/**
	 * The scheme of the DiWoo talen.
	 *
	 * @var string
	 */
	public const LANGUAGE_SCHEME = 'https://identifier.overheid.nl/tooi/def/thes/xtrn/taal';

	/**
	 * The concepts of each scheme, once read in this request.
	 *
	 * @var array<string, array<string, array{label: string, notation: string, deprecated: bool}>>
	 */
	private array $schemes = [];

	/**
	 * Constructor.
	 *
	 * @param OrganisationActivationInterface|null $activation The organisations the administrator activated (REQ-WVL-005);
	 *                                                         without it every organisation counts as activated.
	 * @param VocabularyRegisterInterface|null     $register   OpenRegister's vocabulary register, which holds the lists;
	 *                                                         without it every list read throws.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ?OrganisationActivationInterface $activation = null,
		private readonly ?VocabularyRegisterInterface $register = null,
	) {
	}//end __construct()

	/**
	 * The sitemap route code of each Woo informatiecategorie (WOO-003), with
	 * the labels a stored record may still carry.
	 *
	 * The route codes are this app's: the TOOI concepts carry no code. Whether
	 * a category exists, and whether it is deprecated, comes from the
	 * vocabulary register: a code whose concept the register does not hold is
	 * left out. `label` is the heading the app shows and a legacy register's
	 * schema titles match (TOOI's `skos:prefLabel` is the singular, lower-case
	 * form and is accepted as a name too); `aliases` are the version 1 and 2
	 * `skos:prefLabel` forms, so a label written into a stored record years ago
	 * keeps resolving. `en` is the English name: the `l10n/` source string the
	 * category routes translate, and the schema's `x-enum-labels` entry, because
	 * the TOOI concepts carry Dutch labels only. Public so a caller without a
	 * service instance (a publication rule's validation) can name a category;
	 * whether the register still holds it is {@see informatiecategorieList()}'s answer.
	 *
	 * @var array<string, array{id: string, label: string, en: string, aliases: array<int, string>}>
	 */
	public const INFORMATIECATEGORIEEN = [
		'infocat001' => [
			'id' => 'c_139c6280',
			'label' => 'Wetten en algemeen verbindende voorschriften',
			'en' => 'Laws and generally binding regulations',
			'aliases' => ['wet of algemeen verbindend voorschrift'],
		],
		'infocat002' => [
			'id' => 'c_aab6bfc7',
			'label' => 'Overige besluiten van algemene strekking',
			'en' => 'Other decisions of general scope',
			'aliases' => ['overig besluit van algemene strekking'],
		],
		'infocat003' => [
			'id' => 'c_759721e2',
			'label' => 'Ontwerpen van wet- en regelgeving met adviesaanvraag',
			'en' => 'Draft legislation sent out for advice',
			'aliases' => ['ontwerp wet- en regelgeving', 'ontwerp van wet- en regelgeving met adviesaanvraag'],
		],
		'infocat004' => ['id' => 'c_40a05794', 'label' => 'Organisatie en werkwijze', 'en' => 'Organisation and working methods', 'aliases' => []],
		'infocat005' => ['id' => 'c_89ee6784', 'label' => 'Bereikbaarheidsgegevens', 'en' => 'Contact details', 'aliases' => []],
		'infocat006' => [
			'id' => 'c_8c840238',
			'label' => 'Bij vertegenwoordigende organen ingekomen stukken',
			'en' => 'Documents received by representative bodies',
			'aliases' => ['bij vertegenwoordigend lichaam ingekomen stuk', 'bij vertegenwoordigend orgaan ingekomen stuk'],
		],
		'infocat007' => [
			'id' => 'c_c76862ab',
			'label' => 'Vergaderstukken Staten-Generaal',
			'en' => 'Meeting documents of the States General',
			'aliases' => ['vergaderstuk staten-generaal'],
		],
		'infocat008' => [
			'id' => 'c_db4862c3',
			'label' => 'Vergaderstukken decentrale overheden',
			'en' => 'Meeting documents of local and regional governments',
			'aliases' => ['vergaderstuk lager vertegenwoordigend of openbaar lichaam', 'vergaderstuk decentrale overheid'],
		],
		'infocat009' => [
			'id' => 'c_3a248e3a',
			'label' => "Agenda's en besluitenlijsten bestuurscolleges",
			'en' => 'Agendas and decision lists of executive boards',
			'aliases' => ['agenda of besluitenlijst bestuurscollege'],
		],
		'infocat010' => ['id' => 'c_99a836c7', 'label' => 'Adviezen', 'en' => 'Advice', 'aliases' => ['advies']],
		'infocat011' => ['id' => 'c_8fc2335c', 'label' => 'Convenanten', 'en' => 'Covenants', 'aliases' => ['convenant']],
		'infocat012' => [
			'id' => 'c_c6cd1213',
			'label' => 'Jaarplannen en jaarverslagen',
			'en' => 'Annual plans and annual reports',
			'aliases' => ['jaarplan of jaarverslag'],
		],
		'infocat013' => [
			'id' => 'c_cf268088',
			'label' => 'Subsidieverplichtingen anders dan met beschikking',
			'en' => 'Subsidy obligations other than by decision',
			'aliases' => ['subsidieverplichting', 'subsidieverplichting anders dan met beschikking'],
		],
		'infocat014' => [
			'id' => 'c_3baef532',
			'label' => 'Woo-verzoeken en -besluiten',
			'en' => 'Woo requests and decisions',
			'aliases' => ['woo-verzoek', 'woo-verzoek of -besluit'],
		],
		'infocat015' => ['id' => 'c_fdaee95e', 'label' => 'Onderzoeksrapporten', 'en' => 'Research reports', 'aliases' => ['onderzoeksrapport']],
		'infocat016' => ['id' => 'c_46a81018', 'label' => 'Beschikkingen', 'en' => 'Individual decisions', 'aliases' => ['beschikking']],
		'infocat017' => ['id' => 'c_a870c43d', 'label' => 'Klachtoordelen', 'en' => 'Complaint rulings', 'aliases' => ['klachtoordeel']],
		'infocat018' => [
			'id' => 'c_816e508d',
			'label' => 'Inspanningsverplichting art 3.1 Woo',
			'en' => 'Best-efforts obligation under art. 3.1 Woo',
			'aliases' => ['inspanningsverplichting art 3.1 woo', 'inspanningsverplichting'],
		],
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
	 * Accepts the sitemap route code (`infocatNNN`), the heading, the register's
	 * label, an earlier label, a bare `c_…` identifier, or a full kern URI.
	 * Returns null when the value maps to no member the register holds (the
	 * caller then omits the axis). The label answered is the heading. A
	 * deprecated member still resolves, so existing records keep their
	 * category; {@see ValueListMembership::acceptsNewValue()} refuses it as a
	 * new value.
	 *
	 * @param string|null $value The publication's category value.
	 *
	 * @return array{uri: string, label: string}|null The resolved member, or null.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function resolveInformatiecategorie(?string $value): ?array {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$needle = mb_strtolower(trim($value));
		$concepts = $this->conceptsOf(scheme: self::CATEGORIES_SCHEME);
		foreach (self::INFORMATIECATEGORIEEN as $code => $entry) {
			$uri = self::KERN_BASE . $entry['id'];
			if (isset($concepts[$uri]) === true && in_array($needle, self::categoryNames(code: $code, concept: $concepts[$uri]), true) === true) {
				return ['uri' => $uri, 'label' => $entry['label']];
			}
		}

		return null;
	}//end resolveInformatiecategorie()

	/**
	 * Every name a category answers to, lower case: its code, id, URI, heading, register label and earlier labels.
	 *
	 * @param string                $code    The route code.
	 * @param array{label: string}  $concept The register's concept.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private static function categoryNames(string $code, array $concept): array {
		$entry = self::INFORMATIECATEGORIEEN[$code];
		$names = array_merge([$code, $entry['id'], self::KERN_BASE . $entry['id'], $entry['label'], $concept['label']], $entry['aliases']);

		return array_map('mb_strtolower', $names);
	}//end categoryNames()

	/**
	 * Resolve a handling-type value to an official DiWoo soortHandeling member.
	 *
	 * Defaults to `ontvangst` when no value is supplied. Returns null when a
	 * supplied value is not a member the register holds.
	 *
	 * @param string|null $value The declared handling type, or null for the default.
	 *
	 * @return array{uri: string, label: string}|null The resolved member, or null.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function resolveSoortHandeling(?string $value): ?array {
		$needle = $this->soortHandelingNeedle(value: $value);
		foreach ($this->conceptsOf(scheme: self::HANDLING_SCHEME) as $uri => $concept) {
			$names = [mb_strtolower($concept['label']), mb_strtolower(self::codeOf(uri: $uri)), mb_strtolower($uri)];
			if (in_array($needle, $names, true) === true) {
				return ['uri' => $uri, 'label' => $concept['label']];
			}
		}

		return null;
	}//end resolveSoortHandeling()

	/**
	 * The declared handling type as it is looked up: trimmed, lower case, and
	 * the default when none is declared.
	 *
	 * @param string|null $value The declared handling type, or null for the default.
	 *
	 * @return string The needle.
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private function soortHandelingNeedle(?string $value): string {
		$needle = mb_strtolower(trim((string)($value ?? self::DEFAULT_SOORTHANDELING)));
		if ($needle === '') {
			return self::DEFAULT_SOORTHANDELING;
		}

		return $needle;
	}//end soortHandelingNeedle()

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
	public function resolveOrganisatie(?string $tooiIdentifier): ?string {
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
	 * Whether a TOOI organisation may be named in DiWoo: activation is not in use, or it is activated.
	 *
	 * Activation is in use once an administrator activated one organisation;
	 * until then every organisation counts as activated, so an upgrade does not
	 * take the publisher out of every existing record. An unreadable choice list
	 * counts as not in use, as for the categories: no organisation is withheld
	 * by an error.
	 *
	 * @param string $uri A TOOI organisatie uri, as resolveOrganisatie() answers it.
	 *
	 * @return bool True when it may be named.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function isActivatedOrganisatie(string $uri): bool {
		if ($this->activatedRead === false) {
			$this->activatedRead = true;
			$this->activated = $this->readActivated();
		}

		return $this->activated === null || in_array($uri, $this->activated, true) === true;
	}//end isActivatedOrganisatie()

	/**
	 * The activated TOOI organisations, or null when activation is not in use or cannot be read.
	 *
	 * @return list<string>|null The identifiers.
	 */
	private function readActivated(): ?array {
		if ($this->activation === null) {
			return null;
		}

		try {
			$activated = $this->activation->activeEntries();
		} catch (\Throwable $e) {
			return null;
		}

		if ($activated === []) {
			return null;
		}

		return $activated;
	}//end readActivated()

	/**
	 * The informatiecategorie value list (code → uri, heading and English name), in code order: the members the register holds.
	 *
	 * @return array<string, array{uri: string, label: string, labelEn: string}> The value list.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/tasks.md#task-2.3
	 */
	public function informatiecategorieList(): array {
		$concepts = $this->conceptsOf(scheme: self::CATEGORIES_SCHEME);
		$out = [];
		foreach (self::INFORMATIECATEGORIEEN as $code => $entry) {
			$uri = self::KERN_BASE . $entry['id'];
			if (isset($concepts[$uri]) === true) {
				$out[$code] = ['uri' => $uri, 'label' => $entry['label'], 'labelEn' => $entry['en']];
			}
		}

		return $out;
	}//end informatiecategorieList()

	/**
	 * Resolve a document type to a member of the DiWoo documentsoorten list.
	 *
	 * Accepts the member's resource URI, its code (`c_…`) or its exact Dutch
	 * label, case-insensitive. Returns null for anything else, so a caller
	 * refuses the value rather than storing or emitting free text.
	 *
	 * @param string|null $value The document type as given.
	 *
	 * @return array{code: string, label: string, uri: string}|null The member, or null.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-11-25-diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function resolveDocumentsoort(?string $value): ?array {
		return $this->resolveMember(list: $this->documentsoortList(), value: $value);
	}//end resolveDocumentsoort()

	/**
	 * The DiWoo documentsoorten list, by label.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-11-25-diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function documentsoortList(): array {
		return $this->membersOf(scheme: self::DOCUMENT_TYPE_SCHEME);
	}//end documentsoortList()

	/**
	 * Resolve a language to a member of the DiWoo language list.
	 *
	 * Accepts the member's resource URI, its code (`NLD`) or its exact Dutch
	 * label, case-insensitive. Returns null for anything else.
	 *
	 * @param string|null $value The language as given.
	 *
	 * @return array{code: string, label: string, uri: string}|null The member, or null.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-11-25-diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function resolveLanguage(?string $value): ?array {
		return $this->resolveMember(list: $this->languageList(), value: $value);
	}//end resolveLanguage()

	/**
	 * The DiWoo language list, by label.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-11-25-diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function languageList(): array {
		return $this->membersOf(scheme: self::LANGUAGE_SCHEME);
	}//end languageList()

	/**
	 * The soortHandeling value list (label → uri), by label.
	 *
	 * @return array<string, string> The value list.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function soortHandelingList(): array {
		$out = [];
		foreach ($this->membersOf(scheme: self::HANDLING_SCHEME) as $member) {
			$out[$member['label']] = $member['uri'];
		}

		return $out;
	}//end soortHandelingList()

	/**
	 * The member of a list a value names by URI, code or label, case-insensitive.
	 *
	 * @param array<int, array{code: string, label: string, uri: string}> $list The members.
	 * @param string|null $value The value as given.
	 *
	 * @return array{code: string, label: string, uri: string}|null The member, or null.
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private function resolveMember(array $list, ?string $value): ?array {
		$needle = mb_strtolower(trim((string)$value));
		if ($needle === '') {
			return null;
		}

		foreach ($list as $member) {
			$names = [mb_strtolower($member['uri']), mb_strtolower($member['code']), mb_strtolower($member['label'])];
			if (in_array($needle, $names, true) === true) {
				return $member;
			}
		}

		return null;
	}//end resolveMember()

	/**
	 * The members of a scheme as `{code, label, uri}`, by label; the code is the last segment of the URI.
	 *
	 * @param string $scheme The scheme's URI.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read.
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private function membersOf(string $scheme): array {
		$members = [];
		foreach ($this->conceptsOf(scheme: $scheme) as $uri => $concept) {
			$members[] = ['code' => self::codeOf(uri: $uri), 'label' => $concept['label'], 'uri' => $uri];
		}

		usort($members, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
		return $members;
	}//end membersOf()

	/**
	 * The last path segment of a concept URI: `c_852da199`, `NLD`.
	 *
	 * @param string $uri The URI.
	 *
	 * @return string The code.
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private static function codeOf(string $uri): string {
		$slash = strrpos($uri, '/');
		if ($slash === false) {
			return $uri;
		}

		return substr($uri, $slash + 1);
	}//end codeOf()

	/**
	 * The concepts of a scheme, read from the vocabulary register once per request.
	 *
	 * @param string $scheme The scheme's URI.
	 *
	 * @return array<string, array{label: string, notation: string, deprecated: bool}> Keyed by concept URI.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read; no bundled copy stands in.
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	private function conceptsOf(string $scheme): array {
		if (isset($this->schemes[$scheme]) === true) {
			return $this->schemes[$scheme];
		}

		if ($this->register === null) {
			throw new RuntimeException('The value lists cannot be read: OpenRegister\'s vocabulary register is not available.');
		}

		try {
			$concepts = $this->register->concepts(schemeUri: $scheme);
		} catch (Throwable $e) {
			throw new RuntimeException('The value list ' . $scheme . ' cannot be read from the vocabulary register.', 0, $e);
		}

		$this->schemes[$scheme] = $concepts;
		return $concepts;
	}//end conceptsOf()
}//end class
