<?php

/**
 * Content negotiation on a dataset IRI of the DCAT feed.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\DcatSerializerInterface;
use OCA\OpenCatalogi\Http\DcatResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Answers a dataset IRI in the representation its Accept header puts first.
 *
 * The dataset IRI the feed emits is the public publication URL. Asked for
 * Turtle or RDF/XML it answers that one dataset's DCAT graph, built from the
 * same search with RBAC on as the catalogue feed, so a publication the caller
 * cannot read is a 404. Asked for HTML it redirects to the publication page.
 * Any other Accept header leaves the publication answer as it is.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
 */
class DcatDereference {

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
	 * Constructor.
	 *
	 * @param DcatService $dcat Builds the dataset graph.
	 * @param DcatSerializerInterface $serializer Writes the graph in the asked format.
	 * @param IURLGenerator $urls Builds the publication page URL.
	 * @param IL10N $l10n Translates the refusal.
	 */
	public function __construct(
		private readonly DcatService $dcat,
		private readonly DcatSerializerInterface $serializer,
		private readonly IURLGenerator $urls,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

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
	 * Answer a dataset IRI in a representation `preferred()` named.
	 *
	 * The caller has guarded the catalogue with the read-rule guard, as for the feed.
	 *
	 * @param array<string, mixed> $catalog The guarded catalogue.
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication uuid from the IRI.
	 * @param string $kind `turtle`, `rdfxml` or `html`.
	 *
	 * @return Response The graph, the redirect, or a 404.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
	 */
	public function answer(array $catalog, string $catalogSlug, string $id, string $kind): Response {
		if ($kind === 'html') {
			return new RedirectResponse(
				$this->urls->linkToRoute('opencatalogi.ui.publicationsPage', ['catalogSlug' => $catalogSlug, 'id' => $id])
			);
		}

		$document = null;
		if ($this->dcat->isDcatEnabled($catalog) === true) {
			$document = $this->dcat->buildDatasetDocument(catalog: $catalog, catalogSlug: $catalogSlug, uuid: $id);
		}

		if ($document === null) {
			return new JSONResponse(['error' => $this->l10n->t('Publication not found')], 404);
		}

		return new DcatResponse(
			body: $this->serializer->serialize($document, $kind),
			contentType: DcatSerializerInterface::FORMATS[$kind],
			status: 200,
			headers: ['Vary' => 'Accept']
		);
	}//end answer()

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
