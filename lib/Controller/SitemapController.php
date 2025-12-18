<?php

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
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(string $catalogSlug, string $categoryCode): XMLResponse
    {
        return $this->sitemapService->buildSitemapIndex(catalogSlug: $catalogSlug, categoryCode: $categoryCode);

    }//end index()


    /**
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
