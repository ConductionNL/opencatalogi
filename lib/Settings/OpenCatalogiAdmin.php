<?php
/**
 * OpenCatalogi Admin Settings.
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

class OpenCatalogiAdmin implements ISettings
{

    /**
     * Localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * Configuration service.
     *
     * @var IConfig
     */
    private IConfig $config;


    /**
     * Constructor for OpenCatalogiAdmin settings.
     *
     * @param IConfig $config Configuration service.
     * @param IL10N   $l      Localization service.
     */
    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;

    }//end __construct()


    /**
     * Get the admin settings form response.
     *
     * @return TemplateResponse The settings form template response.
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue('open_catalogi_setting', true),
        ];

        return new TemplateResponse('opencatalogi', 'settings/admin', $parameters, 'admin');

    }//end getForm()


    /**
     * Get the section this settings page belongs to.
     *
     * @return string The name of the settings section.
     */
    public function getSection()
    {
        // Name of the previously created section.
        $sectionName = 'opencatalogi';
        return $sectionName;

    }//end getSection()


    /**
     * Get the priority of this settings form.
     *
     * Determines whether the form should be rather on the top or bottom of
     * the admin section. Forms are arranged in ascending order of priority
     * values. Must return a value between 0 and 100.
     *
     * @return integer The priority value (e.g. 70).
     */
    public function getPriority()
    {
        return 10;

    }//end getPriority()


}//end class
