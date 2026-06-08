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
use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
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
