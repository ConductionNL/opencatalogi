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
		'diwoo:classificatiecollectie' => ['informatiecategorie', 'category resolves to no member of the informatiecategorieen waardelijst'],
		'diwoo:documenthandelingen' => ['soortHandeling', 'handling type does not resolve to a DiWoo value-list member'],
	];

	/**
	 * Per-request cache of resolved organisation → TOOI identifier lookups.
	 *
	 * @var array<string, string|null>
	 */
	private array $orgTooiCache = [];

	/**
	 * Constructor.
	 *
	 * @param TooiVocabularyService $tooiVocabulary Resolves organisations and handling types to TOOI.
	 * @param WooCategoryRegistry   $categories     Resolves a publication's category.
	 * @param OpenRegisterLookup    $openRegister   Reaches OpenRegister for the organisation lookup.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly WooCategoryRegistry $categories,
		private readonly OpenRegisterLookup $openRegister,
	) {
	}//end __construct()

	/**
	 * Maps publication + file metadata into a DIWOO Document XML array structure.
	 *
	 * Used to generate items inside diwoo:Documents.
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
	 * @return array A DIWOO metadata array ready for XMLResponse
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function map(array $publication, array $file, array &$violations = []): array {
		$loc = ($file['downloadUrl'] ?? '');
		$diwoo = $this->diwooCore(publication: $publication, file: $file);
		if (isset($diwoo['diwoo:publisher']['@resource']) === false) {
			$this->addViolation(violations: $violations, loc: $loc, axis: 'publisher', reason: 'organisation has no TOOI identifier');
		}

		// Each optional axis is its element, or null with the violation that omits it.
		foreach ($this->optionalAxes(publication: $publication, file: $file) as $key => $element) {
			if ($element !== null) {
				$diwoo[$key] = $element;
				continue;
			}

			$this->addViolation(violations: $violations, loc: $loc, axis: self::OPTIONAL_AXES[$key][0], reason: self::OPTIONAL_AXES[$key][1]);
		}

		return ['diwoo:Document' => ['diwoo:DiWoo' => $diwoo]];
	}//end map()

	/**
	 * The DiWoo location, dates, publisher and format of one file's document.
	 *
	 * @param array $publication The publication.
	 * @param array $file        The file.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function diwooCore(array $publication, array $file): array {
		$created = ($publication['@self']['created'] ?? 'now');
		$published = ($file['published'] ?? $publication['@self']['updated'] ?? 'now');

		return [
			'loc' => ($file['downloadUrl'] ?? ''),
			'lastmod' => date('Y-m-d H:i:s', strtotime($published)),
			'diwoo:creatiedatum' => date('Y-m-d', strtotime($created)),
			'diwoo:publisher' => $this->publisherElement(publication: $publication, file: $file),
			'diwoo:format' => [
				'@resource' => 'http://publications.europa.eu/resource/authority/file-type/' . strtoupper($file['extension']),
				'#text' => strtolower($file['extension']),
			],
		];
	}//end diwooCore()

	/**
	 * The optional axes of one file's document, each its element or null.
	 *
	 * Diwoo:atTime is the moment the document was made public: the file's own
	 * published timestamp, then the publication's publicationDate. The object-level
	 * `@self.published` is always empty for magic-mapped publications, so it is not read.
	 *
	 * @param array $publication The publication.
	 * @param array $file        The file.
	 *
	 * @return array<string, array|null>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function optionalAxes(array $publication, array $file): array {
		$handlingTime = ($file['published'] ?? $publication['publicationDate'] ?? date('Y-m-d H:i:s'));
		return [
			'diwoo:classificatiecollectie' => $this->categoryElement(publication: $publication),
			'diwoo:documenthandelingen' => $this->handlingElement(publication: $publication, handlingTime: $handlingTime),
		];
	}//end optionalAxes()

	/**
	 * The publisher element: the owner as text, with `@resource` bound to a valid
	 * TOOI organisatie URI, or without `@resource` when there is none.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 * @param array $file        The file metadata belonging to that publication
	 *
	 * @return array{'#text': mixed, '@resource'?: string} The publisher element.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function publisherElement(array $publication, array $file): array {
		$publisher = ['#text' => ($file['owner'] ?? $publication['@self']['owner'] ?? '')];
		$orgUri = $this->tooiVocabulary->resolveOrganisatie($this->resolveOrganisationTooiIdentifier(publication: $publication));
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
	 * @param array  $publication  The publication as returned from ObjectService
	 * @param mixed  $handlingTime The moment the document was made public.
	 *
	 * @return array|null The documenthandelingen element, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function handlingElement(array $publication, mixed $handlingTime): ?array {
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
	 * that is already a TOOI id URI, and finally the organisation object's own
	 * `tooiIdentifier` property (resolved once per organisation via OpenRegister and
	 * cached for the request). Returns null when none resolves.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 *
	 * @return string|null The candidate TOOI organisatie identifier, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function resolveOrganisationTooiIdentifier(array $publication): ?string {
		$direct = $this->stringOrNull(value: ($publication['tooiIdentifier'] ?? null));
		$organisation = $this->stringOrNull(value: ($publication['@self']['organisation'] ?? null));
		if ($direct !== null || $organisation === null) {
			return $direct;
		}

		// Already a TOOI id URI on the publication envelope.
		if (str_starts_with($organisation, TooiVocabularyService::ORG_ID_BASE) === true) {
			return $organisation;
		}

		if (array_key_exists($organisation, $this->orgTooiCache) === false) {
			$this->orgTooiCache[$organisation] = $this->fetchOrganisationTooiIdentifier(organisation: $organisation);
		}

		return $this->orgTooiCache[$organisation];
	}//end resolveOrganisationTooiIdentifier()

	/**
	 * Fetch an organisation's `tooiIdentifier` via OpenRegister by its UUID.
	 *
	 * @param string $organisation The organisation UUID.
	 *
	 * @return string|null The tooiIdentifier, or null when unresolved/unavailable.
	 *
	 * @spec exclude Object-lookup plumbing for the DIWOO publisher binding.
	 */
	private function fetchOrganisationTooiIdentifier(string $organisation): ?string {
		try {
			$objectService = $this->openRegister->installedService('OCA\OpenRegister\Service\ObjectService', 'OpenRegister service is not available.');
			$results = $objectService->searchObjects(query: ['@self' => ['uuid' => $organisation]], _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($results) === false || empty($results) === true) {
			return null;
		}

		return $this->stringOrNull(value: (self::rowData(row: $results[0])['tooiIdentifier'] ?? null));
	}//end fetchOrganisationTooiIdentifier()

	/**
	 * A search row as an array: itself, its jsonSerialize(), or empty.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function rowData(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return [];
	}//end rowData()
}//end class
