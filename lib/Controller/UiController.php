<?php
/**
 * UiController for OpenCatalogi.
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
 */
class UiController extends Controller
{
    /**
     * UiController constructor.
     *
     * @param string   $appName The name of the application.
     * @param IRequest $request The incoming request object.
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
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
     * Renders the dashboard page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end dashboard()

    /**
     * Renders the catalogi page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function catalogi(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end catalogi()

    /**
     * Renders the publications index page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function publicationsIndex(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end publicationsIndex()

    /**
     * Renders a single publication page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function publicationsPage(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end publicationsPage()

    /**
     * Renders the search page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function search(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end search()

    /**
     * Renders the organizations page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function organizations(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end organizations()

    /**
     * Renders the themes page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function themes(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end themes()

    /**
     * Renders the glossary page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function glossary(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end glossary()

    /**
     * Renders the pages page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function pages(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end pages()

    /**
     * Renders the menus page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function menus(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end menus()

    /**
     * Renders the directory page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return   TemplateResponse
     *
     * @return TemplateResponse The SPA template response.
     */
    public function directory(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end directory()
}//end class
