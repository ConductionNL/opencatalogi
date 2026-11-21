<?php

/**
 * The DiWoo fields a publication stores, read for its sitemap document.
 *
 * The creation date, language, validity and document type come from the fields
 * the publication stores (REQ-DWP-001). A record created before those fields
 * were introduced falls back to the old derivation; a newer one does not
 * (REQ-DWP-004).
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
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Reads the stored DiWoo fields of a publication for {@see SitemapDocumentMapper}.
 *
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
 */
class SitemapDocumentFields {

	/**
	 * The two-letter codes the publication schema stores, as the DiWoo language list's three-letter codes.
	 */
	private const LANGUAGE_CODES = ['nl' => 'NLD', 'en' => 'ENG', 'de' => 'DEU', 'fr' => 'FRA', 'fy' => 'FRY'];

	/**
	 * Constructor.
	 *
	 * @param TooiVocabularyService $tooiVocabulary The bundled document type and language lists.
	 * @param InstanceSettings|null $instance Holds the moment the DiWoo fields were introduced; without it every record is legacy.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly ?InstanceSettings $instance = null,
	) {
	}//end __construct()

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
	public function documentTypeElement(array $publication, array $file): array|false|null {
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
	public function isLegacy(array $publication): bool {
		$introduced = strtotime((string)$this->instance?->stringValue(SitemapDocumentMapper::FIELDS_INTRODUCED_AT, ''));
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
	private function validityElement(array $publication): ?array {
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
	 * The axes with the document type left out when none is stored or it resolves to no member.
	 *
	 * @param array<string, array|null> $elements The optional axes.
	 *
	 * @return array<string, array|null> The axes, ready to emit.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	public function withoutUnresolvedType(array $elements): array {
		if (is_array($elements['diwoo:classificatiecollectie'] ?? null) === true
			&& is_array($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten'] ?? null) === false
		) {
			unset($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten']);
		}

		return $elements;
	}//end withoutUnresolvedType()

	/**
	 * The stored creation date, language and validity of a publication's document, each null when absent.
	 *
	 * @param array $publication The publication.
	 *
	 * @return array{'diwoo:creatiedatum': string|null, 'diwoo:language': array|null, 'diwoo:geldigheid': array|null} The elements.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	public function storedElements(array $publication): array {
		$legacy = $this->isLegacy(publication: $publication);

		return [
			'diwoo:creatiedatum' => $this->creationDay(publication: $publication, legacy: $legacy),
			'diwoo:language' => $this->languageElement(publication: $publication, legacy: $legacy),
			'diwoo:geldigheid' => $this->validityElement(publication: $publication),
		];
	}//end storedElements()

	/**
	 * The responsible organisation element, while it has a name and a TOOI URI: the
	 * publisher, or the organisation the publication names as responsible (REQ-PRO-001).
	 *
	 * @param array $publisher The organisation element.
	 *
	 * @return array|null The verantwoordelijke element, or null.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	public function responsible(array $publisher): ?array {
		if (isset($publisher['@resource']) === false || ($publisher['#text'] ?? '') === '') {
			return null;
		}

		return $publisher;
	}//end responsible()

	/**
	 * What the stored fields leave missing: a creation date, or a document type that resolves to no member.
	 *
	 * @param array<string, mixed> $diwoo The document's core elements.
	 * @param array<string, array|null> $elements The optional axes.
	 *
	 * @return array<int, array{0: string, 1: string}> The gaps as axis and reason.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	public function storedGaps(array $diwoo, array $elements): array {
		$gaps = [];
		if (isset($diwoo['diwoo:creatiedatum']) === false) {
			$gaps[] = ['creatiedatum', 'publication has no creation date'];
		}

		if (($elements['diwoo:classificatiecollectie']['diwoo:documentsoorten'] ?? null) === false) {
			$gaps[] = ['documentsoort', 'document type resolves to no member of the documentsoorten waardelijst'];
		}

		return $gaps;
	}//end storedGaps()

	/**
	 * A date or time as an xs:dateTime in UTC; a value without a zone is read as UTC.
	 *
	 * @param string $value The value.
	 *
	 * @return string The moment, or the epoch when it does not parse.
	 */
	private static function utcDateTime(string $value): string {
		$utc = new DateTimeZone('UTC');
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
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()
}//end class
