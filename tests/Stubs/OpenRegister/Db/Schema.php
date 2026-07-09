<?php
/**
 * Stub for OCA\OpenRegister\Db\Schema.
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
 * Minimal stub for Schema entity.
 */
class Schema implements \JsonSerializable
{

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [];

    }//end jsonSerialize()


}//end class
