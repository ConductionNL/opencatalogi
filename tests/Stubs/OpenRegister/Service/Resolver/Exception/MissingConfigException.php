<?php
/**
 * Stub for OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver\Exception;

/**
 * Stub for MissingConfigException.
 */
class MissingConfigException extends \Exception
{

    /**
     * @var string
     */
    private string $appId;

    /**
     * @var string
     */
    private string $configKey;

    /**
     * Constructor.
     *
     * @param string          $appId     The app ID.
     * @param string          $configKey The missing config key.
     * @param \Exception|null $previous  Previous exception.
     */
    public function __construct(string $appId, string $configKey, ?\Exception $previous=null)
    {
        $this->appId     = $appId;
        $this->configKey = $configKey;
        parent::__construct("Missing config '$configKey' for app '$appId'", 0, $previous);

    }//end __construct()


    /**
     * Get the app ID.
     *
     * @return string
     */
    public function getAppId(): string
    {
        return $this->appId;

    }//end getAppId()


    /**
     * Get the config key.
     *
     * @return string
     */
    public function getConfigKey(): string
    {
        return $this->configKey;

    }//end getConfigKey()


}//end class
