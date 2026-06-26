<?php
/**
 * Stub for Doctrine\DBAL\Types\Types.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs\Doctrine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Minimal stub for Doctrine DBAL Types constants.
 */
class Types
{
    public const STRING   = 'string';
    public const TEXT     = 'text';
    public const INTEGER  = 'integer';
    public const BIGINT   = 'bigint';
    public const BOOLEAN  = 'boolean';
    public const FLOAT    = 'float';
    public const DECIMAL  = 'decimal';
    public const DATE     = 'date';
    public const DATETIME = 'datetime';
    // Nextcloud adds *_MUTABLE and *_IMMUTABLE aliases.
    public const DATE_MUTABLE          = 'date';
    public const DATETIME_MUTABLE      = 'datetime';
    public const TIME_MUTABLE          = 'time';
    public const DATETIMETZ_MUTABLE    = 'datetimetz';
    public const DATETIME_IMMUTABLE    = 'datetime_immutable';
    public const DATE_IMMUTABLE        = 'date_immutable';
    public const TIME_IMMUTABLE        = 'time_immutable';
    public const DATETIMETZ_IMMUTABLE  = 'datetimetz_immutable';
    public const JSON     = 'json';
    public const ARRAY    = 'array';
    public const BLOB     = 'blob';
    public const GUID     = 'guid';
    public const ASCII_STRING = 'ascii_string';
    public const BINARY   = 'binary';

}//end class
