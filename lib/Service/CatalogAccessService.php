<?php

/**
 * OpenCatalogi Catalog Access Service.
 *
 * Turns the staff access of the catalogues into OpenRegister authorization
 * rules on the schemas they cover, so OpenRegister enforces who may see and
 * edit a catalogue's publications on every read and write. OpenCatalogi never
 * evaluates access itself (ADR-022); it only writes the rules.
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
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\CatalogAccessServiceInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recomputes the authorization of a schema from the catalogues that cover it.
 *
 * Design D2: while no covering catalogue is restricted, the schema keeps the
 * rules it has (and gets back the rules it had before a restriction, kept in
 * IAppConfig). Once one is restricted, every covering catalogue contributes:
 * a restricted one its read groups to `read` and its edit groups to `read`,
 * `create`, `update` and `delete`; an unrestricted one the signed-in rule. A
 * catalogue with filters narrows its rules to them. The public read rules
 * stay first in `read`.
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */
class CatalogAccessService implements CatalogAccessServiceInterface {

	/**
	 * The IAppConfig key prefix holding a schema's authorization from before the first restriction.
	 */
	public const PREVIOUS_KEY = 'catalog_access_previous_';

	/**
	 * The actions an edit group gets.
	 */
	private const EDIT_ACTIONS = ['read', 'create', 'update', 'delete'];

