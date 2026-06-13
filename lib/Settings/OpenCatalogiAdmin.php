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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\IDelegatedSettings;

/**
 * Admin settings page for OpenCatalogi.
 *
 * Implements IDelegatedSettings so that SettingsController::create() can be
 * annotated with #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)].
 * This makes the settings write endpoint auditable via NC's delegated-admin system
 * and explicitly declares which app-config keys the section is authorised to modify.
 */
class OpenCatalogiAdmin implements IDelegatedSettings
{

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
     */
    public function __construct(IConfig $config)
    {
        $this->config = $config;

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

    /**
     * Get the name of the settings section for delegated-admin display.
     *
     * Required by IDelegatedSettings. Returns null so only the section name
     * is shown (no sub-item label needed for this single-section app).
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return null;

    }//end getName()

    /**
     * Declare which app-config keys this settings section is authorised to write.
     *
     * Used by Nextcloud's delegated-admin system to gate partial-admin access.
     * Lists all keys managed via SettingsService::updateSettings().
     *
     * @return array<string, array<string>>
     */
    public function getAuthorizedAppConfig(): array
    {
        return [
            'opencatalogi' => [
                '/^(catalog_register|catalog_schema|listing_register|listing_schema'
                .'|auto_publish_attachments|auto_publish_objects|use_old_style_publishing_view)$/',
            ],
        ];

    }//end getAuthorizedAppConfig()
}//end class
