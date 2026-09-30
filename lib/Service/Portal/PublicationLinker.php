<?php

/**
 * OpenCatalogi Publication Linker.
 *
 * The public link to one publication, as residents get it in their dossier,
 * on a share link and in a saved-search notice. An admin points it at the
 * portal's own publication page with the app config value
 * `publication_url_template` (with `{id}` for the publication uuid). Without
 * it the link is opencatalogi's public publication API in the catalogue named
 * by `publication_url_catalog` (default `publication`), the same default
 * dossiq uses for its publication URL.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Builds the public link to a publication.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */
class PublicationLinker {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    Holds the template.
	 * @param IURLGenerator $urlGenerator Makes a path absolute.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * The public link to a publication.
	 *
	 * @param string $id The publication uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function url(string $id): string {
		$template = trim($this->appConfig->getValueString('opencatalogi', 'publication_url_template', ''));
		if ($template !== '' && str_contains($template, '{id}') === true) {
			return str_replace('{id}', rawurlencode($id), $template);
		}

		$catalog = trim($this->appConfig->getValueString('opencatalogi', 'publication_url_catalog', 'publication'));
		if ($catalog === '') {
			$catalog = 'publication';
		}

		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencatalogi/api/'.rawurlencode($catalog).'/'.rawurlencode($id));

	}//end url()
}//end class
