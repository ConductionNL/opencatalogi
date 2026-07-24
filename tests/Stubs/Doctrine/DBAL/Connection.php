<?php
/**
 * Stub for Doctrine\DBAL\Connection.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Stub for Doctrine DBAL Connection.
 */
class Connection
{

    /**
     * @param string $sql SQL query.
     * @param array<mixed> $params Parameters.
     * @param array<mixed> $types Types.
     *
     * @return mixed
     */
    public function executeQuery(string $sql, array $params=[], array $types=[]): mixed
    {
        return null;

    }//end executeQuery()


}//end class
