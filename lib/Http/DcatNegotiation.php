<?php

/**
 * Picks the representation a dataset IRI answers from an Accept header.
 *
 * @category Http
 * @package  OCA\OpenCatalogi\Http
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Http;

/**
 * Picks the representation a dataset IRI answers from an Accept header.
 *
 * Pure negotiation, no state: Turtle and RDF/XML answer the DCAT graph, HTML
 * redirects to the publication page, anything else leaves the publication
 * answer as it is.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
 */
class DcatNegotiation {

	/**
	 * The RDF media types a dataset IRI answers, by serializer format.
	 */
	public const RDF_TYPES = [
		'text/turtle' => 'turtle',
		'application/rdf+xml' => 'rdfxml',
	];

	/**
	 * The media types answered with a redirect to the publication page.
	 */
	public const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

	/**
	 * The representation the Accept header puts first, when this IRI answers it itself.
	 *
	 * @param string|null $accept The Accept header.
	 *
	 * @return string|null `turtle`, `rdfxml` or `html`; null for anything else, the publication JSON answers that.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
	 */
	public static function preferred(?string $accept): ?string {
		$best = null;
		$bestQuality = 0.0;
		foreach (explode(',', (string)$accept) as $range) {
			[$type, $quality] = self::mediaRange(range: $range);
			if ($quality > $bestQuality) {
				$best = $type;
				$bestQuality = $quality;
			}
		}

		return self::kindOf(type: $best);
	}//end preferred()

	/**
	 * One media range of an Accept header as its type and quality.
	 *
	 * @param string $range One comma-separated part, such as `text/turtle;q=0.9`.
	 *
	 * @return array{0: string, 1: float} The lower-case type and its quality.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
	 */
	private static function mediaRange(string $range): array {
		$parts = array_map('trim', explode(';', $range));
		$quality = 1.0;
		foreach (array_slice($parts, 1) as $parameter) {
			if (str_starts_with(strtolower($parameter), 'q=') === true) {
				$quality = (float)substr($parameter, 2);
			}
		}

		return [strtolower($parts[0]), $quality];
	}//end mediaRange()

	/**
	 * The representation a media type names here.
	 *
	 * @param string|null $type The media type.
	 *
	 * @return string|null `turtle`, `rdfxml`, `html` or null.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
	 */
	private static function kindOf(?string $type): ?string {
		if (in_array($type, self::HTML_TYPES, true) === true) {
			return 'html';
		}

		return (self::RDF_TYPES[(string)$type] ?? null);
	}//end kindOf()
}//end class
