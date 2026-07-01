<?php
/**
 * Main Application class for OpenCatalogi.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
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
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\AppInfo;

use OCA\OpenCatalogi\Listener\CatalogSchemaEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\OpenCatalogi\Dashboard\CatalogWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedPublicationsWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedAttachmentsWidget;
use OCA\OpenCatalogi\Dashboard\MostViewedPublicationsWidget;
use OCA\OpenCatalogi\Dashboard\RetentionWidget;
use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ToolRegistrationEvent;

/**
 * Main Application class for OpenCatalogi.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'opencatalogi';

    /**
     * Constructor.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);

    }//end __construct()

    /**
     * Register app services and event listeners.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-1
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register dashboard widgets.
        $context->registerDashboardWidget(CatalogWidget::class);
        $context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
        $context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);
        $context->registerDashboardWidget(MostViewedPublicationsWidget::class);
        $context->registerDashboardWidget(RetentionWidget::class);

        // Register event listeners for OpenRegister events.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: ObjectCreatedEventListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: ObjectUpdatedEventListener::class
        );

        // Register catalog cache event listeners.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: CatalogCacheEventListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: CatalogCacheEventListener::class
        );
        $context->registerEventListener(
            event: ObjectDeletedEvent::class,
            listener: CatalogCacheEventListener::class
        );

        // Register catalog rewrite event listeners on the *pre-save* events so the
        // listener can mutate the in-flight payload via `setModifiedData(...)` instead
        // of issuing a second save. Subscribing to the post-save events caused an
        // infinite event loop on every catalog update/soft-delete.
        $context->registerEventListener(ObjectCreatingEvent::class, CatalogSchemaEventListener::class);
        $context->registerEventListener(ObjectUpdatingEvent::class, CatalogSchemaEventListener::class);

        // Register tool registration listener for OpenRegister agents.
        $context->registerEventListener(
            event: ToolRegistrationEvent::class,
            listener: ToolRegistrationListener::class
        );

        // Register OpenCatalogiToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::opencatalogi' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-034/035).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator); until
        // then apps implement the test stub in tests/Stubs/Mcp/IMcpToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::opencatalogi',
            OpenCatalogiToolProvider::class
        );

        // AppHost observability adoption (ADR-040). The /api/health and
        // /api/metrics routes resolve to leaf-namespaced controller class names
        // (OCA\OpenCatalogi\AppHost\Controller\Generic{Health,Metrics}Controller)
        // that do not physically exist in this app; bind them to OpenRegister's
        // shared AppHost generics so the engine serves both endpoints from the
        // `observability` block of src/manifest.json. URL + contract unchanged.
        //
        // These MUST be registerService closures (not registerServiceAlias) so
        // this app's id is injected as the controllers' $appName — exactly like
        // the Dashboard/Preferences registrations below. A bare alias leaves
        // $appName to be autowired, and because the target class lives in the
        // OCA\OpenRegister namespace the cross-container fallback resolves it to
        // `openregister`, so /api/health + /api/metrics would report the wrong
        // app and load OpenRegister's manifest instead of this app's.
        $context->registerService(
            'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericHealthController',
            static function ($c) {
                return new GenericHealthController(
                    appName: self::APP_ID,
                    request: $c->get('OCP\\IRequest'),
                    manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
                    executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
                );
            }
        );
        $context->registerService(
            'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericMetricsController',
            static function ($c) {
                return new GenericMetricsController(
                    appName: self::APP_ID,
                    request: $c->get('OCP\\IRequest'),
                    manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
                    engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
                );
            }
        );

        // Register the domain-metrics escape hatch under the ADR-035 alias the
        // engine's ProviderMetricSource enumerates. The {kind:provider} metric
        // descriptor in the manifest merges this provider's samples into the
        // /api/metrics response, preserving the pre-adoption contract.
        $context->registerServiceAlias(
            IMetricsProvider::class.'::opencatalogi',
            OpenCatalogiMetricsProvider::class
        );

        // AppHost boilerplate adoption (ADR-040). The Dashboard (SPA page +
        // catch-all) and per-user Preferences controllers were byte-for-byte
        // copies of OpenRegister's shared AppHost generics, so bind this app's
        // conventional controller class names to the engine generics with the
        // leaf app id injected as $appName. URLs, route names and JSON
        // contracts are unchanged; the engine owns the (identical) auth
        // posture so the leaf can never drift it. The bespoke
        // DashboardController + PreferencesController classes were deleted.
        //
        // NOT adopted (kept bespoke — domain-entangled): SettingsController
        // (carries getPublishingOptions/updatePublishingOptions/getVersionInfo/
        // manualImport + a GET load() that runs the register.d fragment-merge
        // via the bespoke SettingsService — the generic SettingsController only
        // does index/create/load with force-reimport semantics and does not
        // reproduce that envelope), the OpenCatalogiAdmin AdminSettings (its
        // getAuthorizedAppConfig() declares an OpenCatalogi-specific allow-list
        // regex that the manifest-driven GenericAdminSettings does not
        // reproduce — required for delegated-admin gating), and the federation/
        // DCAT/catalog domain services + listeners.
        // The /api/preferences/{key} and / + /{path} routes resolve to
        // leaf-namespaced AppHost controller class names
        // (OCA\OpenCatalogi\AppHost\Controller\Generic{Dashboard,Preferences}Controller)
        // that do not physically exist in this app — same pattern as the
        // Health/Metrics adoption above. Register them as services that
        // construct the OpenRegister generics with this app's id injected as
        // $appName, so templates/index.php and the `pref_` user-value namespace
        // are scoped to opencatalogi, never OpenRegister.
        $context->registerService(
            'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericDashboardController',
            static function ($c) {
                return new GenericDashboardController(
                    appName: self::APP_ID,
                    request: $c->get('OCP\\IRequest')
                );
            }
        );
        $context->registerService(
            'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericPreferencesController',
            static function ($c) {
                return new GenericPreferencesController(
                    appName: self::APP_ID,
                    request: $c->get('OCP\\IRequest'),
                    config: $c->get('OCP\\IConfig'),
                    userSession: $c->get('OCP\\IUserSession')
                );
            }
        );

    }//end register()

    /**
     * Boot the application.
     *
     * Registers the app-menu navigation entry via INavigationManager (see body).
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     *
     * @spec exclude Framework lifecycle hook; app-menu navigation registration is infrastructure, not a spec'd feature.
     */
    public function boot(IBootContext $context): void
    {
        // Initialization handled by the Repair step (InitializeSettings).
        // See lib/Repair/InitializeSettings.php.
        //
        // Register the app-menu navigation entry in PHP instead of info.xml.
        // The dashboard SPA is served by the engine-namespaced AppHost route
        // OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#page (see appinfo/routes.php),
        // whose generated route name contains backslashes. info.xml's <navigations><route>
        // is validated against the App Store's info.xsd pattern [0-9a-zA-Z_]+(\.[0-9a-zA-Z_]+){2},
        // which rejects that name (HTTP 400 on publish). IURLGenerator::linkToRoute() carries
        // no such constraint, so registering here keeps the app-menu href working AND lets the
        // release pass App Store validation.
        $server            = $context->getServerContainer();
        $navigationManager = $server->get(\OCP\INavigationManager::class);
        $navigationManager->add(
            static function () use ($server) {
                $urlGenerator = $server->get(\OCP\IURLGenerator::class);
                $l10n         = $server->get(\OCP\L10N\IFactory::class)->get(Application::APP_ID);

                return [
                    'id'    => Application::APP_ID,
                    'order' => 10,
                    'href'  => $urlGenerator->linkToRoute('opencatalogi.oca\opencatalogi\apphost\controller\genericdashboard.page'),
                    'icon'  => $urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
                    'name'  => $l10n->t('Catalogi'),
                    'type'  => 'link',
                ];
            }
        );
    }//end boot()
}//end class