	/**
	 * The OpenRegister schema mapper's service id.
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * The OpenRegister object service's service id.
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config    App configuration.
	 * @param ContainerInterface $container For OpenRegister's services.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Recompute and, when it changed, write the authorization of one schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return bool Whether the schema was written.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function recompute(int $schemaId): bool {
		return $this->recomputeMany(schemaIds: [$schemaId]) === 1;

	}//end recompute()

	/**
	 * Recompute and, where it changed, write the authorization of several schemas.
	 *
	 * The catalogues are read once for all of them. A schema that cannot be read
	 * or written is logged and skipped; nothing is thrown, so a catalogue save
	 * that already went through never reports an error for this follow-up work.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 *
	 * @return int How many schemas were written.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function recomputeMany(array $schemaIds): int {
		$schemaIds = array_values(array_unique($schemaIds));
		if ($schemaIds === []) {
			return 0;
		}

		// Fail closed (ADR-102): when the catalogues cannot be read, "no catalogue
		// restricts this schema" is unknown, not true. Recomputing on an empty list
		// would restore the pre-restriction rules and drop the snapshot, so the
		// schemas and their snapshots are left exactly as they are.
		$catalogs = $this->catalogs();
		if ($catalogs === null) {
			$this->logger->warning('[CatalogAccessService] schema(s) '.implode(', ', $schemaIds).' left unchanged: the catalogues could not be read');
			return 0;
		}

		$written = 0;
		foreach ($schemaIds as $schemaId) {
			$written += (int)$this->recomputeWith(schemaId: $schemaId, catalogs: $catalogs);
		}

		return $written;

	}//end recomputeMany()

	/**
	 * Recompute one schema from an already-read catalogue list and write it when it changed.
	 *
	 * @param int                              $schemaId The schema id.
	 * @param array<int, array<string, mixed>> $catalogs Every catalogue.
	 *
	 * @return bool Whether the schema was written.
	 */
	private function recomputeWith(int $schemaId, array $catalogs): bool {
		try {
			$mapper = $this->container->get(self::SCHEMA_MAPPER);
			$schema = $mapper->find($schemaId, [], _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[CatalogAccessService] schema '.$schemaId.' could not be read: '.$e->getMessage());
			return false;
		}

		$current = ($schema->getAuthorization() ?? []);
		$next = $this->authorizationFor(schemaId: $schemaId, current: $current, catalogs: $catalogs);
		if ($next === $current) {
			return false;
		}

		$schema->setAuthorization($next);
		try {
			$mapper->update($schema);
		} catch (\Throwable $e) {
			$this->logger->error('[CatalogAccessService] schema '.$schemaId.' could not be written: '.$e->getMessage());
			return false;
		}

		return true;

	}//end recomputeWith()

	/**
	 * The authorization a schema should have, given the catalogues.
	 *
	 * @param int                              $schemaId The schema id.
	 * @param array<string, mixed>             $current  Its authorization now.
	 * @param array<int, array<string, mixed>> $catalogs Every catalogue.
	 *
	 * @return array<string, mixed> The authorization.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function authorizationFor(int $schemaId, array $current, array $catalogs): array {
		$covering = array_values(
			array_filter($catalogs, fn (array $catalog): bool => in_array($schemaId, $this->ids(value: ($catalog['schemas'] ?? [])), true))
		);
		$restricted = array_filter($covering, fn (array $catalog): bool => $this->isRestricted(catalog: $catalog));

		$key = self::PREVIOUS_KEY.$schemaId;
		$stored = $this->config->getValueString('opencatalogi', $key, '');

		$previous = $this->decode(stored: $stored);

		if ($restricted === []) {
			if ($stored !== '') {
				$this->config->deleteKey('opencatalogi', $key);
			}

			return ($previous ?? $current);
		}

		if ($stored === '') {
			$this->config->setValueString('opencatalogi', $key, (string)json_encode($current));
		}

		return $this->rulesFrom(base: ($previous ?? $current), catalogs: $covering);

	}//end authorizationFor()

	/**
	 * Every schema id any catalogue covers, and every schema still carrying a
	 * remembered authorization from before a restriction.
	 *
	 * @return array<int, int> The ids.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function coveredSchemaIds(): array {
		$ids = [];
		foreach (($this->catalogs() ?? []) as $catalog) {
			$ids = array_merge($ids, $this->schemaIdsOf(catalog: $catalog));
		}

		foreach ($this->config->getKeys('opencatalogi') as $key) {
			if (str_starts_with($key, self::PREVIOUS_KEY) === true) {
				$ids[] = (int)substr($key, strlen(self::PREVIOUS_KEY));
			}
		}

		return array_values(array_unique($ids));

	}//end coveredSchemaIds()

	/**
	 * Every schema id a restricted catalogue covers.
	 *
	 * The catalogue guard refuses a non-administrator's change to a catalogue's
	 * scope when it touches one of these schemas: every catalogue covering a
	 * schema feeds its rules, so adding, moving or narrowing an unrestricted
	 * catalogue changes who may see and edit a restricted catalogue's publications.
	 *
	 * @return array<int, int>|null The ids; null when the catalogues cannot be read.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function restrictedSchemaIds(): ?array {
		$catalogs = $this->catalogs();
		if ($catalogs === null) {
			return null;
		}

		$ids = [];
		foreach ($catalogs as $catalog) {
			if ($this->isRestricted(catalog: $catalog) === true) {
				$ids = array_merge($ids, $this->schemaIdsOf(catalog: $catalog));
			}
		}

		return array_values(array_unique($ids));

	}//end restrictedSchemaIds()

	/**
	 * The schema ids a catalogue covers.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return array<int, int> The ids.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function schemaIdsOf(array $catalog): array {
		return $this->ids(value: ($catalog['schemas'] ?? []));

	}//end schemaIdsOf()

	/**
	 * The rule set from the catalogues that cover a schema.
	 *
	 * @param array<string, mixed>             $base     The authorization before any restriction.
	 * @param array<int, array<string, mixed>> $catalogs The covering catalogues.
	 *
	 * @return array<string, mixed> The authorization.
	 */
	private function rulesFrom(array $base, array $catalogs): array {
		$public = array_values(
			array_filter(
				(array)($base['read'] ?? []),
				static fn (mixed $rule): bool => is_array($rule) === true && ($rule['group'] ?? null) === 'public'
			)
		);

		$actions = array_fill_keys(self::EDIT_ACTIONS, []);
		foreach ($catalogs as $catalog) {
			foreach ($this->contributionOf(catalog: $catalog) as $action => $rules) {
				$actions[$action] = array_merge($actions[$action], $rules);
			}
		}

		$next = $base;
		foreach ($actions as $action => $rules) {
			$prefix = [];
			if ($action === 'read') {
				$prefix = $public;
			}

			$next[$action] = array_values(array_unique(array_merge($prefix, $rules), SORT_REGULAR));
		}

		return $next;

	}//end rulesFrom()

	/**
	 * What one catalogue adds per action.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return array<string, array<int, string|array<string, mixed>>> Rules per action.
	 */
	private function contributionOf(array $catalog): array {
		$filters = [];
		if (is_array($catalog['filters'] ?? null) === true) {
			$filters = $catalog['filters'];
		}

		$editGroups = ['authenticated'];
		$readGroups = [];
		if ($this->isRestricted(catalog: $catalog) === true) {
			$editGroups = $this->groups(value: ($catalog['staffAccess']['editGroups'] ?? []));
			$readGroups = $this->groups(value: ($catalog['staffAccess']['readGroups'] ?? []));
		}

		$actions = array_fill_keys(self::EDIT_ACTIONS, []);
		foreach ($readGroups as $group) {
			$actions['read'][] = $this->rule(group: $group, filters: $filters);
		}

		foreach ($editGroups as $group) {
			foreach (self::EDIT_ACTIONS as $action) {
				$actions[$action][] = $this->rule(group: $group, filters: $filters);
			}
		}

		return $actions;

	}//end contributionOf()

	/**
	 * A stored authorization, or null when there is none or it cannot be read.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return array<string, mixed>|null The authorization.
	 */
	private function decode(string $stored): ?array {
		if ($stored === '') {
			return null;
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end decode()

	/**
	 * One rule: the bare group, or the group narrowed to the catalogue's filters.
	 *
	 * @param string               $group   The group.
	 * @param array<string, mixed> $filters The catalogue's filters.
	 *
	 * @return string|array<string, mixed> The rule.
	 */
	private function rule(string $group, array $filters): string|array {
		if ($filters === []) {
			return $group;
		}

		return ['group' => $group, 'match' => $filters];

	}//end rule()

	/**
	 * Whether a catalogue restricts its staff access.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return bool True when restricted.
	 */
	private function isRestricted(array $catalog): bool {
		$access = $catalog['staffAccess'] ?? null;
		return is_array($access) === true && ($access['restricted'] ?? false) === true;

	}//end isRestricted()

	/**
	 * Group ids, as non-empty strings.
	 *
	 * @param mixed $value The stored list.
	 *
	 * @return array<int, string> The groups.
	 */
	private function groups(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $value), static fn (string $group): bool => $group !== ''));

	}//end groups()

