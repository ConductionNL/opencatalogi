<?php
/**
 * Stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectUpdatedEvent.
 */
class ObjectUpdatedEvent extends Event
{

    /**
     * @var ObjectEntity
     */
    private ObjectEntity $newObject;

    /**
     * @var ObjectEntity|null
     */
    private ?ObjectEntity $oldObject;

    /**
     * Constructor.
     *
     * @param ObjectEntity      $newObject The updated (new) object.
     * @param ObjectEntity|null $oldObject The previous state, if available.
     */
    public function __construct(ObjectEntity $newObject, ?ObjectEntity $oldObject=null)
    {
        parent::__construct();
        $this->newObject = $newObject;
        $this->oldObject = $oldObject;

    }//end __construct()


    /**
     * Get the new object.
     *
     * @return ObjectEntity
     */
    public function getObject(): ObjectEntity
    {
        return $this->newObject;

    }//end getObject()


    /**
     * Get the new object.
     *
     * @return ObjectEntity
     */
    public function getNewObject(): ObjectEntity
    {
        return $this->newObject;

    }//end getNewObject()


    /**
     * Get the old object.
     *
     * @return ObjectEntity|null
     */
    public function getOldObject(): ?ObjectEntity
    {
        return $this->oldObject;

    }//end getOldObject()


}//end class
