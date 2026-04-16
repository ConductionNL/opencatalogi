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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
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
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register dashboard widgets.
        $context->registerDashboardWidget(CatalogWidget::class);
        $context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
        $context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);

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

        // Register catalog rewrite event listeners.
        $context->registerEventListener(ObjectCreatedEvent::class, CatalogSchemaEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, CatalogSchemaEventListener::class);

        // Register tool registration listener for OpenRegister agents.
        $context->registerEventListener(
            event: ToolRegistrationEvent::class,
            listener: ToolRegistrationListener::class
        );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
        // Initialization handled by the Repair step (InitializeSettings).
        // See lib/Repair/InitializeSettings.php.
    }//end boot()
}//end class
