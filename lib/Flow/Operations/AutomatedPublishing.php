<?php
/**
 * Automated Publishing Workflow Operation for OpenCatalogi.
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

namespace OCA\OpenCatalogi\Cron;

use OCP\WorkflowEngine\IOperation ;

class AutomatedPublishing extends IOperation
{


    /**
     * Get the display name of this operation.
     *
     * @return string The translated display name.
     */
    public function getDisplayName(): string
    {
        return $this->l->t('Automated publishing');

    }//end getDisplayName()


    /**
     * Get the description of this operation.
     *
     * @return string The translated description.
     */
    public function getDescription(): string
    {
        return $this->l->t('Automaticly publish publiations if they meet predefined parameters');

    }//end getDescription()


    /**
     * Get the icon URL for this operation.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath('opencatalogi', 'app.svg');

    }//end getIcon()


    /**
     * Validate the operation configuration.
     *
     * @return boolean True if the operation is valid.
     */
    public function validateOperation(): bool
    {
        return true;

    }//end validateOperation()


    /**
     * Determines for what kind of users the operation is available.
     *
     * The $scope parameter is presented from the IManager as a constant
     * with 0 for ADMIN and 1 for USER.
     *
     * @param integer $scope The scope constant (0 = ADMIN, 1 = USER).
     *
     * @return boolean True if available for the given scope.
     */
    public function isAvailableForScope(int $scope): bool
    {
        if (scope === 0) {
            return false;
        }

        return false;

    }//end isAvailableForScope()


}//end class
