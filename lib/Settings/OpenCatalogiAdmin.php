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

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use Throwable;
use OCP\Settings\IDelegatedSettings;

/**
 * Admin settings page for OpenCatalogi.
 *
 * Implements IDelegatedSettings so that SettingsController::create() can be
 * annotated with #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)].
 * This makes the settings write endpoint auditable via NC's delegated-admin system
 * and explicitly declares which app-config keys the section is authorised to modify.
 */
class OpenCatalogiAdmin implements IDelegatedSettings {

	/**
	 * System configuration.
	 *
	 * @var IConfig
	 */
	private IConfig $config;

	/**
	 * The initial-state key the settings page reads its version information from.
	 *
	 * @var string
	 */
	public const VERSION_INFO_STATE = 'version-info';

	/**
	 * Constructor.
	 *
	 * @param IConfig                       $config       System configuration.
	 * @param IInitialState|null            $initialState Hands values to the settings page with the page itself.
	 * @param SettingsServiceInterface|null $settings     Reads the app and configuration versions.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		IConfig $config,
		private readonly ?IInitialState $initialState = null,
		private readonly ?SettingsServiceInterface $settings = null,
	) {
		$this->config = $config;

	}//end __construct()

	/**
	 * Get the admin settings form.
	 *
	 * The version information goes with the page as initial state, so the
	 * version card is filled before the page's own requests answer.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-10
	 */
	public function getForm() {
		$this->provideVersionInfo();

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
	 * Hand the version information to the page, when it can be read.
	 *
	 * Without it the page asks `GET /api/settings/version` itself, as before,
	 * so a failing read here leaves the page working and only costs a request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-10
	 */
	private function provideVersionInfo(): void {
		if ($this->initialState === null || $this->settings === null) {
			return;
		}

		try {
			$versionInfo = $this->settings->getVersionInfo();
		} catch (Throwable) {
			// The page reads the version itself when no initial state is provided.
			return;
		}

		$this->initialState->provideInitialState(self::VERSION_INFO_STATE, $versionInfo);

	}//end provideVersionInfo()

	/**
	 * Get the settings section name.
	 *
	 * @return string
	 */
	public function getSection() {
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
	public function getPriority() {
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
	public function getName(): ?string {
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
	public function getAuthorizedAppConfig(): array {
		return [
			'opencatalogi' => [
				'/^(catalog_register|catalog_schema|listing_register|listing_schema'
				. '|auto_publish_attachments|auto_publish_objects|use_old_style_publishing_view'
				. '|dcat_publisher_name|dcat_publisher_uri|dcat_default_license|dcat_contact_point'
				. '|dcat_instance_title'
				. '|woo_index_registration_status|woo_index_registration_url|woo_index_registration_at'
				. '|woo_readiness_report)$/',
			],
		];

	}//end getAuthorizedAppConfig()
}//end class
