<?php

/**
 * Stub for OCA\OpenRegister\Service\ConfigLookup.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Mirrors the real value object: one app-config lookup for the register resolver.
 */
final class ConfigLookup {

	/**
	 * Constructor.
	 *
	 * @param string $appId Consumer app id.
	 * @param string $configKey Config key to read.
	 * @param string|null $default Fallback slug/UUID if the config is unset.
	 * @param string|null $organisationUuid Optional explicit-tenant override.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $configKey,
		public readonly ?string $default = null,
		public readonly ?string $organisationUuid = null,
	) {
	}//end __construct()
}//end class
