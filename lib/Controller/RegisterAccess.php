<?php

/**
 * Configured register pairs and the service catalogue's ObjectService, for controllers.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;

/**
 * Resolves a configured register and schema pair, and reaches OpenRegister's
 * ObjectService through the service catalogue, which refuses with
 * CatalogueUnreadableException when OpenRegister is missing or unreachable.
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
class RegisterAccess {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface      $openRegister Resolves the configured register pairs.
	 * @param ServiceCatalogueServiceInterface $catalogue    Reaches the ObjectService.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly ServiceCatalogueServiceInterface $catalogue,
	) {

	}//end __construct()

	/**
	 * Resolve the register and schema identifiers for a configuration context.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey   The `<context>_schema` config key.
	 *
	 * @return array<string, string> Map with 'register' and 'schema' identifiers.
	 *
	 * @throws \RuntimeException When neither the resolver nor the app config can be reached.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function registerConfiguration(string $registerKey, string $schemaKey): array {
		return $this->openRegister->registerConfiguration(registerKey: $registerKey, schemaKey: $schemaKey);

	}//end registerConfiguration()

	/**
	 * The OpenRegister ObjectService, as the service catalogue reaches it.
	 *
	 * @return object The service.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function getObjectService(): object {
		return $this->catalogue->getObjectService();

	}//end getObjectService()
}//end class
