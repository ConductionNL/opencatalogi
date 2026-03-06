<?php
/**
 * OpenCatalogiAdmin for OpenCatalogi.
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
 * Admin settings form for OpenCatalogi.
 */
class OpenCatalogiAdmin implements ISettings
{

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * The Nextcloud config service.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Constructor for OpenCatalogiAdmin settings.
     *
     * @param IConfig $config The Nextcloud config service.
     * @param IL10N   $l      The localization service.
     */
    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;

    }//end __construct()

    /**
     * Get the settings form template response.
     *
     * @return TemplateResponse The admin settings template.
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue('open_catalogi_setting', true),
        ];

        return new TemplateResponse('opencatalogi', 'settings/admin', $parameters, 'admin');

    }//end getForm()

    /**
     * Get the section ID for these settings.
     *
     * @return string The section name.
     */
    public function getSection()
    {
        // Name of the previously created section.
        $sectionName = 'opencatalogi';
        return $sectionName;

    }//end getSection()

    /**
     * Get the priority for ordering the settings form.
     *
     * Whether the form should be rather on the top or bottom of
     * the admin section. The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     *
     * @return integer The priority value.
     */
    public function getPriority()
    {
        return 10;

    }//end getPriority()
}//end class
