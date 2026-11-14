<?php

/**
 * Public search returns subjects as their own result type (REQ-SUB-004).
 *
 * Drives `PublicationQueryService::assemblePublicSearchResults()` with the
 * real `SubjectSearch` and `SubjectPublications` over an OpenRegister double
 * holding council publications and subjects (`theme` objects). The double
 * matches `_search` on title, summary, description and content, filters on
 * `themes`, and keeps the scope the query names.
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
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-public-search-returns-subjects-as-their-own-result-type-req-sub-004
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\Subject\SubjectPublications;
use OCA\OpenCatalogi\Service\Subject\SubjectSearch;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An OpenRegister ObjectService double over publication and theme rows.
 */
class SubjectSearchObjectService {

	/**
	 * Every query it answered.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Whether a theme read throws.
	 *
	 * @var bool
	 */
	public bool $themeReadFails = false;

	/**
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 */
	public function __construct(
		private array $rows,
	) {
	}

	public function runAsAnonymous(callable $operation): mixed {
		return $operation();
	}

	public function buildSearchQuery(array $requestParams): array {
		return $requestParams;
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $query;
		$schemas = $query['_schemas'] ?? [];
		if (isset($query['@self']['schema']) === true) {
			$schemas = [$query['@self']['schema']];
		}

		if ($this->themeReadFails === true && in_array(PublicSearchSubjectsTest::THEME_SCHEMA, $schemas, true) === true) {
			throw new RuntimeException('theme read failed');
		}

		$hits = array_values(
			array_filter(
				$this->rows,
				static function (array $row) use ($query, $schemas): bool {
					if (in_array($row['@self']['schema'], $schemas, true) === false) {
						return false;
					}

					if (isset($query['themes']) === true && in_array($query['themes'], ($row['themes'] ?? []), true) === false) {
						return false;
					}

					$term = strtolower((string)($query['_search'] ?? ''));
					if ($term === '') {
						return true;
					}

					foreach (['title', 'summary', 'description', 'content'] as $field) {
						if (str_contains(strtolower((string)($row[$field] ?? '')), $term) === true) {
							return true;
						}
					}

					return false;
				}
			)
		);

		return ['results' => $hits, 'total' => count($hits)];
	}
}

/**
 * Public search with subjects (REQ-SUB-004).
 */
#[CoversClass(PublicationQueryService::class)]
#[UsesClass(SubjectSearch::class)]
#[UsesClass(SubjectPublications::class)]
class PublicSearchSubjectsTest extends TestCase {

	public const PUBLICATION_SCHEMA = 1;

	public const THEME_SCHEMA = 7;

