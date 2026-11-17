<?php

/**
 * Who receives the Woo pipeline incidents, and which ones are open.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCP\IAppConfig;
use OCP\IGroupManager;
use RuntimeException;

/**
 * The incident group lives in app config `woo_incident_group`. Saving it also assigns
 * that group to the `woo-incident-receiver` role of the `wooPipelineIncident` schema,
 * because the schema's notification rule addresses the role: the group setting is what
 * the notifications and e-mails go to (REQ-WNO-004).
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
class PipelineIncidentSettings {
	use ReadsObjectProperties;

	/**
	 * The app config key of the incident group.
	 *
	 * @var string
	 */
	public const GROUP_KEY = 'woo_incident_group';

	/**
	 * The role the schema's notification rule addresses.
	 *
	 * @var string
	 */
	public const ROLE = 'woo-incident-receiver';

	/**
	 * The most open incidents listed.
	 *
	 * @var int
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config       Holds the group.
	 * @param IGroupManager      $groups       Says whether a group exists.
	 * @param OpenRegisterLookup $openRegister The incident schema and its objects.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IGroupManager $groups,
		private readonly OpenRegisterLookup $openRegister,
	) {
	}//end __construct()

	/**
	 * The group that receives pipeline incidents, '' when nobody does.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	public function group(): string {
		return trim($this->config->getValueString(AppIdentity::APP_ID, self::GROUP_KEY, ''));
	}//end group()

	/**
	 * Set the incident group and assign it to the receiver role of the incident schema.
	 *
	 * An empty group clears both. A group that does not exist is refused. When the role
	 * cannot be assigned the setting is not saved either, so the setting never claims
	 * a receiver the notification rule does not reach.
	 *
	 * @param string $group The group id, or '' for nobody.
	 *
	 * @return string The group saved.
	 *
	 * @throws InvalidArgumentException When the group does not exist.
	 * @throws RuntimeException When the incident schema cannot be updated.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	public function setGroup(string $group): string {
		$group = trim($group);
		if ($group !== '' && $this->groups->groupExists($group) === false) {
			throw new InvalidArgumentException('The group "' . $group . '" does not exist.');
		}

		$this->assignRole(group: $group);
		$this->config->setValueString(AppIdentity::APP_ID, self::GROUP_KEY, $group);

		return $group;
	}//end setGroup()

	/**
	 * The open incidents, newest first.
	 *
	 * @return list<array{id: string, kind: string, subject: string, message: string, at: string}> The incidents.
	 *
	 * @throws RuntimeException When the incident schema cannot be read.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	public function openIncidents(): array {
		$found = $this->objects()->search(filters: ['_limit' => self::LIST_LIMIT, '_order' => ['at' => 'desc']]);

		$open = [];
		foreach ((array)($this->asRecord(object: $found)['results'] ?? []) as $row) {
			$incident = $this->asRecord(object: $row);
			if (($incident['resolvedAt'] ?? null) !== null && $incident['resolvedAt'] !== '') {
				continue;
			}

			$open[] = [
				'id' => (string)($incident['id'] ?? ($incident['@self']['id'] ?? '')),
				'kind' => (string)($incident['kind'] ?? ''),
				'subject' => (string)($incident['subject'] ?? ''),
				'message' => (string)($incident['message'] ?? ''),
				'at' => (string)($incident['at'] ?? ''),
			];
		}

		usort($open, static fn (array $left, array $right): int => strcmp($right['at'], $left['at']));

		return $open;
	}//end openIncidents()

	/**
	 * Put the group, or nobody, on the receiver role of the incident schema.
	 *
	 * @param string $group The group id, or ''.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the schema cannot be found or updated.
	 */
	private function assignRole(string $group): void {
		$ids = $this->openRegister->registerConfiguration(registerKey: PipelineIncidents::REGISTER_KEY, schemaKey: PipelineIncidents::SCHEMA_KEY);
		$mapper = $this->openRegister->installedService('OCA\OpenRegister\Db\SchemaMapper', 'OpenRegister SchemaMapper is not available.');

		try {
			$schema = $mapper->find($ids['schema']);
			$authorization = $schema->getAuthorization();
			if (is_array($authorization) === false) {
				$authorization = [];
			}

			$roles = $authorization['roles'] ?? [];
			if (is_array($roles) === false) {
				$roles = [];
			}

			$roles[self::ROLE] = [];
			if ($group !== '') {
				$roles[self::ROLE] = [$group];
			}

			$authorization['roles'] = $roles;
			$schema->setAuthorization($authorization);
			$mapper->update($schema);
		} catch (\Throwable $e) {
			throw new RuntimeException('The incident group could not be assigned to the incident schema: ' . $e->getMessage(), 0, $e);
		}
	}//end assignRole()

	/**
	 * The incident objects.
	 *
	 * @return SchemaObjects The objects of the incident schema.
	 */
	private function objects(): SchemaObjects {
		$ids = $this->openRegister->registerConfiguration(registerKey: PipelineIncidents::REGISTER_KEY, schemaKey: PipelineIncidents::SCHEMA_KEY);

		return new SchemaObjects(
			objects: $this->openRegister->installedObjectService(),
			target: [(string)$ids['register'], (string)$ids['schema']]
		);
	}//end objects()
}//end class
