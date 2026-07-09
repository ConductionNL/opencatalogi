<?php
/**
 * Stub for OC_Util (legacy Nextcloud utility class).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

/**
 * Stub for OC_Util.
 */
class OC_Util
{

    /**
     * Check if the server is configured.
     *
     * @return boolean
     */
    public static function isSetupCompleted(): bool
    {
        return true;

    }//end isSetupCompleted()


    /**
     * Get home directory for user.
     *
     * @param string $userId User ID.
     *
     * @return string
     */
    public static function getUserHome(string $userId): string
    {
        return '/tmp/'.$userId;

    }//end getUserHome()


    /**
     * Add script dependency.
     *
     * @param string $appId   App ID.
     * @param string $scriptName Script name.
     * @param string $afterAppId After app.
     *
     * @return void
     */
    public static function addScript(string $appId, string $scriptName='', string $afterAppId=''): void
    {
        // stub no-op

    }//end addScript()


    /**
     * Add style dependency.
     *
     * @param string $appId      App ID.
     * @param string $styleName  Style name.
     *
     * @return void
     */
    public static function addStyle(string $appId, string $styleName=''): void
    {
        // stub no-op

    }//end addStyle()


}//end class
