<?php
/**
 * Stub for OCA\OpenRegister\Event\ObjectCreatingEvent.
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
 * Stub for ObjectCreatingEvent (fired before creation).
 */
class ObjectCreatingEvent extends Event implements \Psr\EventDispatcher\StoppableEventInterface
{

    /**
     * @var ObjectEntity
     */
    private ObjectEntity $object;

    /**
     * @var boolean
     */
    private bool $propagationStopped = false;

    /**
     * @var array<string>
     */
    private array $errors = [];

    /**
     * @var array<string,mixed>
     */
    private array $modifiedData = [];

    /**
     * Constructor.
     *
     * @param ObjectEntity $object The object being created.
     */
    public function __construct(ObjectEntity $object)
    {
        parent::__construct();
        $this->object = $object;

    }//end __construct()


    /**
     * Get the object.
     *
     * @return ObjectEntity
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()


    /**
     * Check if propagation was stopped.
     *
     * @return boolean
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;

    }//end isPropagationStopped()


    /**
     * Stop propagation.
     *
     * @return void
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;

    }//end stopPropagation()


    /**
     * Set errors.
     *
     * @param array<string> $errors Error messages.
     *
     * @return void
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;

    }//end setErrors()


    /**
     * Get errors.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;

    }//end getErrors()


    /**
     * Set modified data.
     *
     * @param array<string,mixed> $data Modified data.
     *
     * @return void
     */
    public function setModifiedData(array $data): void
    {
        $this->modifiedData = $data;

    }//end setModifiedData()


    /**
     * Get modified data.
     *
     * @return array<string,mixed>
     */
    public function getModifiedData(): array
    {
        return $this->modifiedData;

    }//end getModifiedData()


}//end class
