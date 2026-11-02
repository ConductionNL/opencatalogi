<?php

/**
 * The identity of the OpenCatalogi app: its id and its canonical directory.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The identity of the OpenCatalogi app.
 *
 * Components read the app id and the directory URL here, so they do not depend
 * on the Application class that wires them. Application::APP_ID and
 * Application::DEFAULT_DIRECTORY_URL keep the same values for other apps.
 *
 * @spec exclude Constants only, no behaviour of their own.
 */
final class AppIdentity {

	/**
	 * The app id.
	 *
	 * @var string
	 */
	public const APP_ID = 'opencatalogi';

	/**
	 * Canonical national OpenCatalogi directory URL.
	 *
	 * Single source of truth for the federated-network endpoint that
	 * DirectoryService, the Add-Directory modal and the first-time-setup
	 * `connect-federation` action all default to. An instance may override it
	 * with the `default_directory_url` app-config key (see
	 * DirectoryService::getDefaultDirectoryUrl()).
	 *
	 * @var string
	 */
	public const DEFAULT_DIRECTORY_URL = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';
}//end class
