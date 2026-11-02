<?php

/**
 * OpenCatalogi Robots Controller.
 *
 * Controller for handling robots.txt generation in the OpenCatalogi app.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Http\TextResponse;
use OCA\OpenCatalogi\Service\SitemapDirectives;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for generating robots.txt content.
 *
 * @psalm-suppress                                 UnusedClass
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class RobotsController extends Controller {

	/**
	 * RobotsController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param SettingsServiceInterface $settingsService The settings service.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 * @param IL10N $l10n The localization service.
	 * @param SitemapDirectives $directives The catalogues that publish a Woo sitemap and their sitemap lines.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly SettingsServiceInterface $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly SitemapDirectives $directives,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	// Generous: robots.txt is fetched by every crawler that visits, and a
	// ceiling that trips here blocks indexing rather than abuse.
	/**
	 * Generate robots.txt with sitemap references.
	 *
	 * @return TextResponse The robots.txt response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): TextResponse {
		$settings = $this->settingsService->getSettings();

		if (isset($settings['configuration']['catalog_register']) === false
			|| isset($settings['configuration']['catalog_schema']) === false
		) {
			return new TextResponse(
				text: $this->l10n->t('Could not fetch settings'),
				status: 500
			);
		}

		$catalogs = $this->directives->sitemapCatalogs(configuration: $settings['configuration']);
		$baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');

		// A crawler that honours robots may fetch the sitemaps and the documents
		// they list; every line ends in a real line break (REQ-WIH-001).
		$text = "User-agent: *\nAllow: /apps/opencatalogi/api/\nAllow: /index.php/apps/opencatalogi/api/\n";
		foreach ($catalogs as $catalog) {
			$text .= $this->directives->sitemapLines(catalog: $catalog, baseUrl: $baseUrl);
		}

		return new TextResponse(text: $text);
	}//end index()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		return $this->directives->getObjectService();
	}//end getObjectService()
}//end class
