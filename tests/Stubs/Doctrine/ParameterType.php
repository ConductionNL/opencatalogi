<?php

/**
 * Doctrine DBAL ParameterType stub for PHPUnit tests.
 *
 * Doctrine\DBAL is a Nextcloud server dependency that is not available in the
 * standalone composer dev-environment. This stub satisfies the `use` statements
 * in OCP\DB\QueryBuilder\IQueryBuilder so tests that mock IQueryBuilder can be
 * instantiated without the full Nextcloud server present.
 *
 * This file is loaded by tests/bootstrap*.php when the real class is absent.
 * It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs\Doctrine
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

if (class_exists(ParameterType::class) === false) {
    /**
     * Minimal stub for Doctrine\DBAL\ParameterType.
     */
    class ParameterType
    {
        public const NULL         = 0;
        public const INTEGER      = 1;
        public const STRING       = 2;
        public const LARGE_OBJECT = 3;
        public const BOOLEAN      = 5;
        public const BINARY       = 16;
    }//end class
}//end if
