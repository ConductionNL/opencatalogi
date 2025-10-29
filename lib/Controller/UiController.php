<?php

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
     * @param string $appName
     * @param IRequest $request
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    /**
     * Returns the base SPA template response with permissive connect-src for API calls.
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    private function makeSpaResponse(): TemplateResponse
    {
        try {
            // Create a new TemplateResponse for the index page
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            // Set up Content Security Policy
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            // Return an error template response if an exception occurs
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function catalogi(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function publicationsIndex(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function publicationsPage(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function search(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function organizations(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function themes(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function glossary(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function pages(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function menus(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @phpstan-return TemplateResponse
     * @psalm-return TemplateResponse
     */
    public function directory(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }
}
