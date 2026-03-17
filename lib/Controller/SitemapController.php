<?php
/**
 * Sitemap controller for OpenCatalogi.
 *
 * Generates XML sitemaps for catalogs and categories.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;

/**
 * Class SitemapController
 * Controller for handling sitemap operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class SitemapController extends Controller
{
    /**
     * SitemapController constructor.
     *
     * @param string         $appName        The name of the app
     * @param IRequest       $request        The request object
     * @param SitemapService $sitemapService SitemapService
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SitemapService $sitemapService,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Build a sitemap index for the given catalog and category.
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The category code.
     *
     * @return XMLResponse The XML sitemap index response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(string $catalogSlug, string $categoryCode): XMLResponse
    {
        return $this->sitemapService->buildSitemapIndex(catalogSlug: $catalogSlug, categoryCode: $categoryCode);

    }//end index()

    /**
     * Build a sitemap page for the given catalog and category.
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The category code.
     *
     * @return XMLResponse The XML sitemap response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function sitemap(string $catalogSlug, string $categoryCode): XMLResponse
    {
        $page = ((int) $this->request->getParams()['page'] ?? 1);
        return $this->sitemapService->buildSitemap(catalogSlug: $catalogSlug, categoryCode: $categoryCode, page: $page);

    }//end sitemap()
}//end class