	/**
	 * Numeric ids from a stored list; slugs and free text are left out.
	 *
	 * @param mixed $value The stored list.
	 *
	 * @return array<int, int> The ids.
	 */
	private function ids(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $entry) {
			if (is_int($entry) === true || (is_string($entry) === true && ctype_digit($entry) === true)) {
				$ids[] = (int)$entry;
			}
		}

		return $ids;

	}//end ids()

	/**
	 * Every catalogue, as arrays.
	 *
	 * An empty list means there are no catalogues; null means they could not be
	 * read, so a caller cannot mistake a failed read for "nothing is restricted".
	 *
	 * @return array<int, array<string, mixed>>|null The catalogues; null when they cannot be read.
	 */
	private function catalogs(): ?array {
		$register = $this->config->getValueString('opencatalogi', 'catalog_register', '');
		$schema = $this->config->getValueString('opencatalogi', 'catalog_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->warning('[CatalogAccessService] the catalogues could not be read: the catalogue register or schema is not configured');
			return null;
		}

		try {
			$result = $this->container->get(self::OBJECT_SERVICE)->searchObjectsPaginated(
				query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[CatalogAccessService] the catalogues could not be read: '.$e->getMessage());
			return null;
		}

		return array_values(array_filter(array_map(fn (mixed $object): array => $this->asArray(object: $object), ($result['results'] ?? []))));

	}//end catalogs()

	/**
	 * An OpenRegister object as an array of its properties.
	 *
	 * @param mixed $object The object or its array.
	 *
	 * @return array<string, mixed> The properties; empty when it is neither.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (is_array($object['object'] ?? null) === true) {
			return $object['object'];
		}

		return $object;

	}//end asArray()
}//end class
