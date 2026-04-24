<?php
/**
 * OpenCatalogi Dashboard Controller.
 *
 * Controller for handling dashboard-related operations in the OpenCatalogi app.
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

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for handling dashboard-related operations.
 */
class DashboardController extends Controller
{
    /**
     * DashboardController constructor.
     *
     * @param string   $appName The name of the app.
     * @param IRequest $request The request object.
     */
    public function __construct($appName, IRequest $request)
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
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
}//end class
