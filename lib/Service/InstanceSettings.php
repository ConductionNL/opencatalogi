<?php

/**
 * The instance base URL and the app's own configuration values.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec exclude pure framework plumbing: reads the base URL and opencatalogi app-config values.
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Reads the instance base URL and the opencatalogi app-config values that the
 * feed and sitemap documents are built from.
 *
 * @spec exclude pure framework plumbing: reads the base URL and opencatalogi app-config values.
 */
class InstanceSettings {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator, for the base URL.
	 * @param IAppConfig    $appConfig    The app configuration.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The instance base URL without a trailing slash.
	 *
	 * @return string The base URL.
	 *
	 * @spec exclude pure framework plumbing: reads the base URL.
	 */
	public function baseUrl(): string {
		return rtrim($this->urlGenerator->getBaseUrl(), '/');

	}//end baseUrl()

	/**
	 * A string app-config value of opencatalogi.
	 *
	 * @param string $key     The config key.
	 * @param string $default The value when the key is not set.
	 *
	 * @return string The configured value.
	 *
	 * @spec exclude pure framework plumbing: reads an app-config value.
	 */
	public function stringValue(string $key, string $default): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, $key, $default);

	}//end stringValue()

	/**
	 * An integer app-config value of opencatalogi.
	 *
	 * @param string $key     The config key.
	 * @param int    $default The value when the key is not set.
	 *
	 * @return int The configured value.
	 *
	 * @spec exclude pure framework plumbing: reads an app-config value.
	 */
	public function intValue(string $key, int $default): int {
		return $this->appConfig->getValueInt(AppIdentity::APP_ID, $key, $default);

	}//end intValue()
}//end class
