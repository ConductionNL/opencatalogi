<?php
/**
 * OpenCatalogi UI Controller.
 *
 * Serves SPA entry for history-mode deep links.
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
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * UiController that serves SPA entry for history-mode deep links.
 *
 * @psalm-type TemplateName = 'index'
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class UiController extends Controller
{
    /**
     * Constructor.
     *
     * @param string   $appName The application name.
     * @param IRequest $request The HTTP request.
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        try {
            // Create a new TemplateResponse for the index page.
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            // Set up Content Security Policy.
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            // Return an error template response if an exception occurs.
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }//end try

    }//end makeSpaResponse()

    /**
     * Serve dashboard page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end dashboard()

    /**
     * Serve catalogi page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function catalogi(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end catalogi()

    /**
     * Serve publications index page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function publicationsIndex(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end publicationsIndex()

    /**
     * Serve publications detail page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function publicationsPage(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end publicationsPage()

    /**
     * Serve search page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function search(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end search()

    /**
     * Serve organizations page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function organizations(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end organizations()

    /**
     * Serve themes page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function themes(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end themes()

    /**
     * Serve glossary page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function glossary(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end glossary()

    /**
     * Serve pages page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function pages(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end pages()

    /**
     * Serve menus page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function menus(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end menus()

    /**
     * Serve directory page.
     *
     * @return TemplateResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function directory(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end directory()
}//end class
