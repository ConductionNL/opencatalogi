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
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;

/**
 * The DiWoo document of one downloadable file: location, dates, publisher, format
 * and the optional classification and handling axes, with a violation for every
 * axis that does not resolve.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class SitemapDocumentMapper {

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
	 * @param FileFacts|null $fileFacts Reads each file's type from its bytes (REQ-PFF-001); without it no format is emitted.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly WooCategoryRegistry $categories,
		private readonly OpenRegisterLookup $openRegister,
		private readonly ?FileFacts $fileFacts = null,
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
	 *
	 * @return array{loc: string, lastmod: string, 'diwoo:Document': array} The url entry, ready for XMLResponse.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 * @spec openspec/changes/diwoo-sitemap-structure/specs/woo-compliance/spec.md
	 */
	public function map(array $publication, array $file, array &$violations = []): array {
		$loc = ($file['downloadUrl'] ?? '');
		$diwoo = $this->diwooCore(publication: $publication, file: $file);
		$format = $this->formatOf(file: $file);
		if ($format['element'] !== null) {
			$diwoo['diwoo:format'] = $format['element'];
		}

		if ($format['reason'] !== null) {
			$this->addViolation(violations: $violations, loc: $loc, axis: 'format', reason: $format['reason']);
		}

		// Each optional axis is its element, or null with the violation that omits it.
		$elements = $this->optionalAxes(publication: $publication, file: $file);
		foreach ($this->axisGaps(diwoo: $diwoo, elements: $elements) as $gap) {
			$this->addViolation(violations: $violations, loc: $loc, axis: $gap[0], reason: $gap[1]);
		}

		$diwoo = array_merge($diwoo, array_filter($elements, static fn (?array $element): bool => $element !== null));

		return [
			'loc' => $loc,
			'lastmod' => self::utcDateTime(value: ($file['published'] ?? $publication['@self']['updated'] ?? 'now')),
			'diwoo:Document' => ['diwoo:DiWoo' => $diwoo],
		];
	}//end map()

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

		return $gaps;

	}//end axisGaps()

	/**
	 * The DiWoo creation date, publisher and format of one file's document.
	 *
	 * @param array $publication The publication.
	 * @param array $file The file.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function diwooCore(array $publication, array $file): array {
		$created = ($publication['@self']['created'] ?? 'now');

		return [
			'diwoo:creatiedatum' => date('Y-m-d', strtotime($created)),
			'diwoo:publisher' => $this->publisherElement(publication: $publication),
		];
	}//end diwooCore()

	/**
	 * The DiWoo format of a file, taken from its bytes, never from its name
	 * (REQ-PFF-001), and why it is missing or doubtful.
	 *
	 * Unreadable bytes give no format and the reason `type-unreadable`. A
	 * format that differs from the one the file's extension claims is still
	 * emitted, with the reason `type-mismatch`.
	 *
	 * @param array $file The file metadata, with its Nextcloud `id`.
	 *
	 * @return array{element: array{'@resource': string, '#text': string}|null, reason: string|null}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FileFacts::diwooFormat() is a pure lookup.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
	 */
	private function formatOf(array $file): array {
		$mimeType = $this->fileFacts?->mimeTypeOfListedFile(file: $file);
		if ($mimeType === null) {
			return ['element' => null, 'reason' => 'type-unreadable'];
		}

		$code = FileFacts::diwooFormat(mimeType: $mimeType);
		if ($code === null) {
			return ['element' => null, 'reason' => 'type-unlisted'];
		}

		$element = [
			'@resource' => 'http://publications.europa.eu/resource/authority/file-type/' . $code,
			'#text' => strtolower($code),
		];
		$claimed = strtolower(trim((string)($file['extension'] ?? '')));
		if ($claimed !== strtolower($code)) {
			return ['element' => $element, 'reason' => 'type-mismatch'];
		}

		return ['element' => $element, 'reason' => null];
	}//end formatOf()

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
		return [
			'diwoo:titelcollectie' => $this->titleElement(publication: $publication),
			'diwoo:classificatiecollectie' => $this->categoryElement(publication: $publication),
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
