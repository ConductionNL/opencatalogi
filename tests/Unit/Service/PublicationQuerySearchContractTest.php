<?php

/**
 * Contract test: council documents through the public search (REQ-SCF-001..003).
 *
 * Runs decidiq's real `PublicationPayload` schema (copied into
 * tests/fixtures/decidiq-publication-payload.json, with the shas it came from)
 * through `PublicationQueryService::assemblePublicSearchResults()`. The
 * OpenRegister double filters and counts the way OpenRegister does: exact match
 * on a plain filter, `gte`/`lte` on a range, and a facet count for each
 * property the schema marks `facetable`. It reads those marks from the fixture,
 * so a fragment that stops marking a property facetable fails here by name.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/search/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Exception\MalformedSearchParameterException;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\IAppConfig;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An in-memory OpenRegister search over council publications.
 */
class CouncilSearchObjectService {

	/** @var array<int, array<string, mixed>> The queries the search received. */
	public array $queries = [];

	/**
	 * @param array<int, array<string, mixed>> $rows The stored publications.
	 * @param array<string, mixed> $schema The PublicationPayload schema.
	 */
	public function __construct(private array $rows, private array $schema) {
	}

	public function runAsAnonymous(callable $operation): mixed {
		return $operation();
	}

	public function buildSearchQuery(array $requestParams): array {
		return $requestParams;
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $query;
		$filters = array_filter(
			$query,
			static fn (string $key): bool => str_starts_with($key, '_') === false && $key !== '@self',
			ARRAY_FILTER_USE_KEY
		);

		$hits = array_values(array_filter($this->rows, fn (array $row): bool => $this->matches($row, $filters)));

		$facets = [];
		foreach ($this->schema['properties'] as $name => $property) {
			if (($property['facetable'] ?? false) !== true || ($property['format'] ?? null) === 'date-time') {
				continue;
			}

			$counts = [];
			foreach ($hits as $row) {
				if (isset($row[$name]) === true) {
					$counts[$row[$name]] = ($counts[$row[$name]] ?? 0) + 1;
				}
			}

			$facets[$name] = $counts;
		}

		return ['results' => $hits, 'total' => count($hits), 'facets' => $facets, 'facetable' => array_keys($facets)];
	}

	/**
	 * @param array<string, mixed> $row A publication.
	 * @param array<string, mixed> $filters The property filters.
	 */
	private function matches(array $row, array $filters): bool {
		foreach ($filters as $name => $filter) {
			$value = ($row[$name] ?? null);
			if (is_array($filter) === false) {
				if ($value !== $filter) {
					return false;
				}

				continue;
			}

			$time = strtotime((string)$value);
			if (isset($filter['gte']) === true && $time < strtotime((string)$filter['gte'])) {
				return false;
			}

			if (isset($filter['lte']) === true && $time > strtotime((string)$filter['lte'] . ' 23:59:59')) {
				return false;
			}
		}

		return true;
	}
}

/**
 * The council-document search contract.
 */
class PublicationQuerySearchContractTest extends TestCase {

	/** @var array<string, mixed> decidiq's PublicationPayload schema. */
	private array $schema;

	protected function setUp(): void {
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/decidiq-publication-payload.json'), true);
		$this->schema = $fixture['PublicationPayload'];
	}

