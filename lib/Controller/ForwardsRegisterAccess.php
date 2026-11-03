<?php

/**
 * Forwards the register-pair resolution and the ObjectService of a held RegisterAccess.
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

/**
 * For a controller collaborator that holds a `RegisterAccess $registers`, so a
 * controller can keep reading register pairs through its `$registers` property
 * (the ResolvesRegisterConfiguration and ReadsPublicationRegister traits).
 *
 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
 */
trait ForwardsRegisterAccess {

	/**
	 * Resolve the register and schema identifiers for a configuration context.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey The `<context>_schema` config key.
	 *
	 * @return array{register: string, schema: string} The resolved identifiers.
	 *
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function registerConfiguration(string $registerKey, string $schemaKey): array {
		return $this->registers->registerConfiguration(registerKey: $registerKey, schemaKey: $schemaKey);
	}//end registerConfiguration()

	/**
	 * OpenRegister's ObjectService through the service catalogue.
	 *
	 * @return object The ObjectService.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException When OpenRegister is missing or unreachable.
	 *
	 * @spec exclude pure framework plumbing: the runtime-guarded OpenRegister dependency (ADR-083).
	 */
	public function getObjectService(): object {
		return $this->registers->getObjectService();
	}//end getObjectService()
}//end trait
