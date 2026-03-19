<?php
/**
 * Automated publishing flow operation.
 *
 * @category Flow
 * @package  OCA\OpenCatalogi\Flow\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Flow\Operations;

use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IOperation;

/**
 * Operation for automatically publishing publications that meet predefined parameters.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class AutomatedPublishing implements IOperation
{
    /**
     * Get the display name of this operation.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return 'Automated publishing';

    }//end getDisplayName()

    /**
     * Get the description of this operation.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Automatically publish publications if they meet predefined parameters';

    }//end getDescription()

    /**
     * Get the icon for this operation.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath(appName: 'opencatalogi', file: 'app.svg');

    }//end getIcon()

    /**
     * Validate the operation configuration.
     *
     * @param string $name      The operation name.
     * @param array  $checks    The checks to validate.
     * @param string $operation The operation string.
     *
     * @return void
     */
    public function validateOperation(string $name, array $checks, string $operation): void
    {

    }//end validateOperation()

    /**
     * Determines for what kind of users the operation is available.
     *
     * @param int $scope The scope to check.
     *
     * @return bool
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

    /**
     * Handle the event when triggered.
     *
     * @param string                           $eventName   The event name.
     * @param Event                            $event       The event.
     * @param \OCP\WorkflowEngine\IRuleMatcher $ruleMatcher The rule matcher.
     *
     * @return void
     */
    public function onEvent(string $eventName, Event $event, \OCP\WorkflowEngine\IRuleMatcher $ruleMatcher): void
    {

    }//end onEvent()
}//end class
