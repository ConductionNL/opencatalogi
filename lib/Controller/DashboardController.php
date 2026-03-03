<?php
/**
 * Dashboard controller for OpenCatalogi.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\ContentSecurityPolicy;

/**
 * Controller for handling dashboard-related operations in the OpenCatalogi app.
 */
class DashboardController extends Controller
{


    /**
     * DashboardController constructor.
     *
     * @param string   $appName The name of the app.
     * @param IRequest $request The request object.
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Render the dashboard page.
     *
     * @param string|null $getParameter Optional GET parameter.
     *
     * @return TemplateResponse The rendered template response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter): TemplateResponse
    {
        try {
            // Create a new TemplateResponse for the index page.
            $response = new TemplateResponse(
                appName: $this->appName,
                templateName: 'index',
                params: []
            );

            // Set up Content Security Policy.
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            // Return an error template response if an exception occurs.
            return new TemplateResponse(
                appName: $this->appName,
                templateName: 'error',
                params: ['error' => $e->getMessage()],
                renderAs: '500'
            );
        }//end try

    }//end page()


    /**
     * Retrieve dashboard data.
     *
     * @return JSONResponse JSON response containing dashboard data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            // Prepare results.
            $results = ['results' => []];
            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            // Return an error JSON response if an exception occurs.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end index()


}//end class
