<?php

/**
 * OpenCatalogi Publication Linker.
 *
 * The public link to one publication, as residents get it in their dossier,
 * on a share link and in a saved-search notice. An admin points it at their
 * own publication page with the app config value `publication_url_template`
 * (with `{id}` for the publication uuid). Without it, and with portaliq
 * installed, the link opens the publication page of portaliq's site
 * (`/publicatie/<id>`), which anyone may open. Only without portaliq is it
 * opencatalogi's public publication API in the catalogue named by
 * `publication_url_catalog` (default `publication`): raw data, but the one
 * public address there is then.
 *
 * Every link is absolute through IURLGenerator::getAbsoluteURL(). In a
 * background job (the saved-search match) that reads the instance's
 * `overwrite.cli.url`, so that setting must carry the public host and port.
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Builds the public link to a publication.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */
class PublicationLinker {

	/**
	 * The publication page of portaliq's site, before the publication id
	 * (the same page dossiq's decision notice links to).
	 */
	public const SITE_PUBLICATION_PAGE = '/index.php/apps/portaliq/site?route=/publicatie/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig       $appConfig    Holds the template.
	 * @param IURLGenerator    $urlGenerator Makes a path absolute.
	 * @param IAppManager|null $appManager   Tells whether portaliq, and so its site, is there.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?IAppManager $appManager=null,
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
	 * @spec openspec/changes/saved-search-link-to-the-site/specs/saved-searches/spec.md#requirement-a-notice-links-to-the-publication-page-of-the-site
	 */
	public function url(string $id): string {
		$template = trim($this->appConfig->getValueString('opencatalogi', 'publication_url_template', ''));
		if ($template !== '' && str_contains($template, '{id}') === true) {
			return str_replace('{id}', rawurlencode($id), $template);
		}

		if ($this->appManager?->isInstalled('portaliq') === true) {
			return $this->urlGenerator->getAbsoluteURL(self::SITE_PUBLICATION_PAGE.rawurlencode($id));
		}

		$catalog = trim($this->appConfig->getValueString('opencatalogi', 'publication_url_catalog', 'publication'));
		if ($catalog === '') {
			$catalog = 'publication';
		}

		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencatalogi/api/'.rawurlencode($catalog).'/'.rawurlencode($id));

	}//end url()
}//end class
