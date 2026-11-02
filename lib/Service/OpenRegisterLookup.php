<?php

/**
 * Whether OpenRegister is installed, and its ObjectService when it is.
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
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The one place a caller asks whether OpenRegister is installed, resolves its
 * ObjectService, and resolves a configured register and schema pair.
 *
 * OpenRegister is optional at runtime (ADR-083), so a caller asks isInstalled()
 * before objectService(). Each caller keeps its own refusal for an instance
 * without OpenRegister; this class only answers the questions.
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
class OpenRegisterLookup {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services and the app config.
	 * @param IAppManager $appManager Answers whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @return bool True when it is.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function isInstalled(): bool {
		return $this->appManager->isInstalled('openregister');

	}//end isInstalled()

	/**
	 * The OpenRegister ObjectService, as the container resolves it.
	 *
	 * @return mixed The service.
	 *
	 * @throws \Throwable Whatever the container throws when it cannot resolve it.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function objectService(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');

	}//end objectService()

	/**
	 * The OpenRegister ObjectService when OpenRegister is among the installed apps.
	 *
	 * @return mixed The service.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function installedObjectService(): mixed {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->objectService();
		}

		throw new RuntimeException('OpenRegister service is not available.');

	}//end installedObjectService()

	/**
	 * Resolve the register and schema identifiers for a configuration context.
	 *
	 * Asks OpenRegister's RegisterResolverService when the container has it, and
	 * otherwise reads the `<context>_register` / `<context>_schema` app-config keys.
	 * There is no empty-string fallback: an unconfigured context raises
	 * MissingConfigException, which callers surface as a 503.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey   The `<context>_schema` config key.
	 *
	 * @return array<string, string> Map with 'register' and 'schema' identifiers (slug or UUID).
	 *
	 * @throws RuntimeException When neither the resolver nor the app config can be reached.
	 * @throws MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	public function registerConfiguration(string $registerKey, string $schemaKey): array {
		$resolver = $this->registerResolver();
		if ($resolver !== null) {
			return [
				'register' => $resolver->resolveRegisterId(AppIdentity::APP_ID, $registerKey),
				'schema' => $resolver->resolveSchemaId(AppIdentity::APP_ID, $schemaKey),
			];
		}

		return $this->configuredRegister(registerKey: $registerKey, schemaKey: $schemaKey);

	}//end registerConfiguration()

	/**
	 * OpenRegister's RegisterResolverService, or null when the container cannot resolve it.
	 *
	 * Returned as a loose `object` because the resolver class is `final`; callers
	 * only use its resolveRegisterId() and resolveSchemaId() methods.
	 *
	 * @return object|null The resolver.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function registerResolver(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\RegisterResolverService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end registerResolver()

	/**
	 * The register and schema pair read from the app config.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey   The `<context>_schema` config key.
	 *
	 * @return array<string, string> Map with 'register' and 'schema' identifiers.
	 *
	 * @throws RuntimeException When the app config cannot be resolved.
	 * @throws MissingConfigException When either key is empty.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function configuredRegister(string $registerKey, string $schemaKey): array {
		try {
			$appConfig = $this->container->get(IAppConfig::class);
		} catch (\Throwable $e) {
			throw new RuntimeException(
				message: 'Cannot resolve register configuration for ' . $registerKey . ': IAppConfig unavailable',
				code: 0,
				previous: $e
			);
		}

		$register = $appConfig->getValueString(AppIdentity::APP_ID, $registerKey, '');
		$schema = $appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new MissingConfigException(
				'Register configuration is not set for ' . $registerKey . ' / ' . $schemaKey
				. '. Run the OpenCatalogi setup wizard or POST to /api/settings/load to initialise registers and schemas.'
			);
		}

		return [
			'register' => $register,
			'schema' => $schema,
		];

	}//end configuredRegister()
}//end class
