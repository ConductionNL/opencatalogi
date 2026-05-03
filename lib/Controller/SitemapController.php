<?php
/**
 * OpenCatalogi Sitemap Controller.
 *
 * Controller for handling sitemap operations in the OpenCatalogi app.
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
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;

/**
 * Controller for handling sitemap operations.
 */
class SitemapController extends Controller
{
    /**
     * SitemapController constructor.
     *
     * @param string         $appName        The name of the app.
     * @param IRequest       $request        The request object.
     * @param SitemapService $sitemapService The sitemap service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SitemapService $sitemapService,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Generate a sitemap index for a catalog and category.
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The category code.
     *
     * @return XMLResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(string $catalogSlug, string $categoryCode): XMLResponse
    {
        return $this->sitemapService->buildSitemapIndex(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode
        );

    }//end index()

    /**
     * Generate a sitemap page for a catalog and category.
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The category code.
     *
     * @return XMLResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function sitemap(string $catalogSlug, string $categoryCode): XMLResponse
    {
        $page = (int) ($this->request->getParams()['page'] ?? 1);
        return $this->sitemapService->buildSitemap(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode,
            page: $page
        );

    }//end sitemap()
}//end class
