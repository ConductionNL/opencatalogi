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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * UiController that serves SPA entry for history-mode deep links.
 *
 * @psalm-type TemplateName = 'index'
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
 */
class UiController extends Controller
{
    /**
     * The IAppConfig register/schema keys surfaced to the frontend so the
     * nextcloud-vue manifest renderer can resolve its "resolve" sentinels
     * synchronously via loadState('opencatalogi', key).
     *
     * A runtime fetch fallback (GET /apps/opencatalogi/api/configs/key)
     * cannot be used because the public catch-all route /api/catalogSlug
     * (requirement [a-z0-9-]+) shadows it and answers "Catalog not found".
     * Provisioning these as initial-state is the zero-network path the
     * resolver tries first.
     *
     * @var string[]
     */
    private const MANIFEST_CONFIG_KEYS = [
        'catalog_register',
        'catalog_schema',
        'listing_register',
        'listing_schema',
        'organization_register',
        'organization_schema',
        'theme_register',
        'theme_schema',
        'page_register',
        'page_schema',
        'menu_register',
        'menu_schema',
        'glossary_register',
        'glossary_schema',
        'publication_register',
        'publication_schema',
    ];

    /**
     * Constructor.
     *
     * @param string        $appName      The application name.
     * @param IRequest      $request      The HTTP request.
     * @param IAppConfig    $appConfig    App configuration interface.
     * @param IInitialState $initialState Initial-state service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly IInitialState $initialState
    ) {
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
            // Surface the configured register/schema ids so the manifest
            // renderer can resolve `@resolve:<key>` sentinels synchronously
            // (zero-network) instead of hitting the catch-all-shadowed
            // `/api/configs/<key>` route. Only non-empty values are provided.
            foreach (self::MANIFEST_CONFIG_KEYS as $key) {
                $value = $this->appConfig->getValueString($this->appName, $key, '');
                if ($value !== '') {
                    $this->initialState->provideInitialState($key, $value);
                }
            }

            // Surface the resolved national-directory URL (override key, falling
            // back to the canonical constant) so the Add-Directory modal and the
            // first-time-setup federation step default to a single source of truth
            // instead of a hardcoded literal.
            $this->initialState->provideInitialState(
                'default_directory_url',
                $this->appConfig->getValueString(
                    $this->appName,
                    'default_directory_url',
                    Application::DEFAULT_DIRECTORY_URL
                )
            );

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
            // HTTP 500 is the `status` arg, not `renderAs`: the prior positional
            // '500' silently landed in renderAs and rendered as HTTP 200 instead.
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                status: Http::STATUS_INTERNAL_SERVER_ERROR
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-spa-deep-link-routing/tasks.md#task-1
     */
    public function directory(): TemplateResponse
    {
        return $this->makeSpaResponse();

    }//end directory()
}//end class
