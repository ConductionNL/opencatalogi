<?php

/**
 * The public page and the officer page of a publication.
 *
 * A source system that creates a publication through OpenRegister gets both
 * pages back: `publicUrl`, where the public reads it, and `officerUrl`, where
 * an officer works on it in OpenCatalogi (publication-tells-its-source,
 * REQ-PTS-001).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Builds `publicUrl` and `officerUrl` for a publication.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */
class PublicationLinks {

	/**
	 * The two properties, in the order they are written.
	 *
	 * @var array<int, string>
	 */
	public const PROPERTIES = ['publicUrl', 'officerUrl'];

	/**
	 * Constructor.
	 *
	 * @param PublicationLinker $linker   Builds the public page.
	 * @param CatalogiService   $catalogs Finds the catalogue that lists the publication's schema.
	 * @param IURLGenerator     $urls     Builds the officer page.
	 * @param IAppConfig        $config   Holds the default catalogue.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function __construct(
		private readonly PublicationLinker $linker,
		private readonly CatalogiService $catalogs,
		private readonly IURLGenerator $urls,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * Both pages of a publication.
	 *
	 * `officerUrl` opens the publication in the first catalogue whose
	 * `schemas` include its schema. Without one it opens the publications of
	 * the default catalogue (`publication_url_catalog`), and it is empty when
	 * that is empty too. Without a uuid both are empty.
	 *
	 * @param string $uuid   The publication uuid.
	 * @param string $schema The publication's schema id.
	 *
	 * @return array{publicUrl: string, officerUrl: string} The two pages.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function for(string $uuid, string $schema): array {
		if ($uuid === '') {
			return ['publicUrl' => '', 'officerUrl' => ''];
		}

		return [
			'publicUrl'  => $this->linker->publicUrl(id: $uuid),
			'officerUrl' => $this->officerUrl(uuid: $uuid, schema: $schema),
		];
	}//end for()

	/**
	 * The officer page of a publication.
	 *
	 * @param string $uuid   The publication uuid.
	 * @param string $schema The publication's schema id.
	 *
	 * @return string The absolute link, or ''.
	 */
	private function officerUrl(string $uuid, string $schema): string {
		$slug = $this->catalogs->getCatalogSlugForSchema(schemaId: $schema);
		if ($slug !== null) {
			return $this->urls->linkToRouteAbsolute('opencatalogi.ui.publicationsPage', ['catalogSlug' => $slug, 'id' => $uuid]);
		}

		$default = trim($this->config->getValueString('opencatalogi', 'publication_url_catalog', ''));
		if ($default === '') {
			return '';
		}

		return $this->urls->linkToRouteAbsolute('opencatalogi.ui.publicationsIndex', ['catalogSlug' => $default]);
	}//end officerUrl()
}//end class
