<?php

/**
 * Configured register ids, and the OpenRegister services they are read through.
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
 * @spec exclude pure framework plumbing: reads configured ids and resolves OpenRegister services.
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Reads a configured id from the opencatalogi app config, and resolves an
 * OpenRegister service from the server container.
 *
 * @spec exclude pure framework plumbing: reads configured ids and resolves OpenRegister services.
 */
class RegisterBindings {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container for resolving OpenRegister.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * A configured id, or null when the key is unset or empty.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string|null The id.
	 *
	 * @spec exclude pure framework plumbing: reads a configured id.
	 */
	public function configuredId(string $key): ?string {
		$value = (string)$this->config->getValueString(AppIdentity::APP_ID, $key, '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end configuredId()

	/**
	 * An OpenRegister service from the server container.
	 *
	 * @param string $serviceId The container id of the service.
	 *
	 * @return mixed The service.
	 *
	 * @throws \Psr\Container\ContainerExceptionInterface When the container cannot provide it.
	 *
	 * @spec exclude pure framework plumbing: resolves an OpenRegister service.
	 */
	public function service(string $serviceId): mixed {
		return $this->container->get($serviceId);
	}//end service()
}//end class
