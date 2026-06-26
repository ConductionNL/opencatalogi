<?php
/**
 * Stub for Doctrine\DBAL\Query\Expression\ExpressionBuilder.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Stub for ExpressionBuilder with comparison operator constants.
 * OCP\DB\QueryBuilder\IExpressionBuilder references these at interface-load time.
 */
class ExpressionBuilder
{

    public const EQ  = '=';
    public const NEQ = '<>';
    public const LT  = '<';
    public const LTE = '<=';
    public const GT  = '>';
    public const GTE = '>=';

}//end class
