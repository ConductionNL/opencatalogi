<?php

/**
 * OpenCatalogi capability rights table: the eight rights and the groups granted each, as the admin settings edit them.
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

use InvalidArgumentException;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;

/**
 * Reads and writes the grants of the eight rights in the `catalog` schema's
 * authorization block, through OpenRegister's schema mapper.
 *
 * Only the eight verb keys are ever written; every other rule in the block
 * (the public read rules among them) is kept exactly as it was.
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
class CapabilityRightsTable {

	/**
	 * OpenRegister's schema mapper, resolved from the container.
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Resolves OpenRegister's schema mapper.
	 * @param IGroupManager      $groupManager Checks that a granted group exists.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * The eight rights in declaration order, each with its description and granted groups.
	 *
	 * @return array<int, array{verb: string, description: string, groups: array<int, string>}> The rows.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function rows(): array {
		$authorization = $this->authorization(schema: $this->catalogSchema());

		$rows = [];
		foreach (CapabilityRights::VERBS as $verb => $description) {
			$rows[] = [
				'verb'        => $verb,
				'description' => $description,
				'groups'      => self::groupsOf(value: ($authorization[$verb] ?? null)),
			];
		}

		return $rows;

	}//end rows()

	/**
	 * Grant one right to exactly these groups, replacing its earlier grant.
	 *
	 * @param string             $verb   One of CapabilityRights::VERBS.
	 * @param array<int, mixed>  $groups The group ids; each must exist.
	 *
	 * @return array<int, array{verb: string, description: string, groups: array<int, string>}> The rows after the change.
	 *
	 * @throws InvalidArgumentException When the verb is unknown, a group id is not a string or a group does not exist.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function grant(string $verb, array $groups): array {
		if (array_key_exists($verb, CapabilityRights::VERBS) === false) {
			throw new InvalidArgumentException('Unknown right: '.$verb);
		}

		$granted = [];
		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				throw new InvalidArgumentException('A group must be named by its id.');
			}

			if ($this->groupManager->groupExists($group) === false) {
				throw new InvalidArgumentException('No such group: '.$group);
			}

			if (in_array($group, $granted, true) === false) {
				$granted[] = $group;
			}
		}

		$mapper = $this->container->get(self::SCHEMA_MAPPER);
		$schema = $mapper->find(CapabilityRights::CATALOG_SCHEMA);
		$authorization = $this->authorization(schema: $schema);
		$authorization[$verb] = $granted;
		$schema->setAuthorization($authorization);
		$mapper->update($schema);

		return $this->rows();

	}//end grant()

	/**
	 * The catalog schema, through OpenRegister's schema mapper.
	 *
	 * @return object The schema entity.
	 */
	private function catalogSchema(): object {
		return $this->container->get(self::SCHEMA_MAPPER)->find(CapabilityRights::CATALOG_SCHEMA);

	}//end catalogSchema()

	/**
	 * A schema's authorization block, or [] when it has none.
	 *
	 * @param object $schema The schema entity.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function authorization(object $schema): array {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false) {
			return [];
		}

		return $authorization;

	}//end authorization()

	/**
	 * The group ids of one grant, ignoring anything that is not a group id.
	 *
	 * @param mixed $value The grant as stored.
	 *
	 * @return array<int, string> The group ids.
	 */
	private static function groupsOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn ($group): bool => is_string($group) === true && $group !== ''));

	}//end groupsOf()
}//end class
