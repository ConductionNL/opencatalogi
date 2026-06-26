<?php
/**
 * Stub for OCA\OpenRegister\Event\ObjectDeletedEvent.
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
 * Stub for ObjectDeletedEvent.
 */
class ObjectDeletedEvent extends Event
{

    /**
     * @var ObjectEntity
     */
    private ObjectEntity $object;

    /**
     * Constructor.
     *
     * @param ObjectEntity $object The deleted object.
     */
    public function __construct(ObjectEntity $object)
    {
        parent::__construct();
        $this->object = $object;

    }//end __construct()


    /**
     * Get the deleted object.
     *
     * @return ObjectEntity
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()


}//end class
