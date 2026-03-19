<?php
/**
 * Listing flow event entity.
 *
 * @category Flow
 * @package  OCA\OpenCatalogi\Flow\Events
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Flow\Events;

use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

/**
 * Listing flow event entity for workflow engine.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ListingEvent implements IEntity
{
    /**
     * Get the entity name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Listing';

    }//end getName()

    /**
     * Get the entity icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath(appName: 'opencatalogi', file: 'app.svg');

    }//end getIcon()

    /**
     * Get available events.
     *
     * @return array<array-key, \OCP\WorkflowEngine\IEntityEvent>
     */
    public function getEvents(): array
    {
        return [];

    }//end getEvents()

    /**
     * Prepare the rule matcher for this entity.
     *
     * @param IRuleMatcher $ruleMatcher The rule matcher.
     * @param string       $eventName   The event name.
     * @param Event        $event       The event.
     *
     * @return void
     */
    public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void
    {

    }//end prepareRuleMatcher()

    /**
     * Check if user is legitimated.
     *
     * @param string $userId The user ID.
     *
     * @return bool
     */
    public function isLegitimatedForUserId(string $userId): bool
    {
        return true;

    }//end isLegitimatedForUserId()
}//end class
