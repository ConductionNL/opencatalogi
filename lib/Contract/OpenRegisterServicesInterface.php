<?php

/**
 * Contract of OpenRegisterLookup that also resolves any OpenRegister service by its container id.
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
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of OpenRegisterLookup that also resolves any OpenRegister service by its container id.
 *
 * Callers that need an OpenRegister service other than the ObjectService depend on
 * this interface instead of on the class.
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
interface OpenRegisterServicesInterface extends OpenRegisterLookupInterface {
	/**
	 * An OpenRegister service from the container when OpenRegister is among the installed apps.
	 *
	 * @param string $serviceId The container id of the service.
	 * @param string $unavailable The message of the refusal when OpenRegister is not installed.
	 *
	 * @return mixed The service.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function installedService(string $serviceId, string $unavailable): mixed;
}//end interface
