<?php
/**
 * Stub for OCA\OpenRegister\Service\RegisterResolverService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for RegisterResolverService used by PHPUnit mocks in bare CI.
 */
final class RegisterResolverService
{

    /**
     * Resolve a register ID.
     *
     * @param string $appId  Application ID.
     * @param string $key    Config key.
     *
     * @return integer
     */
    public function resolveRegisterId(string $appId, string $key): int
    {
        return 0;

    }//end resolveRegisterId()


    /**
     * Resolve a schema ID.
     *
     * @param string $appId  Application ID.
     * @param string $key    Config key.
     *
     * @return integer
     */
    public function resolveSchemaId(string $appId, string $key): int
    {
        return 0;

    }//end resolveSchemaId()


}//end class
