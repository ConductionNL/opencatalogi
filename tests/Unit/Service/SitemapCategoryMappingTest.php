<?php

/**
 * Unit tests for the type-level Woo category default (woo-category-mapping-intake):
 * a publication with no category of its own falls back to the active mapping row
 * for its schema, its own category always wins, and a row naming no value-list
 * member is never emitted. Also pins the seeded mapping rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-category-mapping-intake/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the per-type category fallback of the DiWoo document mapping.
 *
 * @covers \OCA\OpenCatalogi\Service\SitemapDocumentMapper
 * @uses \OCA\OpenCatalogi\Service\SitemapService
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses \OCA\OpenCatalogi\Service\InstanceSettings
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission
 * @uses \OCA\OpenCatalogi\Service\WooCategory
 */
class SitemapCategoryMappingTest extends TestCase {

	/**
	 * The TOOI base of the kern thesaurus.
	 *
	 * @var string
	 */
	private const KERN = 'https://identifier.overheid.nl/tooi/def/thes/kern/';

	/**
	 * The mapping queries the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Map one publication's file through the service, with the given mapping rows in OpenRegister.
	 *
	 * @param array<string, mixed>             $publication The publication.
	 * @param array<int, array<string, mixed>> $rows        The mapping rows the query answers.
	 *
	 * @return array{document: array<string, mixed>, violations: array<int, array<string, string>>}
	 */
	private function map(array $publication, array $rows): array {
		$objects = new class($rows, $this->queries) {
			/**
			 * @param array<int, array<string, mixed>> $rows    The rows.
			 * @param array<int, array<string, mixed>> $queries Collects the queries.
			 */
			public function __construct(private array $rows, private array &$queries) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query;
				return ['results' => $this->rows];
			}
		};
		$schemas = new class {
			/**
			 * @param string $id The schema id.
			 * @return object
			 */
			public function find(string $id): object {
				return new class($id === '7' ? 'publication' : 'other') {
					/** @param string $slug The slug. */
					public function __construct(private string $slug) {
					}

					/** @return string */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Db\SchemaMapper' ? $schemas : $objects)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$service = new SitemapService(
			new OpenRegisterLookup($container, $apps),
			$settings,
			new InstanceSettings($this->createMock(IURLGenerator::class), $this->createMock(IAppConfig::class)),
			new TooiVocabularyService(),
			$this->createMock(PublicationQueryService::class),
			new WooCategoryRegistry(new TooiVocabularyService(), $settings, $this->createMock(LoggerInterface::class), new LocalCategoryAdmission(new TooiVocabularyService()))
		);

		$method = new \ReflectionMethod(SitemapService::class, 'mapDiwooDocument');
		$violations = [];
		$document = $method->invokeArgs($service, [$publication, ['downloadUrl' => 'https://example.org/d/1', 'title' => 'Besluit.pdf', 'extension' => 'pdf', 'size' => 1024, 'mimeType' => 'application/pdf'], &$violations]);

		return ['document' => $document, 'violations' => $violations];
	}

	/**
	 * A publication of schema 7 (`publication`) with the given extra fields.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(array $fields = []): array {
		return array_merge(['title' => 'Raadsbesluit', '@self' => ['id' => 'pub-1', 'schema' => 7, 'register' => 3, 'published' => '2026-01-01T00:00:00+00:00']], $fields);
	}

	/**
	 * A mapping row.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(array $fields = []): array {
		return array_merge(['app' => 'opencatalogi', 'objectType' => 'publication', 'informatiecategorie' => self::KERN . 'c_db4862c3', 'informatiecategorieLabel' => 'Vergaderstukken decentrale overheden', 'active' => true], $fields);
	}

	/**
	 * The emitted informatiecategorie, or null when the axis is omitted.
	 *
	 * @param array<string, mixed> $document The mapped document.
	 *
	 * @return array<string, string>|null
	 */
	private static function category(array $document): ?array {
		$json = (string)json_encode($document);
		if (preg_match('/"diwoo:informatiecategorie":(\{[^}]*\})/', $json, $match) !== 1) {
			return null;
		}

		return json_decode($match[1], true);
	}

	/**
	 * Whether the informatiecategorie axis was reported unresolved.
	 *
	 * @param array<int, array<string, string>> $violations The violations.
	 *
	 * @return bool
	 */
	private static function categoryReported(array $violations): bool {
		return in_array('informatiecategorie', array_column($violations, 'axis'), true);
	}

	/**
	 * No category of its own and an active mapping for its schema: the mapping's category is emitted (3.1).
	 *
	 * @return void
	 */
	public function testAPublicationWithoutACategoryFallsBackToTheMapping(): void {
		$result = $this->map(self::publication(), [self::row()]);

		$this->assertSame(['#text' => 'Vergaderstukken decentrale overheden', '@resource' => self::KERN . 'c_db4862c3'], self::category($result['document']));
		$this->assertFalse(self::categoryReported($result['violations']));
		$this->assertSame(
			['@self' => ['register' => 'publication', 'schema' => 'wooCategoryMapping'], 'app' => 'opencatalogi', 'objectType' => 'publication', '_limit' => 10],
			$this->queries[0]
		);
	}

	/**
	 * No category and no mapping, or only another app's, an inactive or another type's row: the axis stays unresolved (3.2).
	 *
	 * @return void
	 */
	public function testWithoutAMatchingActiveMappingTheAxisIsReported(): void {
		foreach ([[], [self::row(['app' => 'decidesk'])], [self::row(['active' => false])], [self::row(['objectType' => 'other'])]] as $rows) {
			$result = $this->map(self::publication(), $rows);
			$this->assertNull(self::category($result['document']));
			$this->assertTrue(self::categoryReported($result['violations']));
		}
	}

	/**
	 * A publication's own category wins and the mapping is not consulted (3.3).
	 *
	 * @return void
	 */
	public function testAPublicationsOwnCategoryWins(): void {
		$result = $this->map(self::publication(['category' => 'infocat014']), [self::row()]);

		$this->assertSame(self::KERN . 'c_3baef532', self::category($result['document'])['@resource']);
		$this->assertSame([], $this->queries);
	}

	/**
	 * A mapping row whose category is no value-list member is never emitted (3.4, WOO-CAT-002).
	 *
	 * @return void
	 */
	public function testAMappingOutsideTheValueListIsNeverUsed(): void {
		$result = $this->map(self::publication(), [self::row(['informatiecategorie' => self::KERN . 'c_PLACEHOLDER_x'])]);

		$this->assertNull(self::category($result['document']));
		$this->assertTrue(self::categoryReported($result['violations']));
	}

	/**
	 * The seeded rows resolve to value-list members with their exact labels, carry no placeholder,
	 * and are unique per (app, objectType) (4.1, 4.2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-category-mapping-intake/specs/woo-category-mapping/spec.md
	 */
	public function testTheSeededRowsResolveAndAreUnique(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/woo-category-mapping-intake.json'), true);
		$rows = $fragment['components']['objects'];
		$members = [];
		foreach ((new TooiVocabularyService())->informatiecategorieList() as $member) {
			$members[$member['uri']] = $member['label'];
		}

		$this->assertCount(8, $rows);
		$keys = [];
		foreach ($rows as $row) {
			$this->assertSame('wooCategoryMapping', $row['@self']['schema']);
			$this->assertStringNotContainsString('PLACEHOLDER', $row['informatiecategorie']);
			$this->assertArrayHasKey($row['informatiecategorie'], $members, $row['objectType']);
			$this->assertSame($members[$row['informatiecategorie']], $row['informatiecategorieLabel'], $row['objectType']);
			$this->assertMatchesRegularExpression('#' . $fragment['components']['schemas']['wooCategoryMapping']['properties']['informatiecategorie']['pattern'] . '#', $row['informatiecategorie']);
			$keys[] = $row['app'] . '|' . $row['objectType'];
		}

		$this->assertSame($keys, array_values(array_unique($keys)));
	}
}
