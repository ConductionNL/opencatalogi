<?php

/**
 * OpenCatalogi organisations by TOOI identifier
 *
 * Finds the OpenRegister organisation that carries a TOOI identifier, or
 * creates one with that identifier and the source's name, for the
 * organisation activation of the Woo value-list curation.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
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
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves OpenRegister's organisation mapper and service by name, so the app
 * loads without OpenRegister and an absent register is a refusal, not a crash.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
class OpenRegisterOrganisations {

	/**
	 * OpenRegister's organisation mapper.
	 *
	 * @var string
	 */
	public const MAPPER = 'OCA\\OpenRegister\\Db\\OrganisationMapper';

	/**
	 * OpenRegister's organisation service.
	 *
	 * @var string
	 */
	public const SERVICE = 'OCA\\OpenRegister\\Service\\OrganisationService';

	/**
	 * The details OpenRegister creates an organisation from.
	 *
	 * @var string
	 */
	public const NEW_ORGANISATION = 'OCA\\OpenRegister\\Service\\Organisation\\NewOrganisation';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's classes.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The organisation that carries a TOOI identifier, or null when none does.
	 *
	 * @param string $tooi The TOOI identifier (a uri).
	 *
	 * @return array{uuid: string, name: string}|null The organisation.
	 *
	 * @throws RuntimeException When OpenRegister's organisations cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function findByTooi(string $tooi): ?array {
		$mapper = $this->service(name: self::MAPPER);
		try {
			$found = $mapper->findAll(limit: 1, offset: 0, filters: ['tooi' => $tooi]);
		} catch (Throwable $e) {
			throw new RuntimeException('The organisations could not be read: ' . $e->getMessage(), 0, $e);
		}

		$first = (is_array($found) === true ? reset($found) : false);
		if (is_object($first) === false) {
			return null;
		}

		return ['uuid' => (string)$first->getUuid(), 'name' => (string)$first->getName()];
	}//end findByTooi()

	/**
	 * Create an organisation with a name and a TOOI identifier; nobody is added as its member.
	 *
	 * @param string $name The name, from the TOOI register.
	 * @param string $tooi The TOOI identifier.
	 *
	 * @return array{uuid: string, name: string} The created organisation.
	 *
	 * @throws RuntimeException When OpenRegister refuses or cannot create it.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function create(string $name, string $tooi): array {
		$service = $this->service(name: self::SERVICE);
		$mapper = $this->service(name: self::MAPPER);
		$details = self::NEW_ORGANISATION;
		try {
			$organisation = $service->createOrganisation(new $details(name: $name), false);
			$organisation->setTooi($tooi);
			$saved = $mapper->save($organisation);
		} catch (Throwable $e) {
			throw new RuntimeException('The organisation ' . $name . ' could not be created: ' . $e->getMessage(), 0, $e);
		}

		return ['uuid' => (string)$saved->getUuid(), 'name' => (string)$saved->getName()];
	}//end create()

	/**
	 * One of OpenRegister's services, or a refusal naming it.
	 *
	 * @param string $name The class.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function service(string $name): object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister organisations unavailable: ' . $name, 0, $e);
		}

		if (is_object($service) === false) {
			throw new RuntimeException('OpenRegister organisations unavailable: ' . $name);
		}

		return $service;
	}//end service()
}//end class
