<?php

namespace OCA\OpenCatalogi\Flow\Events;

use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

class ListingEvent implements IEntity
{

    public function getName(): string
    {
        return 'Listing';
    }

    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath('opencatalogi', 'app.svg');
    }

    /**
     * @return array<array-key, \OCP\WorkflowEngine\IEntityEvent>
     */
    public function getEvents(): array
    {
        return [];
    }

    public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void
    {
    }

    public function isLegitimatedForUserId(string $userId): bool
    {
        return true;
    }

}//end class
