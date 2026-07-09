<?php
/**
 * Stub for Doctrine\DBAL\Logging\SQLLogger.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Logging;

/**
 * Stub for SQLLogger interface.
 */
interface SQLLogger
{

    /**
     * Start a query.
     *
     * @param string       $sql    SQL query.
     * @param array<mixed> $params Parameters.
     * @param array<mixed> $types  Types.
     *
     * @return void
     */
    public function startQuery(string $sql, ?array $params=null, ?array $types=null): void;

    /**
     * Stop a query.
     *
     * @return void
     */
    public function stopQuery(): void;

}//end interface
