<?php

/**
 * Tests for PublicationDocumentCount.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\DocumentReferencesInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Publication\PublicationDocumentCount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A publication's documents are its files plus the references pointing at it (REQ-PSG-004).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
 */
class PublicationDocumentCountTest extends TestCase {

	/** @var list<mixed> The files the file mapper answers. */
	private array $files = [];

	/** A failure the file mapper throws, or null. */
	private ?\Throwable $filesFailure = null;

	/** @var list<mixed> The reference rows OpenRegister answers. */
	private array $references = [];

	/** A failure the reference search throws, or null. */
	private ?\Throwable $referencesFailure = null;

	/** Whether the document reference schema is configured. */
	private bool $referenceSchema = true;

	/** Whether the file mapper is available. */
	private bool $fileMapper = true;

	/** @var list<array<string, mixed>> The reference queries sent. */
	public array $queries = [];

	/** @var list<string> The ids loaded through ObjectService::find(). */
	public array $found = [];

	/**
	 * The counter over the doubles above.
	 *
	 * @return PublicationDocumentCount The counter.
	 */
	private function counter(): PublicationDocumentCount {
		$test = $this;
		$mapper = new class ($test) {
			public function __construct(private readonly PublicationDocumentCountTest $test) {
			}

			public function getFilesForObject(object $object): array {
				return $this->test->filesFor();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === PublicationDocumentCount::FILE_MAPPER && $this->fileMapper);
		$container->method('get')->willReturn($mapper);

		$objects = new class ($test) {
			public function __construct(private readonly PublicationDocumentCountTest $test) {
			}

			public function find(string $id): ?object {
				$this->test->found[] = $id;
				return new \stdClass();
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->referencesFor(query: $query);
			}
		};
		$lookup = $this->createMock(OpenRegisterLookupInterface::class);
		$lookup->method('isInstalled')->willReturn(true);
		$lookup->method('objectService')->willReturn($objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'publication_register') {
					return '7';
				}

				if ($key === DocumentReferencesInterface::SCHEMA_KEY) {
					return $this->referenceSchema === true ? '42' : '';
				}

				return $default;
			}
		);

		return new PublicationDocumentCount(
			container: $container,
			openRegister: $lookup,
			config: $config,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The files the mapper double answers.
	 *
	 * @return list<mixed> The files.
	 */
	public function filesFor(): array {
		if ($this->filesFailure !== null) {
			throw $this->filesFailure;
		}

		return $this->files;
	}

	/**
	 * The reference page the search double answers.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array{results: list<mixed>} The page.
	 */
	public function referencesFor(array $query): array {
		$this->queries[] = $query;
		if ($this->referencesFailure !== null) {
			throw $this->referencesFailure;
		}

		return ['results' => $this->references];
	}

	/** A publication with no files and one reference carries one document. */
	public function testAReferenceCountsAsADocument(): void {
		$this->references = [['id' => 'r1', 'publication' => 'p1', 'url' => 'https://bron.example/besluit.pdf']];

		$this->assertSame(expected: 1, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
		$this->assertSame(expected: ['register' => '7', 'schema' => '42'], actual: $this->queries[0]['@self']);
		$this->assertSame(expected: 'p1', actual: $this->queries[0]['publication']);
	}

	/** Files and references add up; a reference row of another publication or an entity row is read correctly. */
	public function testFilesAndReferencesAddUp(): void {
		$this->files = [['id' => 1], ['id' => 2]];
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['publication' => 'p1'];
			}
		};
		$this->references = [$entity, ['publication' => 'p2'], 'not a row'];

		$this->assertSame(expected: 3, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
	}

	/** Without the document reference schema only the files count, and no search is sent. */
	public function testWithoutTheReferenceSchemaOnlyFilesCount(): void {
		$this->referenceSchema = false;
		$this->files = [['id' => 1]];
		$this->references = [['publication' => 'p1']];

		$this->assertSame(expected: 1, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
		$this->assertSame(expected: [], actual: $this->queries);
	}

	/** An unreadable part counts as zero. */
	public function testAnUnreadablePartCountsAsZero(): void {
		$this->filesFailure = new \RuntimeException('The file table could not be read.');
		$this->referencesFailure = new \RuntimeException('OpenRegister is down.');

		$this->assertSame(expected: 0, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
	}

	/** Without a loaded entity the publication is found by id; without the file mapper files count as zero. */
	public function testTheEntityIsLoadedWhenNotGiven(): void {
		$this->files = [['id' => 1]];
		$counter = $this->counter();

		$this->assertSame(expected: 1, actual: $counter->count(id: 'p1'));
		$this->assertSame(expected: ['p1'], actual: $this->found);

		$this->fileMapper = false;
		$this->assertSame(expected: 0, actual: $counter->count(id: 'p1'));
	}

	/** An empty id carries no documents and reads nothing. */
	public function testAnEmptyIdCountsZero(): void {
		$this->assertSame(expected: 0, actual: $this->counter()->count(id: ''));
		$this->assertSame(expected: [], actual: $this->queries);
	}
}
