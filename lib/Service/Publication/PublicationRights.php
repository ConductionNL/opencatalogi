<?php

/**
 * OpenCatalogi Publication Rights.
 *
 * Whether the current user may update one publication, asked of OpenRegister's
 * own permission handler on that object. Publishing and withdrawing send
 * letters to national channels before anything is saved, so the right has to
 * be known BEFORE the first side effect, not discovered by a save that fails
 * after the letters went out.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The update right on one publication, fail closed.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
class PublicationRights {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container Server container, for OpenRegister's schema mapper.
	 * @param ServiceCatalogueService $objects   The OpenRegister reader.
	 * @param LoggerInterface         $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ServiceCatalogueService $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the current user may update this publication.
	 *
	 * Any failure to ask answers no: a check that could not be made is not a
	 * check that passed (ADR-005, fail closed).
	 *
	 * @param object $publication The publication as OpenRegister returned it.
	 *
	 * @return boolean True only when OpenRegister grants update on it.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function mayUpdate(object $publication): bool {
		try {
			$schema = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($publication->getSchema());
			$owner = $publication->getOwner();
			if (is_string($owner) === false) {
				$owner = null;
			}

			return $this->objects->getObjectService()->getPermissionHandler()->hasPermission(
				schema: $schema,
				action: 'update',
				objectOwner: $owner,
				object: $publication
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicationRights] The update right could not be checked: ' . $e->getMessage());
			return false;
		}

	}//end mayUpdate()
}//end class
