<?php

/**
 * OpenCatalogi Hand Records To System repair step.
 *
 * Hands the depublication, inspection, comment-period, community and Woo-request
 * records an editor came to own back to the system identity, the owner these
 * records are written with since they are saved unowned.
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
 * @spec openspec/specs/publications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Repair step: hand the records of the system-owned schemas to the system identity.
 *
 * Runs inside an OpenRegister system operation and uses OpenRegister's
 * release-to-system call, which writes one audit entry per record and leaves
 * a record the system already owns alone, so the step can run again. On an
 * OpenRegister without that call it does nothing and says so.
 *
 * @spec openspec/specs/publications/spec.md
 */
class HandRecordsToSystem implements IRepairStep {

	/**
	 * The schemas whose records the system owns, by their config key in the publication register.
	 */
	public const SCHEMA_KEYS = [
		'depublication_schema',
		'inspection_schema',
		'comment_period_schema',
		'service_status_schema',
		'woo_request_schema',
	];

	/**
	 * Records read per page.
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Whether OpenRegister is enabled.
	 * @param IAppConfig         $config     The configured register and schemas.
	 * @param ContainerInterface $container  Lazy OpenRegister lookups.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function getName(): string {
		return 'Hand depublication, inspection, comment-period, community and Woo-request records to the system';
	}//end getName()

	/**
	 * Hand every record of the system-owned schemas to the system.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function run(IOutput $output): void {
		$services = $this->openRegister(output: $output);
		if ($services === null) {
			return;
		}

		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		if ($register === '') {
			$output->info('No publication register configured; no records to hand to the system');
			return;
		}

		$handed = 0;
		$services['objects']->runAsSystem(function () use ($services, $register, $output, &$handed): void {
			foreach (self::SCHEMA_KEYS as $key) {
				$schema = trim($this->config->getValueString('opencatalogi', $key, ''));
				if ($schema === '') {
					continue;
				}

				try {
					$handed += $this->handSchema(services: $services, register: $register, schema: $schema);
				} catch (Throwable $e) {
					$output->warning($key . ': skipped, ' . $e->getMessage());
				}
			}
		});

		$output->info(sprintf('Handed %d record(s) to the system.', $handed));
	}//end run()

	/**
	 * Hand one schema's records to the system, page by page.
	 *
	 * @param array  $services The OpenRegister services.
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return int The records handed over.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function handSchema(array $services, string $register, string $schema): int {
		$registerEntity = $services['registers']->find($register);
		$schemaEntity = $services['schemas']->find($schema);

		$handed = 0;
		$page = 1;
		do {
			$result = $services['objects']->searchObjectsPaginated(
				['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE, '_page' => $page],
				_rbac: false,
				_multitenancy: false
			);
			$rows = ($result['results'] ?? []);
			foreach ($rows as $row) {
				$handed += $this->handRow(services: $services, row: $row, tables: [$registerEntity, $schemaEntity]);
			}

			$page++;
		} while (count($rows) === self::PAGE);

		return $handed;
	}//end handSchema()

	/**
	 * Hand one record to the system when an owner other than the system holds it.
	 *
	 * @param array $services The OpenRegister services.
	 * @param mixed $row      The listed record.
	 * @param array $tables   The register and schema entities.
	 *
	 * @return int 1 when the record changed hands, else 0.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function handRow(array $services, mixed $row, array $tables): int {
		$uuid = self::uuidOf(row: $row);
		if ($uuid === null) {
			return 0;
		}

		$object = $services['objects']->find(
			id: $uuid,
			register: $tables[0],
			schema: $tables[1],
			_rbac: false,
			_multitenancy: false,
			_render: false,
			_audit: false
		);
		if ($object === null || in_array($object->getOwner(), [null, ''], true) === true) {
			return 0;
		}

		$record = new \OCA\OpenRegister\Service\Rbac\OwnedRecord(register: $tables[0], schema: $tables[1], object: $object);
		$outcome = $services['ownership']->releaseToSystem($record);

		return (int)(($outcome['changed'] ?? false) === true);
	}//end handRow()

	/**
	 * The uuid of a listed record, rendered or as an entity.
	 *
	 * @param mixed $row The listed record.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private static function uuidOf(mixed $row): ?string {
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			return $row->getUuid();
		}

		if (is_array($row) === true) {
			$uuid = ($row['@self']['id'] ?? $row['id'] ?? null);
			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end uuidOf()

	/**
	 * The OpenRegister services this step needs, or null when OpenRegister cannot hand records to the system.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return array|null The services.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function openRegister(IOutput $output): ?array {
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			$output->warning('OpenRegister is not enabled; no records were handed to the system');
			return null;
		}

		try {
			$services = [
				'objects' => $this->container->get('OCA\OpenRegister\Service\ObjectService'),
				'ownership' => $this->container->get('OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface'),
				'registers' => $this->container->get('OCA\OpenRegister\Db\RegisterMapper'),
				'schemas' => $this->container->get('OCA\OpenRegister\Db\SchemaMapper'),
			];
		} catch (Throwable $e) {
			$output->warning('OpenRegister services unavailable; no records were handed to the system: ' . $e->getMessage());
			return null;
		}

		if (method_exists($services['ownership'], 'releaseToSystem') === false
			|| method_exists($services['objects'], 'runAsSystem') === false
			|| class_exists('OCA\OpenRegister\Service\Rbac\OwnedRecord') === false
		) {
			$output->info('This OpenRegister cannot hand records to the system yet; nothing was changed');
			return null;
		}

		return $services;
	}//end openRegister()
}//end class
