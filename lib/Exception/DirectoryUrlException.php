<?php
/**
 * Exception for invalid directory URL errors in OpenCatalogi.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Exception;

use Exception;

class DirectoryUrlException extends Exception
{


    /**
     * Set the exception message.
     *
     * @param string $message The exception message to set.
     *
     * @return void
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;

    }//end setMessage()


}//end class
