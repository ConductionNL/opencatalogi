<?php

/**
 * Maps one file of a publication to a DiWoo document.
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
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\OpenCatalogi\Contract\DiwooCompletenessInterface;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;

/**
 * The DiWoo document of one downloadable file: location, dates, publisher, format
 * and the optional classification and handling axes, with a violation for every
 * axis that does not resolve.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class SitemapDocumentMapper implements DiwooCompletenessInterface {

	/**
	 * The optional DiWoo axes: the element key, the violation axis and the reason it is omitted.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const OPTIONAL_AXES = [
		'diwoo:titelcollectie' => ['officieleTitel', 'publication has no title'],
		'diwoo:classificatiecollectie' => ['informatiecategorie', 'category resolves to no member of the informatiecategorieen waardelijst'],
		'diwoo:documenthandelingen' => ['soortHandeling', 'handling type does not resolve to a DiWoo value-list member'],
	];

	/**
	 * The reported axes whose absence does not hold a document back: the element is optional in the XSD.
	 */
	private const OPTIONAL_GAPS = ['documentsoort'];

	/**
	 * The app-config key holding the moment the DiWoo fields were introduced (written by the repair step).
	 */
	public const FIELDS_INTRODUCED_AT = 'diwoo_fields_introduced_at';

	/**
	 * The two-letter codes the publication schema stores, as the DiWoo language list's three-letter codes.
	 */
	private const LANGUAGE_CODES = ['nl' => 'NLD', 'en' => 'ENG', 'de' => 'DEU', 'fr' => 'FRA', 'fy' => 'FRY'];

	/**
	 * Per-request cache of organisation rows, by organisation uuid; an empty array when not found.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $organisationCache = [];

	/**
	 * Constructor.
	 *
	 * @param TooiVocabularyService $tooiVocabulary Resolves organisations and handling types to TOOI.
	 * @param WooCategoryRegistry $categories Resolves a publication's category.
	 * @param OpenRegisterLookup $openRegister Reaches OpenRegister for the organisation lookup.
	 * @param InstanceSettings|null $instance Holds the moment the DiWoo fields were introduced; without it every record is legacy.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly WooCategoryRegistry $categories,
		private readonly OpenRegisterLookup $openRegister,
		private readonly ?InstanceSettings $instance = null,
	) {
	}//end __construct()

	/**
	 * Maps publication + file metadata into one `url` entry of a DiWoo sitemap.
	 *
	 * The entry holds `loc`, `lastmod` and `diwoo:Document`, in the order the
	 * sitemaps.org `url` type requires.
	 *
	 * The three Woo-index-controlled axes — informatiecategorie, publisher
	 * `@resource`, and soortHandeling — are resolved through the bundled TOOI/DiWoo
	 * value lists ({@see TooiVocabularyService}). An axis that cannot resolve to an
	 * official URI is OMITTED (never emitted as a free-text `@resource`) and a
	 * `{documentLoc, axis, reason}` violation is appended to `$violations`, so the
	 * national Woo-index (KOOP) never rejects the document on ingest (WOO-TOOI-001..004).
	 *
	 * @param array $publication The publication as returned from ObjectService
	 * @param array $file The file metadata belonging to that publication
	 * @param array<int, array<string, string>> $violations Per-document unresolved-axis report (by reference)
	 * @param array<string, array<string, mixed>> $relations The document's `isPartOf` or `hasParts` element, if any ({@see SitemapDocumentRelations}).
	 * @param bool $omitIncomplete Whether a document missing a mandatory element is left out (null) rather than emitted.
	 *
	 * @return array{loc: string, lastmod: string, 'diwoo:Document': array}|null The url entry, ready for XMLResponse.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md
	 */
	public function map(array $publication, array $file, array &$violations = [], array $relations = [], bool $omitIncomplete = false): ?array {
		$loc = ($file['downloadUrl'] ?? '');
		$diwoo = $this->diwooCore(publication: $publication, file: $file);
		// Each optional axis is its element, or null with the violation that omits it.
		$elements = $this->optionalAxes(publication: $publication, file: $file);
		$gaps = $this->axisGaps(diwoo: $diwoo, elements: $elements);
		foreach ($gaps as $gap) {
			$this->addViolation(violations: $violations, loc: $loc, axis: $gap[0], reason: $gap[1]);
		}

		// A document the Woo-index would refuse is held back; the rest of the page is served (REQ-DWP-004).
		if ($omitIncomplete === true && array_diff(array_column($gaps, 0), self::OPTIONAL_GAPS) !== []) {
			return null;
		}

		$elements = self::withoutUnresolvedType(elements: $elements);
		$diwoo = array_merge($diwoo, array_filter($elements, static fn (?array $element): bool => $element !== null), $relations);

		return [
			'loc' => $loc,
			'lastmod' => self::lastModified(publication: $publication, file: $file),
			'diwoo:Document' => ['diwoo:DiWoo' => $diwoo],
		];
	}//end map()

	/**
	 * The mandatory DiWoo elements a publication cannot fill, keyed by the publication
	 * field an editor sets, each with the reason (REQ-DWP-003).
	 *
	 * The mandatory set is the one the DiWoo metadata XSD requires under `diwoo:DiWoo`
	 * (tests/fixtures/diwoo/0.9.8-mandatory-elements.json): the publisher, the official
	 * title, the informatiecategorie and the documenthandeling. `atTime` is always
	 * filled from the publication date. The same resolution as {@see map()} decides,
	 * so a publication this accepts is one the sitemap can emit.
	 *
	 * @param array<string, mixed> $publication The publication, with `@self.organisation`.
	 *
	 * @return array<string, string> The missing fields and why, empty when complete.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication
	 */
	public function mandatoryGaps(array $publication): array {
		$gaps = [];
		$publisher = $this->publisherElement(publication: $publication);
		if ($publisher['#text'] === '') {
			$gaps['publisher'] = 'organisation has no name';
		}

		if (isset($publisher['@resource']) === false) {
			$gaps['publisher'] = 'organisation has no TOOI identifier';
		}

		if ($this->titleElement(publication: $publication) === null) {
			$gaps['title'] = self::OPTIONAL_AXES['diwoo:titelcollectie'][1];
		}

		if ($this->categoryElement(publication: $publication) === null) {
			$gaps['wooCategory'] = self::OPTIONAL_AXES['diwoo:classificatiecollectie'][1];
		}

		if ($this->handlingElement(publication: $publication, handlingTime: '') === null) {
			$gaps['soortHandeling'] = self::OPTIONAL_AXES['diwoo:documenthandelingen'][1];
		}

		return $gaps;
	}//end mandatoryGaps()

	/**
	 * When the document last changed: the latest of the file's modified time,
	 * the file's published time and the publication's last update, so a change
	 * to the publication's metadata alone moves every document's `lastmod`
	 * (REQ-WSD-001). With none of the three, now.
	 *
	 * @param array $publication The publication.
	 * @param array $file The file.
	 *
	 * @return string The moment in DATE_ATOM form, UTC.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-documents-lastmod-follows-its-metadata-as-well-as-its-file-req-wsd-001
	 */
	private static function lastModified(array $publication, array $file): string {
		$moments = [];
		foreach ([($file['modified'] ?? null), ($file['published'] ?? null), ($publication['@self']['updated'] ?? null)] as $value) {
			// A stored value that is not a date still counts, as the epoch (utcDateTime()).
			if ($value !== null && $value !== '') {
				$moments[] = self::utcDateTime(value: $value);
			}
		}

		if ($moments === []) {
			return self::utcDateTime(value: 'now');
		}

		// DATE_ATOM in UTC sorts as text in time order.
		return max($moments);
	}//end lastModified()

	/**
	 * What the document is missing, as axis and reason pairs: the publisher's
	 * gaps first, then each optional axis that resolved to nothing, in order.
	 *
	 * @param array<string, mixed> $diwoo The core DiWoo element.
	 * @param array<string, array|null> $elements The optional axes' elements, null when omitted.
	 *
	 * @return array<int, array{0: string, 1: string}> The gaps.
	 */
	private function axisGaps(array $diwoo, array $elements): array {
		$gaps = [];
		if (isset($diwoo['diwoo:publisher']['@resource']) === false) {
			$gaps[] = ['publisher', 'organisation has no TOOI identifier'];
		}

		if ($diwoo['diwoo:publisher']['#text'] === '') {
			$gaps[] = ['publisher', 'organisation has no name'];
		}

		foreach (array_keys(array_filter($elements, static fn (?array $element): bool => $element === null)) as $key) {
			$gaps[] = self::OPTIONAL_AXES[$key];
		}

		if (isset($diwoo['diwoo:creatiedatum']) === false) {
			$gaps[] = ['creatiedatum', 'publication has no creation date'];
		}

		$documentsoorten = ($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten'] ?? null);
		if ($documentsoorten === false) {
			$gaps[] = ['documentsoort', 'document type resolves to no member of the documentsoorten waardelijst'];
		}

		return $gaps;

	}//end axisGaps()

	/**
	 * The DiWoo creation date, publisher, responsible organisation, format, language
	 * and validity of one file's document, from the fields the publication stores.
	 *
	 * @param array $publication The publication.
	 * @param array $file The file.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function diwooCore(array $publication, array $file): array {
		$legacy = $this->isLegacy(publication: $publication);
		$publisher = $this->publisherElement(publication: $publication);
		$core = [];
		$created = $this->creationDay(publication: $publication, legacy: $legacy);
		if ($created !== null) {
			$core['diwoo:creatiedatum'] = $created;
		}

		$core['diwoo:publisher'] = $publisher;
		// The responsible organisation is the publisher until it is stored apart.
		if (isset($publisher['@resource']) === true && $publisher['#text'] !== '') {
			$core['diwoo:verantwoordelijke'] = $publisher;
		}

		$core['diwoo:format'] = [
			'@resource' => 'http://publications.europa.eu/resource/authority/file-type/' . strtoupper($file['extension']),
			'#text' => strtolower($file['extension']),
		];

		$language = $this->languageElement(publication: $publication, legacy: $legacy);
		if ($language !== null) {
			$core['diwoo:language'] = $language;
		}

		$validity = self::validityElement(publication: $publication);
		if ($validity !== null) {
			$core['diwoo:geldigheid'] = $validity;
		}

		return $core;
	}//end diwooCore()

	/**
	 * The optional axes of one file's document, each its element or null.
	 *
	 * Diwoo:atTime is the moment the document was made public: the file's own
	 * published timestamp, then the publication's publicationDate. The object-level
	 * `@self.published` is always empty for magic-mapped publications, so it is not read.
	 *
	 * @param array $publication The publication.
	 * @param array $file The file.
	 *
	 * @return array<string, array|null>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function optionalAxes(array $publication, array $file): array {
		$handlingTime = self::utcDateTime(value: ($file['published'] ?? $publication['publicationDate'] ?? 'now'));
		$classification = $this->categoryElement(publication: $publication);
		if ($classification !== null) {
			$classification['diwoo:documentsoorten'] = $this->documentTypeElement(publication: $publication, file: $file);
		}

		return [
			'diwoo:titelcollectie' => $this->titleElement(publication: $publication),
			'diwoo:classificatiecollectie' => $classification,
			'diwoo:documenthandelingen' => $this->handlingElement(publication: $publication, handlingTime: $handlingTime),
		];
	}//end optionalAxes()

	/**
	 * The publisher element: the owning organisation's name as text, with `@resource`
	 * bound to a valid TOOI organisatie URI, or without `@resource` when there is none.
	 *
	 * The text is never a user: no owner id and no display name reach the public
	 * sitemap. Without an organisation name it is empty, and map() reports it.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 *
	 * @return array{'#text': string, '@resource'?: string} The publisher element.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function publisherElement(array $publication): array {
		$organisation = $this->organisationRow(publication: $publication);
		$publisher = ['#text' => trim((string)$this->stringOrNull(value: ($organisation['name'] ?? null)))];
		$tooiIdentifier = $this->resolveOrganisationTooiIdentifier(publication: $publication, organisation: $organisation);
		$orgUri = $this->tooiVocabulary->resolveOrganisatie($tooiIdentifier);
		if ($orgUri !== null) {
			$publisher['@resource'] = $orgUri;
		}

		return $publisher;
	}//end publisherElement()

	/**
	 * The informatiecategorie element, bound to an official TOOI category URI,
	 * or null when the publication's category resolves to none.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 *
	 * @return array|null The classificatiecollectie element, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function categoryElement(array $publication): ?array {
		$categoryRaw = ($publication['wooCategory'] ?? $publication['category'] ?? $publication['tooiCategorieUri']
			?? $publication['tooiCategorieNaam'] ?? null);
		$category = $this->categories->resolveForDocument($this->stringOrNull(value: $categoryRaw));
		if ($category === null) {
			return null;
		}

		return [
			'diwoo:informatiecategorieen' => [
				'diwoo:informatiecategorie' => [
					'#text' => $category['label'],
					'@resource' => $category['uri'],
				],
			],
		];
	}//end categoryElement()

	/**
	 * The documenthandeling element, resolved through the DiWoo value list
	 * (default: ontvangst), or null when the handling type resolves to none.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 * @param string $handlingTime The moment the document was made public, as an xs:dateTime.
	 *
	 * @return array|null The documenthandelingen element, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function handlingElement(array $publication, string $handlingTime): ?array {
		$handling = $this->tooiVocabulary->resolveSoortHandeling($this->stringOrNull(value: $publication['soortHandeling'] ?? null));
		if ($handling === null) {
			return null;
		}

		return [
			'diwoo:documenthandeling' => [
				'diwoo:soortHandeling' => [
					'#text' => $handling['label'],
					'@resource' => $handling['uri'],
				],
				'diwoo:atTime' => $handlingTime,
			],
		];
	}//end handlingElement()

	/**
	 * The axes with the document type left out when none is stored or it resolves to no member.
	 *
	 * @param array<string, array|null> $elements The optional axes.
	 *
	 * @return array<string, array|null> The axes, ready to emit.
	 */
	private static function withoutUnresolvedType(array $elements): array {
		if (is_array($elements['diwoo:classificatiecollectie'] ?? null) === true
			&& is_array($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten'] ?? null) === false
		) {
			unset($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten']);
		}

		return $elements;
	}//end withoutUnresolvedType()

	/**
	 * The documentsoorten element: the file's own type first, else the publication's (REQ-DWP-002).
	 *
	 * @param array $publication The publication.
	 * @param array $file The file.
	 *
	 * @return array|false|null The element, false when the stored type resolves to no member, null when none is stored.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	private function documentTypeElement(array $publication, array $file): array|false|null {
		$perFile = $publication['fileDocumentsoorten'] ?? [];
		$fileId = (string)($file['id'] ?? '');
		$value = $this->stringOrNull(value: $publication['documentsoort'] ?? null);
		if (is_array($perFile) === true && $fileId !== '' && $this->stringOrNull(value: $perFile[$fileId] ?? null) !== null) {
			$value = $perFile[$fileId];
		}

		if ($value === null) {
			return null;
		}

		$member = $this->tooiVocabulary->resolveDocumentsoort($value);
		if ($member === null) {
			return false;
		}

		return ['diwoo:documentsoort' => ['#text' => $member['label'], '@resource' => $member['uri']]];
	}//end documentTypeElement()

	/**
	 * Whether the publication was created before the DiWoo fields were introduced.
	 *
	 * Before the repair step has recorded that moment every record is legacy. A
	 * record without a creation moment is legacy too: it cannot be newer.
	 *
	 * @param array $publication The publication.
	 *
	 * @return bool True when the derivation may fill a missing field.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	private function isLegacy(array $publication): bool {
		$introduced = strtotime((string)$this->instance?->stringValue(self::FIELDS_INTRODUCED_AT, ''));
		$created = strtotime((string)($publication['@self']['created'] ?? ''));
		if ($introduced === false || $created === false) {
			return true;
		}

		return $created < $introduced;
	}//end isLegacy()

	/**
	 * The day the document was made: the stored creationDate, else for a legacy record the day it was saved.
	 *
	 * @param array $publication The publication.
	 * @param bool $legacy Whether the record predates the DiWoo fields.
	 *
	 * @return string|null The day, or null when there is none.
	 */
	private function creationDay(array $publication, bool $legacy): ?string {
		$stored = $this->stringOrNull(value: $publication['creationDate'] ?? null);
		if ($stored !== null && strtotime($stored) !== false) {
			return gmdate('Y-m-d', (int)strtotime($stored));
		}

		if ($legacy === false) {
			return null;
		}

		return date('Y-m-d', (int)strtotime((string)($publication['@self']['created'] ?? 'now')));
	}//end creationDay()

	/**
	 * The language element from the stored language, else nl for a legacy record.
	 *
	 * The schema stores a two-letter code (`nl`), the DiWoo list a three-letter one
	 * (`NLD`): the common two-letter codes are mapped, anything else resolves as given.
	 *
	 * @param array $publication The publication.
	 * @param bool $legacy Whether the record predates the DiWoo fields.
	 *
	 * @return array|null The element, or null when there is none or it resolves to no member.
	 */
	private function languageElement(array $publication, bool $legacy): ?array {
		$value = $this->stringOrNull(value: $publication['language'] ?? null);
		if ($value === null && $legacy === true) {
			$value = 'nl';
		}

		if ($value === null) {
			return null;
		}

		$member = $this->tooiVocabulary->resolveLanguage(self::LANGUAGE_CODES[strtolower(trim($value))] ?? $value);
		if ($member === null) {
			return null;
		}

		return ['#text' => $member['label'], '@resource' => $member['uri']];
	}//end languageElement()

	/**
	 * The geldigheid element from the stored validFrom and validUntil, or null when neither is stored.
	 *
	 * @param array $publication The publication.
	 *
	 * @return array|null The element.
	 */
	private static function validityElement(array $publication): ?array {
		$validity = [];
		foreach (['diwoo:begindatum' => 'validFrom', 'diwoo:einddatum' => 'validUntil'] as $element => $field) {
			$value = $publication[$field] ?? null;
			if (is_string($value) === true && trim($value) !== '' && strtotime($value) !== false) {
				$validity[$element] = self::utcDateTime(value: $value);
			}
		}

		if ($validity === []) {
			return null;
		}

		return $validity;
	}//end validityElement()

	/**
	 * The titelcollectie element with the publication's title as officieleTitel, or null without a title.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 *
	 * @return array|null The titelcollectie element, or null.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	private function titleElement(array $publication): ?array {
		$title = $this->stringOrNull(value: ($publication['title'] ?? null));
		if ($title === null) {
			return null;
		}

		return ['diwoo:officieleTitel' => trim($title)];
	}//end titleElement()

	/**
	 * A date or time as an xs:dateTime in UTC; a value without a zone is read as UTC.
	 *
	 * A value that does not parse gives the epoch, as strtotime() did before.
	 *
	 * @param mixed $value The stored date or time.
	 *
	 * @return string The moment in DATE_ATOM form, such as 2024-02-02T08:00:00+00:00.
	 *
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	private static function utcDateTime(mixed $value): string {
		$utc = new DateTimeZone('UTC');
		if (is_string($value) === false) {
			$value = '@0';
		}

		try {
			$moment = new DateTimeImmutable($value, $utc);
		} catch (Exception $e) {
			$moment = new DateTimeImmutable('@0', $utc);
		}

		return $moment->setTimezone($utc)->format(DATE_ATOM);
	}//end utcDateTime()

	/**
	 * Return the value as a string, or null when it is not a non-empty string.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return string|null The string, or null.
	 *
	 * @spec exclude Type-narrowing plumbing.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Append a DIWOO value-list violation record.
	 *
	 * @param array<int, array<string, string>> $violations The violation list (by reference).
	 * @param string $loc The document location (download URL).
	 * @param string $axis The unresolved axis.
	 * @param string $reason The human-readable reason.
	 *
	 * @return void
	 *
	 * @spec exclude Violation-record plumbing for the DIWOO validator.
	 */
	private function addViolation(array &$violations, string $loc, string $axis, string $reason): void {
		$violations[] = ['documentLoc' => $loc, 'axis' => $axis, 'reason' => $reason];

	}//end addViolation()

	/**
	 * Resolve the TOOI organisatie identifier for a publication's owning organisation.
	 *
	 * Prefers a publication-level `tooiIdentifier`, then a `@self.organisation` value
	 * that is already a TOOI id URI, and finally the TOOI identifier of the
	 * OpenRegister organisation. Returns null when none resolves.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 * @param array<string, mixed> $organisation The organisation row, empty when there is none.
	 *
	 * @return string|null The candidate TOOI organisatie identifier, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function resolveOrganisationTooiIdentifier(array $publication, array $organisation): ?string {
		$direct = $this->stringOrNull(value: ($publication['tooiIdentifier'] ?? null));
		$envelope = $this->stringOrNull(value: ($publication['@self']['organisation'] ?? null));
		if ($direct !== null || $envelope === null) {
			return $direct;
		}

		// Already a TOOI id URI on the publication envelope.
		if (str_starts_with($envelope, TooiVocabularyService::ORG_ID_BASE) === true) {
			return $envelope;
		}

		return $this->stringOrNull(value: ($organisation['tooiIdentifier'] ?? null));
	}//end resolveOrganisationTooiIdentifier()

	/**
	 * The publication's organisation row, looked up once per organisation uuid for the
	 * request; empty when the envelope names none, names a TOOI id, or nothing is found.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function organisationRow(array $publication): array {
		$organisation = $this->stringOrNull(value: ($publication['@self']['organisation'] ?? null));
		if ($organisation === null || str_starts_with($organisation, TooiVocabularyService::ORG_ID_BASE) === true) {
			return [];
		}

		if (array_key_exists($organisation, $this->organisationCache) === false) {
			$this->organisationCache[$organisation] = $this->fetchOrganisation(organisation: $organisation);
		}

		return $this->organisationCache[$organisation];
	}//end organisationRow()

	/**
	 * Read an OpenRegister organisation's name and TOOI identifier by its UUID.
	 *
	 * `@self.organisation` names an OpenRegister organisation, not an object, so it
	 * is read through the OrganisationMapper. Only the name and the TOOI identifier
	 * leave this method.
	 *
	 * @param string $organisation The organisation UUID.
	 *
	 * @return array{name?: mixed, tooiIdentifier?: mixed} The organisation, or empty when unresolved or unavailable.
	 *
	 * @spec exclude Organisation-lookup plumbing for the DIWOO publisher binding.
	 */
	private function fetchOrganisation(string $organisation): array {
		try {
			$found = $this->openRegister
				->installedService('OCA\OpenRegister\Db\OrganisationMapper', 'OpenRegister organisations are not available.')
				->findByUuidFollowingMerge($organisation);

			return ['name' => $found->getName(), 'tooiIdentifier' => $found->getTooi()];
		} catch (\Throwable $e) {
			return [];
		}
	}//end fetchOrganisation()
}//end class
