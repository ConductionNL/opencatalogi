<?php

/**
 * OpenCatalogi capability rights: the eight Woo capabilities, granted per group and asked of OpenRegister.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The rights an administrator grants per group (REQ-WAB-004).
 *
 * Each verb is declared to OpenRegister, granted in the `catalog` schema's
 * authorization block and decided by CapabilityScopeListener. `may()` asks
 * OpenRegister's permission handler and fails closed.
 */
class CapabilityRights {

	/**
	 * The slug of the schema whose authorization block holds the grants.
	 */
	public const CATALOG_SCHEMA = 'catalog';

	/**
	 * The verbs and the description OpenRegister shows for each.
	 */
	public const VERBS = [
		'openInspection'         => 'Open an inspection period on a publication',
		'openCommentPeriod'      => 'Open a comment period on a publication',
		'depublish'              => 'Depublish a publication and acknowledge a withdrawal',
		'announceNationally'     => 'Announce a publication to the national index',
		'decideRetention'        => 'Decide on the retention of a publication',
		'processWooBatch'        => 'Create and process a Woo batch and its assessments',
		'publishWooBatch'        => 'Publish a Woo batch',
		'requestWooRegistration' => 'Request and confirm the Woo registration of a catalogue',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container The container, for OpenRegister's schema mapper.
	 * @param ServiceCatalogueService $objects   Reaches OpenRegister's object service.
	 * @param LoggerInterface         $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ServiceCatalogueService $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the user holds the capability.
	 *
	 * An unknown verb, an anonymous caller, an unreadable catalog schema or a
	 * failing permission handler all answer false.
	 *
	 * @param string      $verb   One of VERBS.
	 * @param string|null $userId The user, null for anonymous.
	 *
	 * @return bool True when the user may.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function may(string $verb, ?string $userId): bool {
		if (array_key_exists($verb, self::VERBS) === false || $userId === null || $userId === '') {
			return false;
		}

		try {
			$schema = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find(self::CATALOG_SCHEMA);

			return $this->objects->getObjectService()->getPermissionHandler()->hasPermission(
				schema: $schema,
				action: $verb,
				userId: $userId
			) === true;
		} catch (\Throwable $e) {
			$this->logger->warning('[CapabilityRights] The right '.$verb.' could not be checked: '.$e->getMessage());
			return false;
		}

	}//end may()
}//end class
