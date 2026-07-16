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
 *
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;

/**
 * Controller for handling sitemap operations.
 *
 * @spec openspec/specs/woo-compliance/spec.md
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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/woo-compliance/spec.md
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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/woo-compliance/spec.md
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

    /**
     * Validate a catalog's DIWOO output against the TOOI/DiWoo value lists.
     *
     * Admin-only (AuthorizedAdminSetting). Runs the DIWOO mapping in a dry run and
     * reports, per document, any axis (informatiecategorie / publisher / soortHandeling)
     * that could not resolve to an official value-list URI. Advisory only — it never
     * blocks the sitemap from being served (WOO-TOOI-004).
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The DIWOO category code (e.g. `infocat014`).
     *
     * @return JSONResponse The per-document violation report.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function diwooReport(string $catalogSlug, string $categoryCode): JSONResponse
    {
        $page   = (int) ($this->request->getParams()['page'] ?? 1);
        $report = $this->sitemapService->validateDiwooOutput(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode,
            page: $page
        );

        return new JSONResponse($report, 200);

    }//end validateDiwoo()
}//end class
