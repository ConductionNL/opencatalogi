<?php
/**
 * OpenCatalogi admin settings page.
 *
 * @category Settings
 * @package  OCA\OpenCatalogi\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

/**
 * Admin settings page for OpenCatalogi.
 */
class OpenCatalogiAdmin implements ISettings
{

    /**
     * Localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * System configuration.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Constructor.
     *
     * @param IConfig $config System configuration.
     * @param IL10N   $l      Localization service.
     */
    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;

    }//end __construct()

    /**
     * Get the admin settings form.
     *
     * @return TemplateResponse
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue(key: 'open_catalogi_setting', default: true),
        ];

        return new TemplateResponse(
            appName: 'opencatalogi',
            templateName: 'settings/admin',
            params: $parameters,
            renderAs: 'admin'
        );

    }//end getForm()

    /**
     * Get the settings section name.
     *
     * @return string
     */
    public function getSection()
    {
        // Name of the previously created section.
        $sectionName = 'opencatalogi';
        return $sectionName;

    }//end getSection()

    /**
     * Get the form priority within the admin section.
     *
     * The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     *
     * @return integer
     */
    public function getPriority()
    {
        return 10;

    }//end getPriority()
}//end class
