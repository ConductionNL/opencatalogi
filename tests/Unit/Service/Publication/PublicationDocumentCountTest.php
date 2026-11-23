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

	/**
	 * The files the file mapper answers.
	 *
	 * @var list<mixed>
	 */
	private array $files = [];

	/**
	 * A failure the file mapper throws, or null.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $filesFailure = null;

	/**
	 * The reference rows OpenRegister answers.
	 *
	 * @var list<mixed>
	 */
	private array $references = [];

	/**
	 * A failure the reference search throws, or null.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $referencesFailure = null;

	/**
	 * Whether the document reference schema is configured.
	 *
	 * @var boolean
	 */
	private bool $referenceSchema = true;

	/**
	 * Whether the file mapper is available.
	 *
	 * @var boolean
	 */
	private bool $fileMapper = true;

	/**
	 * The reference queries sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * The ids loaded through ObjectService::find().
	 *
	 * @var list<string>
	 */
	public array $found = [];

	/**
	 * The counter over the doubles above.
	 *
	 * @return PublicationDocumentCount The counter.
	 */
	private function counter(): PublicationDocumentCount {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === PublicationDocumentCount::FILE_MAPPER && $this->fileMapper);
		$container->method('get')->willReturn($this->mapperDouble());

		$lookup = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$lookup->method('isInstalled')->willReturn(true);
		$lookup->method('objectService')->willReturn($this->objectServiceDouble());

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => $this->configValue(key: $key, default: $default)
		);

		return new PublicationDocumentCount(
			container: $container,
			openRegister: $lookup,
			config: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end counter()

	/**
	 * The app config value the counter reads.
	 *
	 * @param string $key     The key.
	 * @param string $default The default.
	 *
	 * @return string The value.
	 */
	private function configValue(string $key, string $default): string {
		if ($key === 'publication_register') {
			return '7';
		}

		if ($key === DocumentReferencesInterface::SCHEMA_KEY && $this->referenceSchema === true) {
			return '42';
		}

		return $default;
	}//end configValue()

	/**
	 * A file mapper double answering filesFor().
	 *
	 * @return object The double.
	 */
	private function mapperDouble(): object {
		return new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param PublicationDocumentCountTest $test The test that holds the files.
			 */
			public function __construct(private readonly PublicationDocumentCountTest $test) {
			}//end __construct()

			/**
			 * The files of the object.
			 *
			 * @param object $object The object.
			 *
			 * @return array<int, mixed> The files.
			 */
			public function getFilesForObject(object $object): array {
				return $this->test->filesFor();
			}//end getFilesForObject()
		};
	}//end mapperDouble()

	/**
	 * An ObjectService double for find() and searchObjectsPaginated().
	 *
	 * @return object The double.
	 */
	private function objectServiceDouble(): object {
		return new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param PublicationDocumentCountTest $test The test that records the calls.
			 */
			public function __construct(private readonly PublicationDocumentCountTest $test) {
			}//end __construct()

			/**
			 * Find an object by id.
			 *
			 * @param string $id The id.
			 *
			 * @return object The object.
			 */
			public function find(string $id): object {
				$this->test->found[] = $id;
				return new \stdClass();
			}//end find()

			/**
			 * One page of objects.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param boolean              $_rbac         Whether RBAC applies.
			 * @param boolean              $_multitenancy Whether multitenancy applies.
			 *
			 * @return array{results: list<mixed>} The page.
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				return $this->test->referencesFor(query: $query);
			}//end searchObjectsPaginated()
		};
	}//end objectServiceDouble()

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
	}//end filesFor()

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
	}//end referencesFor()

	/**
	 * A publication with no files and one reference carries one document.
	 *
	 * @return void
	 */
	public function testAReferenceCountsAsADocument(): void {
		$this->references = [['id' => 'r1', 'publication' => 'p1', 'url' => 'https://bron.example/besluit.pdf']];

		$this->assertSame(expected: 1, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
		$this->assertSame(expected: ['register' => '7', 'schema' => '42'], actual: $this->queries[0]['@self']);
		$this->assertSame(expected: 'p1', actual: $this->queries[0]['publication']);
	}//end testAReferenceCountsAsADocument()

	/**
	 * Files and references add up; a row of another publication does not count, an entity row does.
	 *
	 * @return void
	 */
	public function testFilesAndReferencesAddUp(): void {
		$this->files = [['id' => 1], ['id' => 2]];
		$entity = new class implements \JsonSerializable {
			/**
			 * The reference's properties.
			 *
			 * @return array<string, string> The properties.
			 */
			public function jsonSerialize(): array {
				return ['publication' => 'p1'];
			}//end jsonSerialize()
		};
		$this->references = [$entity, ['publication' => 'p2'], 'not a row'];

		$this->assertSame(expected: 3, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
	}//end testFilesAndReferencesAddUp()

	/**
	 * Without the document reference schema only the files count, and no search is sent.
	 *
	 * @return void
	 */
	public function testWithoutTheReferenceSchemaOnlyFilesCount(): void {
		$this->referenceSchema = false;
		$this->files = [['id' => 1]];
		$this->references = [['publication' => 'p1']];

		$this->assertSame(expected: 1, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testWithoutTheReferenceSchemaOnlyFilesCount()

	/**
	 * An unreadable part counts as zero.
	 *
	 * @return void
	 */
	public function testAnUnreadablePartCountsAsZero(): void {
		$this->filesFailure = new \RuntimeException('The file table could not be read.');
		$this->referencesFailure = new \RuntimeException('OpenRegister is down.');

		$this->assertSame(expected: 0, actual: $this->counter()->count(id: 'p1', publication: new \stdClass()));
	}//end testAnUnreadablePartCountsAsZero()

	/**
	 * Without a loaded entity the publication is found by id; without the file mapper files count as zero.
	 *
	 * @return void
	 */
	public function testTheEntityIsLoadedWhenNotGiven(): void {
		$this->files = [['id' => 1]];
		$counter = $this->counter();

		$this->assertSame(expected: 1, actual: $counter->count(id: 'p1'));
		$this->assertSame(expected: ['p1'], actual: $this->found);

		$this->fileMapper = false;
		$this->assertSame(expected: 0, actual: $counter->count(id: 'p1'));
	}//end testTheEntityIsLoadedWhenNotGiven()

	/**
	 * An empty id carries no documents and reads nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyIdCountsZero(): void {
		$this->assertSame(expected: 0, actual: $this->counter()->count(id: ''));
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testAnEmptyIdCountsZero()
}//end class
