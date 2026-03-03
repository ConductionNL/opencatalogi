<?php
/**
 * Attachment event entity for Nextcloud workflow engine.
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

declare(strict_types=1);

namespace OCA\OpenCatalogi\Flow\Events;

use OCP\WorkflowEngine\IEntity;

/**
 * Represents an Attachment entity in the Nextcloud workflow engine.
 *
 * DOCS: https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/flow.html#entities
 */
class AttachmentEvent implements IEntity
{


    /**
     * Get the display name of this entity.
     *
     * @return string The human-readable name of the entity.
     */
    public function getName(): string
    {
        return 'Attachment';

    }//end getName()


    /**
     * Get the icon URL for this entity.
     *
     * @return string The URL of the entity icon.
     */
    public function getIcon(): string
    {
        return \OC::$server->getURLGenerator()->imagePath(
            app: 'opencatalogi',
            image: 'app.svg'
        );

    }//end getIcon()


    /**
     * Get the list of events this entity supports.
     *
     * @return array<string> The list of supported event class names.
     */
    public function getEvents(): array
    {
        // Return an empty list of events for now.
        return [];

    }//end getEvents()


    /**
     * Check whether the passed user is allowed to see the current event.
     *
     * @param string $userId The user ID to check.
     *
     * @return boolean Whether the user is legitimated.
     */
    public function isLegitimatedForUserId(string $userId): bool
    {
        return true;

    }//end isLegitimatedForUserId()


}//end class
