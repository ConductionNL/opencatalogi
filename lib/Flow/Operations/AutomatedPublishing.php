<?php

namespace OCA\OpenCatalogi\Flow\Operations;

use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IOperation;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — parameters required by IOperation interface
 */
class AutomatedPublishing implements IOperation
{

    public function getDisplayName(): string
    {
        return 'Automated publishing';

    }//end getDisplayName()


    public function getDescription(): string
    {
        return 'Automatically publish publications if they meet predefined parameters';

    }//end getDescription()


    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath('opencatalogi', 'app.svg');

    }//end getIcon()


    public function validateOperation(string $name, array $checks, string $operation): void
    {

    }//end validateOperation()


    /**
     * Determines for what kind of users the operation is available.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function isAvailableForScope(int $scope): bool
    {
        if ($scope === 0) {
            return false;
        }

        return false;

    }//end isAvailableForScope()


    public function onEvent(string $eventName, Event $event, \OCP\WorkflowEngine\IRuleMatcher $ruleMatcher): void
    {

    }//end onEvent()


}//end class
