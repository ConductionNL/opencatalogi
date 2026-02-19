<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Http\TextResponse;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCA\OpenCatalogi\Service\SitemapService;

/**
 * Class RobotsController
 *
 * Controller for handling publication-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class RobotsController extends Controller
{


    /**
     * PublicationsController constructor.
     *
     * @param string             $appName         The name of the app
     * @param IRequest           $request         The request object
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       The container for dependency injection
     * @param IAppManager        $appManager      The app manager
     * @param IURLGenerator      $urlGenerator    The Nextcloud URL generator
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return \OCP\AppFramework\Http\Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): TextResponse
    {
        $settings = $this->settingsService->getSettings();

        if (isset($settings['configuration']['catalog_register']) === false || isset($settings['configuration']['catalog_schema']) === false) {
            return new TextResponse('Could net fetch settings', 500);
        }

        $searchQuery['@self']['register'] = $settings['configuration']['catalog_register'];
        $searchQuery['@self']['schema']   = $settings['configuration']['catalog_schema'];

        $catalogs = ($this->getObjectService()->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false,
            deleted: false
        )['results'] ?? []);

        $baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');

        $text  = '';
        $count = 0;
        foreach ($catalogs as $catalog) {
            if (!$catalog->getSlug()) {
                continue;
            }

            if ($count > 0) {
                $text .= '\n';
            }

            foreach (SitemapService::INFO_CAT as $categoryCode => $categoryName) {
                $text .= "Sitemap: $baseUrl/apps/opencatalogi/api/{$catalog->getSlug()}/sitemaps/$categoryCode\n";
            }

            $count++;
        }

        return new TextResponse($text);

    }//end index()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return mixed|null The OpenRegister service if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


}//end class
