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

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * The one place a store asks whether OpenRegister is installed and resolves its ObjectService.
 *
 * OpenRegister is optional at runtime (ADR-083), so a caller asks isInstalled()
 * before objectService(). Each caller keeps its own refusal for an instance
 * without OpenRegister; this class only answers the two questions.
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
class OpenRegisterLookup {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the OpenRegister ObjectService.
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
}//end class
