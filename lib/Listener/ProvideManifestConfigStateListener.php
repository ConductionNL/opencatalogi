<?php
/**
 * OpenCatalogi Manifest-Config Initial-State Listener.
 *
 * Surfaces the configured register/schema ids (and the national-directory URL)
 * as frontend initial state whenever the OpenCatalogi SPA `index` template is
 * rendered — regardless of which controller served the page.
 *
 * Before this listener the manifest-config initial state was provided inline by
 * UiController::makeSpaResponse. After the AppHost adoption (ADR-040) the `/`
 * index route resolves to OpenRegister's shared AppHost GenericDashboardController
 * (which renders `templates/index.php` but knows nothing of OpenCatalogi's config
 * keys), so a clean install served the SPA without the `@resolve:<key>` sentinels
 * it needs — the frontend then failed with "Invalid configuration for object
 * type: catalog" and never rendered. Hooking the provision to
 * BeforeTemplateRenderedEvent makes it controller-independent: the AppHost `/`
 * page and the UiController-served `/catalogi`, `/search`, … routes all deliver
 * the same initial state.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Provides the manifest-config initial state on every OpenCatalogi SPA render.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class ProvideManifestConfigStateListener implements IEventListener
{
    /**
     * The template name of the SPA index page (see templates/index.php).
     *
     * @var string
     */
    private const SPA_TEMPLATE = 'index';

    /**
     * The IAppConfig register/schema keys surfaced to the frontend so the
     * manifest renderer can resolve `@resolve:<key>` sentinels synchronously
     * (zero-network) instead of hitting the catch-all-shadowed
     * `/api/configs/<key>` route. Only non-empty values are provided.
     *
     * Kept in sync with the object types OpenCatalogi's frontend registers.
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
        'woo_register',
        'woo_batch_schema',
        'woo_assessment_schema',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig    $appConfig    App configuration interface.
     * @param IInitialState $initialState Initial-state service.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IInitialState $initialState
    ) {
    }//end __construct()

    /**
     * Provide the manifest-config initial state for the OpenCatalogi SPA index.
     *
     * No-ops for any template render that is not this app's `index` page, so
     * error pages, other apps' templates and non-SPA responses are untouched.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec exclude ADR-040 AppHost gap fix; relocates UiController's specced initial-state provision, controller-independent.
     * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-every-manifest-resolve-sentinel-is-backed-by-provided-initial-state-woo-prov-003
     */
    public function handle(Event $event): void
    {
        if (($event instanceof BeforeTemplateRenderedEvent) === false) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getApp() !== Application::APP_ID
            || $response->getTemplateName() !== self::SPA_TEMPLATE
        ) {
            return;
        }

        foreach (self::MANIFEST_CONFIG_KEYS as $key) {
            $value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            if ($value !== '') {
                $this->initialState->provideInitialState($key, $value);
            }
        }

        // Surface the resolved national-directory URL (override key, falling back
        // to the canonical constant) so the Add-Directory modal and the
        // first-time-setup federation step default to a single source of truth.
        $this->initialState->provideInitialState(
            'default_directory_url',
            $this->appConfig->getValueString(
                Application::APP_ID,
                'default_directory_url',
                Application::DEFAULT_DIRECTORY_URL
            )
        );

    }//end handle()
}//end class
