<?php

/**
 * OpenCatalogi AppHost Metrics Provider.
 *
 * Escape-hatch metrics provider for the OpenRegister AppHost observability
 * engine (ADR-040 / ADR-006). The structurally-simple metrics (the two health
 * checks and `search_requests_total`) are expressed declaratively in the
 * `observability` block of src/manifest.json; the JSON-field-grouped domain
 * metrics cannot be expressed by the closed declarative source-kind set
 * (grouping happens on JSON object fields, the usageCounter sum splits one
 * source schema into two named families by `kind`, and the contract emits
 * explicit zero-fallback lines). This provider reproduces those families
 * byte-for-byte with the SAME OpenRegister-backed queries the now-deleted
 * MetricsController ran, so the /api/metrics contract is unchanged on adoption.
 *
 * The engine resolves this class via the container alias
 * `OCA\OpenRegister\AppHost\IMetricsProvider::opencatalogi` (ADR-035 pattern)
 * and merges its MetricSample output into the response; the AppHost
 * PrometheusRenderer prepends the `opencatalogi_` prefix and renders the
 * exposition format, so this provider never emits raw Prometheus text.
 *
 * @category Observability
 * @package  OCA\OpenCatalogi\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Domain metrics provider for OpenCatalogi (AppHost escape hatch).
 *
 * Counts are sourced through OpenRegister object aggregation (ADR-022): the
 * provider resolves the relevant schemas via OpenRegister's `SchemaMapper`,
 * fetches their objects through `ObjectService`, and aggregates the JSON fields
 * in PHP. It no longer issues raw query builders against OpenRegister's storage
 * tables, so OR is free to change its physical layout without breaking this
 * contract.
 *
 * @psalm-suppress UnusedClass Resolved via the AppHost container alias.
 *
 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class OpenCatalogiMetricsProvider implements IMetricsProvider {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the OR ObjectService / mappers.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The ObjectService, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR ObjectService; pure plumbing.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getObjectService()

	/**
	 * Resolve the OpenRegister SchemaMapper, or null when unavailable.
	 *
	 * @return object|null The SchemaMapper, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR SchemaMapper; pure plumbing.
	 */
	private function getSchemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getSchemaMapper()

	/**
	 * Resolve the OpenRegister RegisterMapper, or null when unavailable.
	 *
	 * @return object|null The RegisterMapper, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR RegisterMapper; pure plumbing.
	 */
	private function getRegisterMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getRegisterMapper()

	/**
	 * Normalise an OpenRegister object (entity or array) to a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object's own fields.
	 *
	 * @spec exclude Shape-normalisation plumbing for OR objects; no domain behaviour.
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$data = $object->jsonSerialize();
				if (is_array($data) === true) {
					return $data;
				}
			}

			return (array)$object;
		}

		return [];
	}//end normalise()

	/**
	 * Resolve the IDs of schemas whose title contains the given needle.
	 *
	 * Uses OpenRegister's SchemaMapper (an OR abstraction) rather than a raw SQL
	 * LIKE against the schema table.
	 *
	 * @param string $needle Case-insensitive substring to match against schema titles.
	 *
	 * @return array<int, int> Matching schema IDs.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function resolveSchemaIds(string $needle): array {
		$schemaMapper = $this->getSchemaMapper();
		if ($schemaMapper === null) {
			return [];
		}

		try {
			return $this->titledSchemaIds(schemas: $schemaMapper->findAll(), needle: $needle);
		} catch (\Exception $e) {
			$this->logger->warning('[OpenCatalogiMetricsProvider] Failed to resolve schemas', ['needle' => $needle, 'error' => $e->getMessage()]);
			return [];
		}
	}//end resolveSchemaIds()

	/**
	 * The ids of the schemas whose title contains the needle, in mapper order.
	 *
	 * @param iterable<mixed> $schemas The schemas (entities or arrays).
	 * @param string $needle Case-insensitive substring to match against the titles.
	 *
	 * @return array<int, int> Matching schema IDs.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function titledSchemaIds(iterable $schemas, string $needle): array {
		$ids = [];
		foreach ($schemas as $schema) {
			$id = $this->titledSchemaId(schema: $schema, needle: $needle);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end titledSchemaIds()

	/**
	 * The id of a schema whose title contains the needle.
	 *
	 * @param mixed $schema The schema (entity or array).
	 * @param string $needle Case-insensitive substring to match against the title.
	 *
	 * @return int|null The schema id, or null without an id, a title or a match.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function titledSchemaId(mixed $schema, string $needle): ?int {
		$data = $this->normalise(object: $schema);
		$title = (string)($data['title'] ?? '');
		$id = ($data['id'] ?? null);
		if ($id === null || $title === '' || stripos($title, $needle) === false) {
			return null;
		}

		return (int)$id;
	}//end titledSchemaId()

	/**
	 * Build a map of schema ID → register IDs that contain it.
	 *
	 * @return array<int, array<int, int>> Map of schema ID → register IDs.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function buildSchemaRegisterMap(): array {
		$registerMapper = $this->getRegisterMapper();
		if ($registerMapper === null) {
			return [];
		}

		try {
			return $this->registerMap(registers: $registerMapper->findAll());
		} catch (\Exception $e) {
			$this->logger->warning('[OpenCatalogiMetricsProvider] Failed to map schemas to registers', ['error' => $e->getMessage()]);
			return [];
		}
	}//end buildSchemaRegisterMap()

	/**
	 * Map each schema id to the ids of the registers that list it.
	 *
	 * @param iterable<mixed> $registers The registers (entities or arrays).
	 *
	 * @return array<int, array<int, int>> Map of schema ID → register IDs.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function registerMap(iterable $registers): array {
		$map = [];
		foreach ($registers as $register) {
			$map = $this->withRegister(map: $map, register: $this->normalise(object: $register));
		}

		return $map;
	}//end registerMap()

	/**
	 * Add a register's id under each schema id it lists.
	 *
	 * @param array<int, array<int, int>> $map Map of schema ID → register IDs so far.
	 * @param array<string, mixed> $register The register's own fields.
	 *
	 * @return array<int, array<int, int>> The map with this register added.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function withRegister(array $map, array $register): array {
		$registerId = ($register['id'] ?? null);
		if ($registerId === null) {
			return $map;
		}

		foreach ($this->registerSchemaIds(register: $register) as $schemaId) {
			$map[$schemaId][] = (int)$registerId;
		}

		return $map;
	}//end withRegister()

	/**
	 * The numeric schema ids a register lists.
	 *
	 * @param array<string, mixed> $register The register's own fields.
	 *
	 * @return array<int, int> The schema ids, in list order.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function registerSchemaIds(array $register): array {
		$schemas = ($register['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return [];
		}

		$ids = array_filter(array_map($this->schemaIdOf(...), $schemas), 'is_numeric');
		return array_values(array_map('intval', $ids));
	}//end registerSchemaIds()

	/**
	 * The id of one entry in a register's schema list.
	 *
	 * Register schema lists may hold IDs or nested schema arrays.
	 *
	 * @param mixed $schema The list entry.
	 *
	 * @return mixed The entry itself, or the `id` of a nested schema array.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function schemaIdOf(mixed $schema): mixed {
		if (is_array($schema) === true) {
			return ($schema['id'] ?? null);
		}

		return $schema;
	}//end schemaIdOf()

	/**
	 * Fetch, via OpenRegister object aggregation, every object of the schemas whose
	 * title matches the needle, as plain field arrays.
	 *
	 * @param string $needle Schema-title substring identifying the domain schemas.
	 *
	 * @return array<int, array<string, mixed>> The matching objects' own fields.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function fetchObjectsBySchemaNeedle(string $needle): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$schemaIds = $this->resolveSchemaIds(needle: $needle);
		if ($schemaIds === []) {
			return [];
		}

		$objects = [];
		foreach ($this->schemaRegisterPairs(schemaIds: $schemaIds) as $self) {
			array_push($objects, ...$this->searchSchemaObjects(objectService: $objectService, self: $self));
		}

		return $objects;
	}//end fetchObjectsBySchemaNeedle()

	/**
	 * Every register and schema pair to search, by schema, then by register.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 *
	 * @return array<int, array{register: int, schema: int}> The pairs.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function schemaRegisterPairs(array $schemaIds): array {
		$schemaRegisterMap = $this->buildSchemaRegisterMap();
		$pairs = [];
		foreach ($schemaIds as $schemaId) {
			foreach (($schemaRegisterMap[$schemaId] ?? []) as $registerId) {
				$pairs[] = ['register' => $registerId, 'schema' => $schemaId];
			}
		}

		return $pairs;
	}//end schemaRegisterPairs()

	/**
	 * Every object of one register and schema, as plain field arrays.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array{register: int, schema: int} $self The register and schema to search.
	 *
	 * @return array<int, array<string, mixed>> The objects, or none when the search fails or answers no list.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function searchSchemaObjects(object $objectService, array $self): array {
		try {
			$results = $objectService->searchObjects(query: ['@self' => $self], _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(
				'[OpenCatalogiMetricsProvider] Object aggregation failed',
				['schema' => $self['schema'], 'register' => $self['register'], 'error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		return array_values(array_map($this->normalise(...), $results));
	}//end searchSchemaObjects()

	/**
	 * Produce OpenCatalogi's domain metric samples.
	 *
	 * Order and family shape match the pre-adoption MetricsController exactly:
	 * publications (status+catalog), catalogs (scalar), listings (status, with
	 * a zero fallback), directory entries (scalar), then the usageCounter
	 * view/download totals by catalog (each with a `{catalog=""} 0` fallback).
	 *
	 * @return MetricSample[] The provider's samples.
	 *
	 * @spec openspec/specs/adopt-apphost/spec.md
	 */
	public function metrics(): array {
		$samples = [$this->publicationsSample()];
		$samples[] = MetricSample::single(
			name: 'catalogs_total',
			type: 'gauge',
			help: 'Total catalogs',
			value: $this->countObjectsBySchemaNeedle(needle: 'atalog')
		);
		$samples[] = $this->listingsSample();
		$samples[] = MetricSample::single(
			name: 'directory_entries_total',
			type: 'gauge',
			help: 'Total federated directory entries',
			value: $this->countObjectsBySchemaNeedle(needle: 'irectory')
		);

		return array_merge($samples, $this->usageSamples());
	}//end metrics()

	/**
	 * The publications total by status and catalog.
	 *
	 * @return MetricSample The `publications_total` family.
	 *
	 * @spec openspec/specs/adopt-apphost/spec.md
	 */
	private function publicationsSample(): MetricSample {
		$points = [];
		foreach ($this->getPublicationCounts() as $row) {
			$points[] = [
				'labels' => [
					'status' => (string)($row['status'] ?? ''),
					'catalog' => (string)($row['catalog'] ?? ''),
				],
				'value' => (int)($row['cnt'] ?? 0),
			];
		}

		return new MetricSample(
			name: 'publications_total',
			type: 'gauge',
			help: 'Total publications by status and catalog',
			samples: $points
		);
	}//end publicationsSample()

	/**
	 * The listings total by status, with the historical zero fallback.
	 *
	 * @return MetricSample The `listings_total` family.
	 *
	 * @spec openspec/specs/adopt-apphost/spec.md
	 */
	private function listingsSample(): MetricSample {
		$points = [];
		foreach ($this->getListingCounts() as $row) {
			$points[] = [
				'labels' => ['status' => (string)($row['status'] ?? '')],
				'value' => (int)($row['cnt'] ?? 0),
			];
		}

		if ($points === []) {
			$points[] = ['labels' => [], 'value' => 0];
		}

		return new MetricSample(
			name: 'listings_total',
			type: 'gauge',
			help: 'Total listings by status',
			samples: $points
		);
	}//end listingsSample()

	/**
	 * The catalog-labelled view and download totals (ANA-008).
	 *
	 * @return MetricSample[] The `publication_views_total` and `file_downloads_total` families.
	 *
	 * @spec openspec/specs/adopt-apphost/spec.md
	 */
	private function usageSamples(): array {
		$usage = $this->getUsageTotalsByCatalog();

		return [
			new MetricSample(
				name: 'publication_views_total',
				type: 'counter',
				help: 'Total counted publication views by catalog',
				samples: $this->catalogPoints(totals: $usage['view'])
			),
			new MetricSample(
				name: 'file_downloads_total',
				type: 'counter',
				help: 'Total counted file downloads by catalog',
				samples: $this->catalogPoints(totals: $usage['download'])
			),
		];
	}//end usageSamples()

	/**
	 * One point per catalog, or a single `{catalog=""} 0` point when there are none.
	 *
	 * @param array<string, int> $totals Totals keyed by catalog.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}> The points.
	 *
	 * @spec openspec/specs/adopt-apphost/spec.md
	 */
	private function catalogPoints(array $totals): array {
		$points = [];
		foreach ($totals as $catalog => $count) {
			$points[] = ['labels' => ['catalog' => (string)$catalog], 'value' => (int)$count];
		}

		if ($points === []) {
			$points[] = ['labels' => ['catalog' => ''], 'value' => 0];
		}

		return $points;
	}//end catalogPoints()

	/**
	 * Get publication counts grouped by status and catalog, via OR aggregation.
	 *
	 * @return array<array{status: string, catalog: string, cnt: int}> Grouped counts.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function getPublicationCounts(): array {
		$grouped = [];
		foreach ($this->fetchObjectsBySchemaNeedle(needle: 'ublicati') as $object) {
			$status = (string)($object['status'] ?? '');
			$catalog = (string)($object['catalog'] ?? '');
			$key = $status . '|' . $catalog;
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = ['status' => $status, 'catalog' => $catalog, 'cnt' => 0];
			}

			$grouped[$key]['cnt']++;
		}

		return array_values($grouped);
	}//end getPublicationCounts()

	/**
	 * Count objects of the schemas whose title contains the given needle.
	 *
	 * @param string $needle Schema-title substring.
	 *
	 * @return int Object count.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function countObjectsBySchemaNeedle(string $needle): int {
		return count($this->fetchObjectsBySchemaNeedle(needle: $needle));
	}//end countObjectsBySchemaNeedle()

	/**
	 * Get listing counts grouped by status, via OR aggregation.
	 *
	 * @return array<array{status: string, cnt: int}> Grouped counts.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function getListingCounts(): array {
		$grouped = [];
		foreach ($this->fetchObjectsBySchemaNeedle(needle: 'isting') as $object) {
			$status = (string)($object['status'] ?? '');
			if (isset($grouped[$status]) === false) {
				$grouped[$status] = ['status' => $status, 'cnt' => 0];
			}

			$grouped[$status]['cnt']++;
		}

		return array_values($grouped);
	}//end getListingCounts()

	/**
	 * Sum usage-counter objects by catalog and kind for the metrics families.
	 *
	 * Reads the privacy-safe `usageCounter` objects (via OR object aggregation)
	 * and sums their `count` grouped by `catalog` + `kind`.
	 * Returns ['view' => [catalog => total], 'download' => [catalog => total]].
	 * No per-publication grouping is performed (cardinality, ANA-008).
	 *
	 * @return array{view: array<string,int>, download: array<string,int>} Totals by catalog.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	private function getUsageTotalsByCatalog(): array {
		$out = ['view' => [], 'download' => []];

		foreach ($this->fetchObjectsBySchemaNeedle(needle: 'sageCounter') as $object) {
			$kind = (string)($object['kind'] ?? '');
			if (isset($out[$kind]) === false) {
				continue;
			}

			$catalog = (string)($object['catalog'] ?? '');
			$count = (int)($object['count'] ?? 0);
			if (isset($out[$kind][$catalog]) === false) {
				$out[$kind][$catalog] = 0;
			}

			$out[$kind][$catalog] += $count;
		}//end foreach

		return $out;
	}//end getUsageTotalsByCatalog()
}//end class