	/**
	 * Three council publications, validated against the real fragment first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function councilPublications(): array {
		$payloads = [
			'besluitenlijst-jan' => ['oriType' => 'Verslag', 'title' => 'Besluitenlijst januari', 'documentType' => 'minutes', 'bodyName' => 'Gemeenteraad', 'meetingDate' => '2026-01-15T19:30:00+01:00'],
			'verslag-apr' => ['oriType' => 'Verslag', 'title' => 'Verslag april', 'documentType' => 'minutes', 'bodyName' => 'Commissie Ruimte', 'meetingDate' => '2026-04-09T19:30:00+02:00'],
			'agenda-mei' => ['oriType' => 'Vergadering', 'title' => 'Agenda mei', 'documentType' => 'agenda', 'bodyName' => 'Gemeenteraad', 'meetingDate' => '2026-05-21T19:30:00+02:00'],
		];

		$validator = new Validator();
		$validator->parser()->setOption('allowFormats', false);
		$schema = ['type' => 'object', 'properties' => $this->schema['properties'], 'required' => $this->schema['required']];
		$rows = [];
		foreach ($payloads as $id => $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema));
			$this->assertTrue($result->isValid(), "decidiq's PublicationPayload refuses the test payload $id.");
			$rows[] = $payload + ['@self' => ['id' => $id, 'schema' => 1, 'register' => 1]];
		}

		return $rows;
	}

	/**
	 * Build the service around the council store, one catalogue with schema 1.
	 */
	private function search(array $params, ?CouncilSearchObjectService &$store = null): array {
		$store = new CouncilSearchObjectService($this->councilPublications(), $this->schema);
		$catalogi = new class {
			public function getCatalogBySlug(string $slug): ?array {
				return ['slug' => 'raad', 'listed' => true, 'published' => '2020-01-01T00:00:00+00:00', 'registers' => [1], 'schemas' => [1]];
			}
		};
		$schemaMapper = new class {
			public function find(int|string $id): object {
				return new class {
					public function getId(): int {
						return 1;
					}

					public function getSlug(): string {
						return 'publication';
					}

					public function getTitle(): string {
						return 'Publication';
					}

					public function getAuthorization(): ?array {
						return ['read' => [['group' => 'public']]];
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $key) => match ($key) {
				'OCA\\OpenCatalogi\\Service\\CatalogiService' => $catalogi,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
				'OCA\\OpenRegister\\Service\\ObjectService' => $store,
				default => throw new \RuntimeException("Unmocked container key: $key"),
			}
		);

		$service = new PublicationQueryService(
			container: $container,
			config: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return $service->assemblePublicSearchResults(queryParams: $params + ['_catalog' => 'raad'], objectService: $store);
	}

	/**
	 * @param array<string, mixed> $envelope The search envelope.
	 *
	 * @return array<int, string> The ids listed.
	 */
	private function ids(array $envelope): array {
		return array_map(static fn (array $row): string => (string)$row['@self']['id'], $envelope['results']);
	}

	public function testTheFragmentMarksTheThreeFiltersFacetable(): void {
		foreach (['documentType', 'bodyName', 'meetingDate'] as $name) {
			$this->assertTrue(
				($this->schema['properties'][$name]['facetable'] ?? false) === true,
				"decidiq's PublicationPayload no longer marks $name facetable."
			);
		}

		$this->assertSame('date-time', $this->schema['properties']['meetingDate']['format']);
	}

	/** REQ-SCF-001 scenario "A citizen filters by document type". */
	public function testACitizenFiltersByDocumentType(): void {
		$filtered = $this->search(['documentType' => 'minutes'], $store);
		$this->assertSame(['besluitenlijst-jan', 'verslag-apr'], $this->ids($filtered));
		$this->assertSame('minutes', $store->queries[0]['documentType']);

		$all = $this->search([]);
		$this->assertSame(2, $all['facets']['documentType']['minutes'] ?? null, 'No documentType facet count came back.');
		$this->assertSame(1, $all['facets']['documentType']['agenda'] ?? null);
		$this->assertSame(2, $all['facets']['bodyName']['Gemeenteraad'] ?? null, 'No bodyName facet count came back.');
	}

	public function testACitizenFiltersByBody(): void {
		$this->assertSame(['verslag-apr'], $this->ids($this->search(['bodyName' => 'Commissie Ruimte'])));
	}

	/** REQ-SCF-002 scenario "A citizen searches one quarter". */
	public function testACitizenSearchesOneQuarter(): void {
		$envelope = $this->search(['meetingDate' => ['gte' => '2026-04-01', 'lte' => '2026-06-30']], $store);

		$this->assertSame(['verslag-apr', 'agenda-mei'], $this->ids($envelope));
		$this->assertSame(['gte' => '2026-04-01', 'lte' => '2026-06-30'], $store->queries[0]['meetingDate']);
	}

	/** REQ-SCF-002 scenario "A bad date". */
	public function testABadDateNamesTheParameter(): void {
		try {
			$this->search(['meetingDate' => ['gte' => 'tomorrow-ish']], $store);
			$this->fail('A malformed meetingDate was searched.');
		} catch (MalformedSearchParameterException $e) {
			$this->assertSame('meetingDate[gte]', $e->getParameter());
		}

		$this->assertSame([], $store->queries);
	}
}
