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

use OCA\OpenCatalogi\Http\TextResponse;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Controller for generating robots.txt content.
 *
 * @psalm-suppress                                 UnusedClass
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RobotsController extends Controller {

	/**
	 * The object service instance.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * RobotsController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param SettingsService $settingsService The settings service.
	 * @param ContainerInterface $container The container for DI.
	 * @param IAppManager $appManager The app manager.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 * @param IL10N $l10n The localization service.
	 * @param WooCategoryRegistry $categories The information categories, bundled plus local.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly WooCategoryRegistry $categories,
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

		$searchQuery = [];
		$searchQuery['@self']['register'] = $settings['configuration']['catalog_register'];
		$searchQuery['@self']['schema'] = $settings['configuration']['catalog_schema'];
		// Only catalogues that publish a Woo sitemap (REQ-WIH-001).
		$searchQuery['hasWooSitemap'] = true;

		// Rbac=true enforces schema authorization; multi=false for public robots.txt.
		$catalogResult = $this->getObjectService()->searchObjectsPaginated(
			query: $searchQuery,
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);

		// Visibility governed by RBAC on the search above (_rbac: true) — robots.txt
		// references only catalogs the public group may read.
		$catalogs = ($catalogResult['results'] ?? []);

		$baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');

		// A crawler that honours robots may fetch the sitemaps and the documents
		// they list; every line ends in a real line break (REQ-WIH-001).
		$text = "User-agent: *\nAllow: /apps/opencatalogi/api/\nAllow: /index.php/apps/opencatalogi/api/\n";
		foreach ($catalogs as $catalog) {
			$slug = $catalog->getSlug();
			if (is_string($slug) === false || $slug === '' || $this->publishesWoo(catalog: $catalog) === false) {
				continue;
			}

			// One line per category the registry serves, so a category an operator
			// added as data is crawled without a code change (REQ-WIC-001).
			foreach (array_keys($this->categories->sitemapFiles()) as $categoryCode) {
				$text .= "Sitemap: $baseUrl/apps/opencatalogi/api/$slug/sitemaps/$categoryCode\n";
			}
		}

		return new TextResponse(text: $text);
	}//end index()

	/**
	 * Whether a catalogue publishes a Woo sitemap.
	 *
	 * The query already asks OpenRegister for these only; this second look keeps
	 * a catalogue out when a filter is not honoured.
	 *
	 * @param object $catalog The catalogue.
	 *
	 * @return boolean True when `hasWooSitemap` is true.
	 */
	private function publishesWoo(object $catalog): bool {
		$data = [];
		if (method_exists($catalog, 'getObject') === true) {
			$data = (array)$catalog->getObject();
		}

		return (($data['hasWooSitemap'] ?? false) === true);

	}//end publishesWoo()

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
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()
}//end class
