<?php

declare(strict_types=1);

namespace OCA\OpenCatalogi\AppInfo;

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
     * @psalm-suppress PossiblyUnusedMethod 
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }//end constructor

    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__ . '/../../vendor/autoload.php';
        
        // Register dashboard widgets
        $context->registerDashboardWidget(CatalogWidget::class);
        $context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
        $context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);
                
        // Register event listeners for OpenRegister events
        $context->registerEventListener(ObjectCreatedEvent::class, ObjectCreatedEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ObjectUpdatedEventListener::class);
        
        // Register catalog cache event listeners
        $context->registerEventListener(ObjectCreatedEvent::class, CatalogCacheEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, CatalogCacheEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, CatalogCacheEventListener::class);
        
        // Register tool registration listener for OpenRegister agents
        $context->registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class);
    }//end register

    public function boot(IBootContext $context): void
    {
        // Initialization is now handled by the Repair step (InitializeSettings)
        // which runs only during app install/upgrade, not on every request.
        // See lib/Repair/InitializeSettings.php
    }//end boot
}
