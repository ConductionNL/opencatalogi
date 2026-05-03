<?php
/**
 * OpenCatalogi Catalog Cache Event Listener
 *
 * This file contains the listener class for handling catalog object events from OpenRegister
 * to manage cache invalidation and warmup.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Event listener for catalog object events from OpenRegister.
 *
 * Listens to object creation, update, and deletion events and manages
 * the catalog cache accordingly through invalidation and warmup operations.
 */
class CatalogSchemaEventListener implements IEventListener
{
    /**
     * CatalogCacheEventListener constructor.
     *
     * @param CatalogiService $catalogiService Service used to invalidate and warm the catalog cache.
     * @param LoggerInterface $logger          Logger for event-handling diagnostics.
     * @param IAppConfig      $appConfig       App configuration for feature flags.
     */
    public function __construct(
        private readonly CatalogiService $catalogiService,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig
    ) {

    }//end __construct()

    /**
     * Handle the event when a catalog object is created or updated.
     *
     * This method checks if the event relates to a catalog object and performs
     * appropriate rewrite operations (fields registers and schemas)
     *
     * @param Event $event The event object containing the ObjectEntity.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Verify this is a supported event type.
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
        ) {
            return;
        }

        try {
            // Get the object from the event (different methods for different event types).
            if ($event instanceof ObjectCreatedEvent) {
                $objectEntity = $event->getObject();
            } else if ($event instanceof ObjectUpdatedEvent) {
                $objectEntity = $event->getNewObject();
            } else {
                return;
            }

            // Get catalog schema and register from config.
            $catalogSchema   = $this->appConfig->getValueString('opencatalogi', 'catalog_schema', '');
            $catalogRegister = $this->appConfig->getValueString('opencatalogi', 'catalog_register', '');

            // Only process if this is a catalog object.
            if ($objectEntity->getSchema() !== $catalogSchema || $objectEntity->getRegister() !== $catalogRegister) {
                return;
            }

            $this->catalogiService->rewriteSchemasAndRegisters($objectEntity);
        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully.
            // Get logger if not already available.
            if (isset($logger) === false) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }

            $logger->error(
                'OpenCatalogi: Exception in catalog cache event listener: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end handle()
}//end class
