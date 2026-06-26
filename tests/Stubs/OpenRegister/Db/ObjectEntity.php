<?php
/**
 * Stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Used in the bare php:8.3-cli unit-test environment where the real OpenRegister
 * app is not installed. PHPUnit createMock / getMockBuilder needs the class to
 * exist so it can build a type-safe mock; the methods here are left unimplemented.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for ObjectEntity — only the surface used by unit tests.
 *
 * Extends OCP\AppFramework\Db\Entity so that production code doing
 * instanceof checks resolves correctly in bare php:8.3-cli CI containers.
 * The __call magic is overridden to bypass Entity's strict property_exists
 * guard (the real ObjectEntity stores data in a JSON blob, not declared
 * PHP properties, so it overrides __call in the same way).
 */
class ObjectEntity extends \OCP\AppFramework\Db\Entity implements \JsonSerializable
{

    /**
     * Internal property bag for magic setter/getter/hasser calls.
     * Mirrors how the real ObjectEntity stores its dynamic payload.
     *
     * @var array<string,mixed>
     */
    private array $_props = [];

    /**
     * Magic method router — overrides OCP\AppFramework\Db\Entity::__call so
     * arbitrary setter/getter/hasser calls do not throw BadFunctionCallException.
     *
     * @param string       $name      Method name.
     * @param array<mixed> $arguments Arguments.
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'set') === true && count($arguments) >= 1) {
            $prop                = lcfirst(substr($name, 3));
            $this->_props[$prop] = $arguments[0];
            return null;
        }

        if (str_starts_with($name, 'get') === true) {
            $prop = lcfirst(substr($name, 3));
            return $this->_props[$prop] ?? null;
        }

        if (str_starts_with($name, 'has') === true) {
            $prop = lcfirst(substr($name, 3));
            return isset($this->_props[$prop]) === true;
        }

        return null;

    }//end __call()


    /**
     * Stub implementation of jsonSerialize.
     *
     * Mirrors the real ObjectEntity behaviour: returns the stored object data
     * (from setObject/getObject) merged with a top-level 'id' from the uuid,
     * NOT the raw internal property bag.  Tests that call setObject() and then
     * read $entity->jsonSerialize()['slug'] work correctly.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        // Base is the stored object data (set via setObject or __call).
        $object = $this->_props['object'] ?? [];

        // Surface the uuid as a top-level 'id' key, matching real ObjectEntity.
        if (isset($this->_props['uuid']) === true) {
            $object['id'] = $this->_props['uuid'];
        }

        return $object;

    }//end jsonSerialize()


    /**
     * Stub for getId — inherits from OCP\AppFramework\Db\Entity (int primary key).
     *
     * @return integer|null
     */
    public function getId(): ?int
    {
        return isset($this->_props['id']) ? (int) $this->_props['id'] : null;

    }//end getId()


    /**
     * Stub for getUuid.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->_props['uuid'] ?? null;

    }//end getUuid()


    /**
     * Stub for getRegister.
     *
     * @return mixed
     */
    public function getRegister(): mixed
    {
        return $this->_props['register'] ?? null;

    }//end getRegister()


    /**
     * Stub for getSchema.
     *
     * @return mixed
     */
    public function getSchema(): mixed
    {
        return $this->_props['schema'] ?? null;

    }//end getSchema()


    /**
     * Stub for getObject.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        return $this->_props['object'] ?? [];

    }//end getObject()


}//end class
