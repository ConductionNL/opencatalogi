<?php

/**
 * Contract of OpenRegisterLookup, for callers in other components.
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
 * Contract of OpenRegisterLookup, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
interface OpenRegisterLookupInterface {
	/**
	 * Whether OpenRegister is installed.
	 *
	 * @return bool True when it is.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function isInstalled(): bool;

	/**
	 * The OpenRegister ObjectService, as the container resolves it.
	 *
	 * @return mixed The service.
	 *
	 * @throws \Throwable Whatever the container throws when it cannot resolve it.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function objectService(): mixed;

	/**
	 * The OpenRegister ObjectService when OpenRegister is among the installed apps.
	 *
	 * @return mixed The service.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function installedObjectService(): mixed;

	/**
	 * Resolve the register and schema identifiers for a configuration context.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey The `<context>_schema` config key.
	 *
	 * @return array<string, string> Map with 'register' and 'schema' identifiers (slug or UUID).
	 *
	 * @throws \RuntimeException When neither the resolver nor the app config can be reached.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	public function registerConfiguration(string $registerKey, string $schemaKey): array;
}//end interface
