<?php
/**
 * Directory URL exception for OpenCatalogi.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenCatalogi\Exception;

use Exception;

/**
 * Exception thrown when a directory URL is invalid.
 */
class DirectoryUrlException extends Exception
{
    /**
     * Set the exception message.
     *
     * @param string $message The error message.
     *
     * @return void
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;

    }//end setMessage()
}//end class
