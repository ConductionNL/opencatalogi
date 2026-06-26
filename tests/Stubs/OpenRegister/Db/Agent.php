<?php
/**
 * Stub for OCA\OpenRegister\Db\Agent.
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
 * Minimal stub for Agent entity.
 *
 * Provides magic set* / get* / has* routing to emulate the Nextcloud Entity base class.
 */
class Agent implements \JsonSerializable
{

    /**
     * @var array<string,mixed>
     */
    private array $_props = [];

    /**
     * Magic method router for set* / get* / has*.
     *
     * @param string       $name      Method name.
     * @param array<mixed> $arguments Arguments.
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'set') === true && count($arguments) >= 1) {
            $this->_props[lcfirst(substr($name, 3))] = $arguments[0];
            return null;
        }

        if (str_starts_with($name, 'get') === true) {
            return $this->_props[lcfirst(substr($name, 3))] ?? null;
        }

        if (str_starts_with($name, 'has') === true) {
            return isset($this->_props[lcfirst(substr($name, 3))]) === true;
        }

        return null;

    }//end __call()


    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->_props;

    }//end jsonSerialize()


}//end class
