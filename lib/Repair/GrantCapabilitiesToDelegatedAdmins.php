<?php

/**
 * OpenCatalogi repair step: grant the capability rights to the groups delegated the admin section.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;

/**
 * Nobody loses a capability on upgrade (REQ-WAB-004, task 4.4).
 *
 * Before this change a group with Nextcloud's delegated access to the
 * OpenCatalogi admin section could open inspections, depublish and the rest.
 * This step lists those groups under every capability verb in the `catalog`
 * schema's authorization block. Grants already there are kept; a block that
 * already holds every group is not written.
 */
class GrantCapabilitiesToDelegatedAdmins implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether OpenRegister is enabled.
	 * @param IDBConnection      $db         Reads Nextcloud's admin delegations.
	 * @param ContainerInterface $container  For OpenRegister's schema mapper.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Grant the capability rights to the groups delegated the OpenCatalogi admin section';
	}//end getName()

	/**
	 * Add the delegated groups to every capability verb of the catalog schema.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			$output->warning('OpenRegister is not enabled; the capability rights were not granted');
			return;
		}

		try {
			$groups = $this->delegatedGroups();
			if ($groups === []) {
				$output->info('No group is delegated the OpenCatalogi admin section; no capability right granted');
				return;
			}

			$mapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$schema = $mapper->find(CapabilityRights::CATALOG_SCHEMA);
			$before = $schema->getAuthorization();
			if (is_array($before) === false) {
				$before = [];
			}

			$after = self::granted(authorization: $before, groups: $groups);
			if ($after === $before) {
				$output->info('The delegated groups already hold every capability right');
				return;
			}

			$schema->setAuthorization($after);
			$mapper->update($schema);
			$output->info('Capability rights granted to '.count($groups).' delegated group(s)');
		} catch (\Throwable $e) {
			$output->warning('The capability rights could not be granted: '.$e->getMessage());
		}

	}//end run()

	/**
	 * The authorization block with the groups added under every capability verb.
	 *
	 * @param array             $authorization The block.
	 * @param array<int,string> $groups        The groups to add.
	 *
	 * @return array The block, other keys and existing grants untouched.
	 */
	public static function granted(array $authorization, array $groups): array {
		foreach (array_keys(CapabilityRights::VERBS) as $verb) {
			$current = $authorization[$verb] ?? [];
			if (is_array($current) === false) {
				$current = [];
			}

			$merged = $current;
			foreach ($groups as $group) {
				if (in_array($group, $merged, true) === false) {
					$merged[] = $group;
				}
			}

			if ($merged !== $current || array_key_exists($verb, $authorization) === false) {
				$authorization[$verb] = $merged;
			}
		}

		return $authorization;

	}//end granted()

	/**
	 * The groups Nextcloud delegated the OpenCatalogi admin section to.
	 *
	 * @return array<int,string> The group ids.
	 */
	private function delegatedGroups(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('group_id')
			->from('authorized_groups')
			->where($query->expr()->eq('class', $query->createNamedParameter(OpenCatalogiAdmin::class)));

		$result = $query->executeQuery();
		$groups = [];
		while (($row = $result->fetch()) !== false) {
			if (is_string($row['group_id'] ?? null) === true && $row['group_id'] !== '') {
				$groups[] = $row['group_id'];
			}
		}

		$result->closeCursor();

		return array_values(array_unique($groups));

	}//end delegatedGroups()
}//end class