	/**
	 * The stored rows: the subject "Sporthal" whose description mentions
	 * "sportaccommodatie", two public publications filed under it that do not,
	 * and one about the Sporthal budget.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			['@self' => ['id' => 'theme-sporthal', 'schema' => self::THEME_SCHEMA, 'register' => 4], 'slug' => 'sporthal', 'title' => 'Sporthal', 'summary' => 'De nieuwe sporthal', 'description' => 'Alles over de sportaccommodatie aan de Stationsweg', 'image' => 'https://example.nl/sporthal.png'],
			['@self' => ['id' => 'theme-zwembad', 'schema' => self::THEME_SCHEMA, 'register' => 4], 'slug' => 'zwembad', 'title' => 'Zwembad', 'description' => 'Het zwembad'],
			['@self' => ['id' => 'pub-1', 'schema' => self::PUBLICATION_SCHEMA, 'register' => 1], 'title' => 'Raadsbesluit bouw', 'themes' => ['theme-sporthal']],
			['@self' => ['id' => 'pub-2', 'schema' => self::PUBLICATION_SCHEMA, 'register' => 1], 'title' => 'Aanbesteding', 'themes' => ['theme-sporthal']],
			['@self' => ['id' => 'pub-3', 'schema' => self::PUBLICATION_SCHEMA, 'register' => 1], 'title' => 'Begroting sporthal 2026', 'themes' => []],
		];
	}

	/**
	 * The service, with or without the subject search.
	 *
	 * @param SubjectSearchObjectService $store       The OpenRegister double.
	 * @param bool                       $withSubjects Whether the subject search is wired in.
	 *
	 * @return PublicationQueryService
	 */
	private function service(SubjectSearchObjectService $store, bool $withSubjects = true): PublicationQueryService {
		$catalogi = new class {
			public function getCatalogBySlug(string $slug): ?array {
				return ['slug' => 'raad', 'listed' => true, 'published' => '2020-01-01T00:00:00+00:00', 'registers' => [1], 'schemas' => [PublicSearchSubjectsTest::PUBLICATION_SCHEMA]];
			}
		};
		$schemaMapper = new class {
			public function find(int|string $id): object {
				return new class ((int)$id) {
					public function __construct(
						private int $id,
					) {
					}

					public function getId(): int {
						return $this->id;
					}

					public function getSlug(): string {
						return $this->id === PublicSearchSubjectsTest::THEME_SCHEMA ? 'theme' : 'publication';
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
				default => throw new RuntimeException("Unmocked container key: $key"),
			}
		);

		$subjects = null;
		if ($withSubjects === true) {
			$registers = $this->createMock(OpenRegisterLookupInterface::class);
			$registers->method('installedObjectService')->willReturn($store);
			$registers->method('registerConfiguration')->willReturnCallback(
				static fn (string $registerKey, string $schemaKey): array => match ($registerKey) {
					'theme_register' => ['register' => 4, 'schema' => self::THEME_SCHEMA],
					default => ['register' => 1, 'schema' => self::PUBLICATION_SCHEMA],
				}
			);
			$subjects = new SubjectSearch(registers: $registers, publications: new SubjectPublications(registers: $registers));
		}

		return new PublicationQueryService(
			container: $container,
			config: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			subjects: $subjects,
		);
	}

	/**
	 * Search the store.
	 *
	 * @param array<string, mixed>        $params       The query.
	 * @param SubjectSearchObjectService  $store        The OpenRegister double.
	 * @param bool                        $withSubjects Whether the subject search is wired in.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function search(array $params, SubjectSearchObjectService $store, bool $withSubjects = true): array {
		return $this->service(store: $store, withSubjects: $withSubjects)
			->assemblePublicSearchResults(queryParams: $params + ['_catalog' => 'raad'], objectService: $store);
	}

	/** REQ-SUB-004 scenario "Search finds a subject". */
	public function testASubjectIsAResultWithItsOwnType(): void {
		$envelope = $this->search(['_search' => 'sportaccommodatie'], new SubjectSearchObjectService($this->rows()));

		$this->assertSame(
			[
				[
					'resultType' => 'subject',
					'id' => 'theme-sporthal',
					'slug' => 'sporthal',
					'title' => 'Sporthal',
					'summary' => 'De nieuwe sporthal',
					'image' => 'https://example.nl/sporthal.png',
					'publicationCount' => 2,
					'url' => '/index.php/apps/opencatalogi/api/themes/theme-sporthal',
				],
			],
			$envelope['results']
		);
		$this->assertSame(1, $envelope['total']);
		$this->assertSame(['publication' => 0, 'document' => 0, 'subject' => 1], $envelope['facets']['resultType']);
	}

	public function testPublicationsAndSubjectsAnswerTogetherEachWithItsType(): void {
		$envelope = $this->search(['_search' => 'sporthal'], new SubjectSearchObjectService($this->rows()));

		$this->assertSame(
			[['publication', 'pub-3'], ['subject', 'theme-sporthal']],
			array_map(static fn (array $row): array => [$row['resultType'], (string)($row['@self']['id'] ?? $row['id'])], $envelope['results'])
		);
		$this->assertSame(2, $envelope['total']);
		$this->assertSame(['publication' => 1, 'document' => 0, 'subject' => 1], $envelope['facets']['resultType']);
	}

	/** REQ-SUB-004 scenario "Existing results keep their shape". */
	public function testAPublicationOnlySearchIsUnchanged(): void {
		$today = $this->search(['_search' => 'sporthal'], new SubjectSearchObjectService($this->rows()), withSubjects: false);
		$store = new SubjectSearchObjectService($this->rows());
		$envelope = $this->search(['_search' => 'sporthal', 'resultType' => 'publication'], $store);

		$this->assertSame($today, $envelope);
		$this->assertSame('publication', $envelope['results'][0]['resultType']);
		$this->assertCount(1, $store->queries, 'A publication-only search must not read the subjects.');
		$this->assertArrayNotHasKey('resultType', $store->queries[0], 'resultType is OpenCatalogi\'s own parameter, never a filter for OpenRegister.');
	}

	public function testASubjectOnlySearchListsTheSubjectsAlone(): void {
		$store = new SubjectSearchObjectService($this->rows());
		$envelope = $this->search(['_search' => 'sporthal', 'resultType' => 'subject'], $store);

		$this->assertSame(['theme-sporthal'], array_column($envelope['results'], 'id'));
		$this->assertSame(1, $envelope['total']);
		$this->assertSame([self::THEME_SCHEMA], [$store->queries[0]['@self']['schema']]);
	}

	public function testWithoutATermNoSubjectIsRead(): void {
		$store = new SubjectSearchObjectService($this->rows());
		$envelope = $this->search([], $store);

		$this->assertSame(['pub-1', 'pub-2', 'pub-3'], array_map(static fn (array $row): string => $row['@self']['id'], $envelope['results']));
		$this->assertCount(1, $store->queries);
		$this->assertSame(0, $envelope['facets']['resultType']['subject']);
	}

	public function testAFailedSubjectReadStillAnswersThePublications(): void {
		$store = new SubjectSearchObjectService($this->rows());
		$store->themeReadFails = true;
		$envelope = $this->search(['_search' => 'sporthal'], $store);

		$this->assertSame(['pub-3'], array_map(static fn (array $row): string => $row['@self']['id'], $envelope['results']));
		$this->assertSame(1, $envelope['total']);
	}
}
