<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reads and writes behind document references: only references inside
 * their window are published, the daily check sees every one of them up to
 * the cap, and its result is written back (REQ-PBR-002, REQ-PBR-003).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DocumentReferenceMapper;
use OCA\OpenCatalogi\Service\DocumentReferences;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\DocumentReferences
 * @uses   \OCA\OpenCatalogi\Service\DocumentReferenceMapper
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class DocumentReferencesTest extends TestCase {

	/**
	 * The searches asked of OpenRegister.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The saves asked of OpenRegister.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The store over these rows, answered page by page.
	 *
	 * @param list<array<string, mixed>> $rows The references OpenRegister holds.
	 * @param string $schema The configured schema id.
	 *
	 * @return DocumentReferences The store.
	 */
	private function store(array $rows, string $schema = '91'): DocumentReferences {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['publication_register' => '19', 'document_reference_schema' => $schema][$key] ?? $default)
		);

		$objects = new class ($rows, $this->queries, $this->saves) {
			/**
			 * @param list<array<string, mixed>> $rows
			 * @param list<array<string, mixed>> $queries
			 * @param list<array<string, mixed>> $saves
			 */
			public function __construct(private array $rows, private array &$queries, private array &$saves) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = ['query' => $query, 'rbac' => $_rbac];
				$rows = $this->rows;
				if (isset($query['publication']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['publication'] ?? null) === $query['publication']));
				}

				return ['results' => array_slice($rows, ($query['_page'] - 1) * $query['_limit'], $query['_limit'])];
			}

			public function saveObject(array $object, string $register, string $schema, string $uuid, bool $_rbac, bool $_multitenancy): array {
				$this->saves[] = compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy');
				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		return new DocumentReferences(
			config: $config,
			openRegister: new OpenRegisterLookup($container, $apps),
			mapper: new DocumentReferenceMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end store()

	/**
	 * A reference.
	 *
	 * @param string $id Its id.
	 * @param array<string, mixed> $overrides Changed properties.
	 *
	 * @return array<string, mixed>
	 */
	private static function reference(string $id, array $overrides = []): array {
		return array_merge(
			[
				'id' => $id,
				'publication' => 'pub-1',
				'url' => 'https://documenten.voorbeeldgemeente.nl/' . $id . '.pdf',
				'title' => 'Document ' . $id,
				'format' => 'pdf',
				'publicationDate' => '2020-01-01T00:00:00+00:00',
			],
			$overrides
		);
	}//end reference()

	public function testOnlyTheReferencesInsideTheirWindowArePublished(): void {
		$store = $this->store(
			rows: [
				self::reference(id: 'open'),
				self::reference(id: 'ended', overrides: ['depublicationDate' => '2021-01-01T00:00:00+00:00']),
				self::reference(id: 'future', overrides: ['publicationDate' => '2999-01-01T00:00:00+00:00']),
				self::reference(id: 'undated', overrides: ['publicationDate' => null]),
				self::reference(id: 'plain-http', overrides: ['url' => 'http://example.org/a.pdf']),
				self::reference(id: 'other', overrides: ['publication' => 'pub-2']),
			]
		);

		$entries = $store->publishedFileEntries(publicationId: 'pub-1');

		$this->assertSame(['open'], array_column($entries, 'id'));
		$this->assertSame('https://documenten.voorbeeldgemeente.nl/open.pdf', $entries[0]['downloadUrl']);
		$this->assertSame('reference', $entries[0]['kind']);
		$this->assertSame(['register' => '19', 'schema' => '91'], $this->queries[0]['query']['@self']);
		$this->assertSame('pub-1', $this->queries[0]['query']['publication']);
		$this->assertFalse($this->queries[0]['rbac']);
	}//end testOnlyTheReferencesInsideTheirWindowArePublished()

	public function testReferencesComeAfterTheFiles(): void {
		$files = [['downloadUrl' => 'https://example.org/file.pdf', 'extension' => 'pdf']];

		$merged = $this->store(rows: [self::reference(id: 'open')])->appendTo(files: $files, publicationId: 'pub-1');

		$this->assertSame(['https://example.org/file.pdf', 'https://documenten.voorbeeldgemeente.nl/open.pdf'], array_column($merged, 'downloadUrl'));
	}//end testReferencesComeAfterTheFiles()

	public function testWithoutTheSchemaNothingIsAskedAndNothingIsListed(): void {
		$store = $this->store(rows: [self::reference(id: 'open')], schema: '');

		$this->assertSame([], $store->publishedFileEntries(publicationId: 'pub-1'));
		$this->assertSame([], $store->inWindow(cap: 10));
		$this->assertFalse($store->recordCheck(reference: self::reference(id: 'open'), reachable: true, status: 200, checkedAt: 'now'));
		$this->assertSame([], $this->queries);
		$this->assertSame([], $this->saves);
	}//end testWithoutTheSchemaNothingIsAskedAndNothingIsListed()

	public function testTheCheckReadsEveryPageUpToTheCap(): void {
		$rows = [self::reference(id: 'closed', overrides: ['depublicationDate' => '2021-01-01T00:00:00+00:00'])];
		for ($i = 1; $i <= 1100; $i++) {
			$rows[] = self::reference(id: 'r' . $i, overrides: ['publication' => 'pub-' . $i]);
		}

		$all = $this->store(rows: $rows)->inWindow(cap: 5000);
		$this->assertCount(1100, $all);
		$this->assertSame(['r1', 'r1100'], [$all[0]['id'], $all[1099]['id']]);
		$this->assertSame([1, 2, 3], array_map(static fn (array $q): int => $q['query']['_page'], $this->queries));

		$this->queries = [];
		$this->assertSame(['r1', 'r2', 'r3'], array_column($this->store(rows: $rows)->inWindow(cap: 3), 'id'));
		$this->assertCount(1, $this->queries);
	}//end testTheCheckReadsEveryPageUpToTheCap()

	public function testTheResultOfACheckIsWrittenOntoTheReference(): void {
		$reference = self::reference(id: 'ref-1', overrides: ['@self' => ['id' => 'ref-1'], 'reachable' => true]);

		$this->assertTrue($this->store(rows: [])->recordCheck(reference: $reference, reachable: false, status: 404, checkedAt: '2026-11-13T03:00:00+00:00'));

		$this->assertCount(1, $this->saves);
		$save = $this->saves[0];
		$this->assertSame(['ref-1', '19', '91', false, false], [$save['uuid'], $save['register'], $save['schema'], $save['_rbac'], $save['_multitenancy']]);
		$this->assertFalse($save['object']['reachable']);
		$this->assertSame(404, $save['object']['lastStatus']);
		$this->assertSame('2026-11-13T03:00:00+00:00', $save['object']['lastCheckedAt']);
		$this->assertSame('https://documenten.voorbeeldgemeente.nl/ref-1.pdf', $save['object']['url']);
		$this->assertArrayNotHasKey('id', $save['object']);
		$this->assertArrayNotHasKey('@self', $save['object']);
	}//end testTheResultOfACheckIsWrittenOntoTheReference()
}//end class
