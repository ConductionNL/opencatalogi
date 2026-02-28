<?php

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Class DashboardController
 *
 * Controller for handling dashboard-related operations in the OpenCatalogi app.
 */
class DashboardController extends Controller
{


    /**
     * DashboardController constructor.
     *
     * @param string   $appName The name of the app
     * @param IRequest $request The request object
     */
    public function __construct($appName, IRequest $request)
    {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Render the dashboard page.
     *
     * @param  string|null $getParameter Optional GET parameter
     * @return TemplateResponse The rendered template response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter): TemplateResponse
    {
        try {
            // Create a new TemplateResponse for the index page
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            return $response;
        } catch (\Exception $e) {
            // Return an error template response if an exception occurs
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }//end try

    }//end page()


}//end class
