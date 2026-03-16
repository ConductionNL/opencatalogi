<?php
/**
 * Application bootstrap for OpenCatalogi.
 *
 * Registers dashboard widgets, event listeners, and tool registrations.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ToolRegistrationEvent;

/**
 * Main Application class for OpenCatalogi
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'opencatalogi';

    /**
     * Constructor for the Application.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register services, event listeners, and dashboard widgets.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register dashboard widgets.
        $context->registerDashboardWidget(CatalogWidget::class);
        $context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
        $context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);

        // Register event listeners for OpenRegister events.
        $context->registerEventListener(ObjectCreatedEvent::class, ObjectCreatedEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ObjectUpdatedEventListener::class);

        // Register catalog cache event listeners.
        $context->registerEventListener(ObjectCreatedEvent::class, CatalogCacheEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, CatalogCacheEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, CatalogCacheEventListener::class);

        // Register catalog rewrite event listeners.
        $context->registerEventListener(ObjectCreatedEvent::class, CatalogSchemaEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, CatalogSchemaEventListener::class);

        // Register tool registration listener for OpenRegister agents.
        $context->registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class);
    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        // Initialization is now handled by the Repair step (InitializeSettings).
        // which runs only during app install/upgrade, not on every request.
        // See lib/Repair/InitializeSettings.php.
    }//end boot()
}//end class
