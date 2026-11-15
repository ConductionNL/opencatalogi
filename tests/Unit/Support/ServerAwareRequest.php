<?php

/**
 * An IRequest test double base with the `server` array the real request exposes.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Support;

use OCP\IRequest;

/**
 * Nextcloud's Request answers `$request->server` through __get; a mock of the
 * IRequest interface has no such property, so setting it creates a deprecated
 * dynamic property. Mocks of this class declare it.
 */
abstract class ServerAwareRequest implements IRequest {

	/**
	 * The server variables, as `$request->server` reads them.
	 *
	 * @var array<string, mixed>
	 */
	public array $server = [];
}//end class
