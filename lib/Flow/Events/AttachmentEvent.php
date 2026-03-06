<?php
/**
 * AttachmentEvent for OpenCatalogi.
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


namespace OCA\OpenCatalogi\Cron;

use OCP\WorkflowEngine\IEntity;

/**
 * DOCS: https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/flow.html#entities
 */
/**
 * Attachment event entity for workflow engine.
 */
class AttachmentEvent extends IEntity
{
    /**
     * Get the name of the event entity.
     *
     * @return string The entity name.
     */
    public function getName(): string
    {
        return $this->l10n - t('Attachment');
    }//end getName()

    /**
     * Get the icon for the event entity.
     *
     * @return string The icon path.
     */
    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath('opencatalogi', 'app.svg');
    }//end getIcon()

    /**
     * Get the events for the entity.
     *
     * @return string The events string.
     */
    public function getEvents(): string
    {
        // Return \OC::$server->getURLGenerator()->imagePath('opencatalogi','app.svg');.
    }//end getEvents()

    /**
     * Will check whether the passed user is allowed to see the current event.
     *
     * @return boolean Whether the user is legitimated.
     */
    public function isLegitimatedForUserId(): bool
    {
        return true;
    }//end isLegitimatedForUserId()
}//end class
