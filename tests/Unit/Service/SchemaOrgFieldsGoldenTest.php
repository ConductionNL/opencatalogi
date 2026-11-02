<?php

/**
 * Golden test for the field helpers behind SchemaOrgService::buildPublicationNode.
 *
 * Varies what the earlier golden keeps fixed: the schema marker (prefixed,
 * bare, empty, only a prefix, an entity, a failing lookup), the publisher
 * chain (organisation title, name, oin and uri against the catalog
 * defaults), the attachment shapes (non-string titles and media types,
 * numeric and non-numeric sizes, no results key) and the descriptive fields
 * (null and non-string values). Each node is compared, key order included,
 * with what was recorded, together with every lookup made.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/SchemaOrgFieldsGoldenTest.php`.
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
 * @spec openspec/specs/structured-data-discoverability/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\SchemaOrgService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the SchemaOrgService field helpers.
 */
class SchemaOrgFieldsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schemaorg-fields-golden.json';

	/**
	 * Every lookup made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The recorded cases: publication, catalog, catalog defaults and the attachments.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$elected = ['schemaOrgDataset' => true, 'title' => 'Open'];
		$files = [
			['downloadUrl' => 'https://f/1', 'mimetype' => 7, 'title' => 3, 'name' => 'ignored', 'size' => 0],
			['accessUrl' => 'https://f/2', 'mimetype' => '', 'mimeType' => 'text/csv', 'title' => '', 'size' => '12.9'],
			['downloadUrl' => 5, 'accessUrl' => 'https://f/3'],
			['downloadUrl' => null, 'accessUrl' => 'https://f/4', 'mimetype' => null, 'mimeType' => 'text/plain', 'title' => null, 'name' => 'Four', 'size' => null],
			['accessUrl' => ''],
		];

		return [
			'prefixed marker' => [['object' => ['id' => 'a', '@self' => ['schema' => 1]]]],
			'empty marker' => [['object' => ['id' => 'a', '@self' => ['schema' => 2]]]],
			'prefix only' => [['object' => ['id' => 'a', '@self' => ['schema' => 3]]]],
			'bare marker' => [['object' => ['id' => 'a', '@self' => ['schema' => 4]]]],
			'entity marker' => [['object' => ['id' => 'a', '@self' => ['schema' => 6]]]],
			'entity not an array' => [['object' => ['id' => 'a', '@self' => ['schema' => 7]]]],
			'numeric marker' => [['object' => ['id' => 'a', '@self' => ['schema' => 8]]]],
			'failing lookup' => [['object' => ['id' => 'a', '@self' => ['schema' => 99]]]],
			'organisation title and oin' => [['catalog' => ['organisation' => ['title' => 'T', 'name' => 'N', 'oin' => 'O', 'uri' => 'U']]]],
			'organisation name and uri' => [['catalog' => ['organisation' => ['name' => 'N', 'uri' => 'U']]]],
			'organisation empty, defaults' => [['catalog' => ['organisation' => []]]],
			'defaults name only' => [['catalog' => ['dcatPublisherName' => 'P', 'dcatPublisherUri' => '']]],
			'defaults uri only' => [['catalog' => ['dcatPublisherName' => '', 'dcatPublisherUri' => 'https://u']]],
			'numeric organisation' => [['catalog' => ['organisation' => ['title' => 12, 'oin' => 34]]]],
			'nothing to publish as' => [['catalog' => ['dcatPublisherName' => '', 'dcatPublisherUri' => '', 'organisation' => ['title' => '', 'oin' => '']]]],
			'odd attachments' => [['object' => ['id' => 'f'], 'catalog' => $elected, 'files' => $files]],
			'no results key' => [['object' => ['id' => 'f'], 'catalog' => $elected, 'files' => 'no-results']],
			'uuid only' => [['object' => ['@self' => ['uuid' => 'u-1']], 'catalog' => $elected, 'files' => $files]],
			'no object id' => [['object' => ['title' => 'x'], 'catalog' => $elected, 'files' => $files]],
			'id wins over uuid' => [['object' => ['id' => 'i-1', '@self' => ['uuid' => 'u-1']], 'catalog' => $elected, 'files' => $files]],
			'null fields' => [['object' => ['id' => 'n', 'title' => null, 'name' => 'Named', 'description' => null, 'summary' => 'Sum', '@self' => ['updated' => null], 'publicationDate' => 'not a date', 'tags' => null]]],
			'numeric fields' => [['object' => ['id' => 'n', 'title' => 0, 'description' => 1.5, 'publicationDate' => '2026-01-02', 'license' => 'cc-by', 'tags' => [0, '0', false]]]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testNodeMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$service = $this->service(files: ($case['files'] ?? []));
		$node = $service->buildPublicationNode(($case['object'] ?? ['id' => 'x']), ($case['catalog'] ?? []), 'cat');
		$actual = ['node' => $node, 'asked' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testNodeMatchesTheRecording()

	/**
	 * Build the service over a schema mapper with fixed markers and the given attachments.
	 *
	 * @param array<int, mixed>|string $files The formatted attachments, or 'no-results'.
	 *
	 * @return SchemaOrgService The service.
	 */
	private function service(array|string $files): SchemaOrgService {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://example.test/');

		$dcat = $this->createMock(DcatService::class);
		$dcat->method('datasetIri')->willReturnCallback(
			static fn (string $slug, string $uuid): string => "https://example.test/apps/opencatalogi/api/$slug/$uuid"
		);
		$dcat->method('resolveDefaults')->willReturnCallback(
			static fn (array $catalog): array => [
				'publisherName' => ($catalog['dcatPublisherName'] ?? 'Gemeente Test'),
				'publisherUri' => ($catalog['dcatPublisherUri'] ?? 'https://example.test/org'),
				'organisation' => ($catalog['organisation'] ?? null),
			]
		);

		$log = &$this->log;
		$mapper = new class($log) {
			public function __construct(
				private array &$log,
			) {
			}

			public function find(int $id): mixed {
				$this->log[] = 'schema:' . $id;
				return match ($id) {
					1 => ['x-schema-org' => 'schema:Report'],
					2 => ['x-schema-org' => ''],
					3 => ['x-schema-org' => 'schema:'],
					4 => ['x-schema-org' => 'Dataset'],
					6 => new class {
						public function jsonSerialize(): array {
							return ['x-schema-org' => 'schema:Article'];
						}
					},
					7 => new class {
						public function jsonSerialize(): string {
							return 'schema:Article';
						}
					},
					8 => ['x-schema-org' => 42],
					default => throw new \RuntimeException('no schema'),
				};
			}
		};
		$fileService = new class($log, $files) {
			public function __construct(
				private array &$log,
				private array|string $files,
			) {
			}

			public function getFiles(mixed $object): array {
				$this->log[] = 'files:' . (string)$object;
				return ['raw'];
			}

			public function formatFiles(array $files): array {
				if ($this->files === 'no-results') {
					return ['total' => 0];
				}

				return ['results' => $this->files];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper, $fileService): object {
				if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
					return $mapper;
				}

				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}

				throw new \RuntimeException('not resolvable: ' . $id);
			}
		);

		return new SchemaOrgService($container, $dcat, $urls, $this->createMock(LoggerInterface::class));

	}//end service()
}//end class
