<?php

/**
 * Golden test for SchemaOrgService::buildPublicationNode.
 *
 * Every case is recorded once and replayed from the fixture: the JSON-LD node,
 * key order included, and every OpenRegister lookup made to build it.
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
 * Golden test for SchemaOrgService::buildPublicationNode.
 *
 * @covers \OCA\OpenCatalogi\Service\SchemaOrgService
 */
class SchemaOrgPublicationNodeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schemaorg-publication-node-golden.json';

	/**
	 * Every lookup made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The recorded cases: publication, catalog, slug and the OpenRegister world.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}>
	 */
	public static function cases(): array {
		$full = [
			'id' => 'pub-1',
			'@self' => ['uuid' => 'uuid-1', 'schema' => 5, 'updated' => '2026-03-04T10:11:12+00:00'],
			'title' => 'Besluit',
			'summary' => 'ignored when a description is set',
			'description' => 'Een besluit',
			'publicationDate' => '2026-01-01',
			'license' => 'cc0',
			'tags' => ['a', '', 3, ['x'], true, null, 'b'],
		];
		$elected = ['title' => 'Open data', 'schemaOrgDataset' => 'yes', 'organisation' => ['title' => 'Gemeente', 'oin' => '000123']];
		$plain = ['title' => 'Woo'];

		return [
			'full, not elected' => [$full, $plain, 'woo', 'files'],
			'full, elected' => [$full, $elected, 'opendata', 'files'],
			'elected, no files service' => [$full, $elected, 'opendata', 'none'],
			'elected, files fail' => [$full, $elected, 'opendata', 'failing-files'],
			'elected, no catalog title' => [$full, ['schemaOrgDataset' => true], 'od', 'files'],
			'elected false string' => [$full, ['schemaOrgDataset' => 'false'], 'od', 'files'],
			'minimal' => [['id' => 'x'], $plain, 'woo', 'files'],
			'no id at all' => [[], $plain, 'woo', 'files'],
			'name and summary fallbacks' => [['@self' => ['uuid' => 'u'], 'name' => 'N', 'summary' => 'S', 'publicationDate' => '2026-02-03'], $plain, 'woo', 'files'],
			'empty strings' => [['id' => 'e', 'title' => '', 'description' => '', 'license' => '', 'tags' => [], '@self' => ['updated' => '']], $plain, 'woo', 'files'],
			'non-string license and tags' => [['id' => 'n', 'license' => 5, 'tags' => 'a,b'], $plain, 'woo', 'files'],
			'unknown schema marker' => [['id' => 's', '@self' => ['schema' => 9]], $plain, 'woo', 'files'],
			'no schema mapper' => [['id' => 's', '@self' => ['schema' => 5]], $plain, 'woo', 'no-mapper'],
			'publisher from catalog config' => [['id' => 'p'], ['dcatPublisherName' => 'P', 'dcatPublisherUri' => ''], 'woo', 'files'],
			'no publisher' => [['id' => 'p'], ['dcatPublisherName' => '', 'dcatPublisherUri' => ''], 'woo', 'files'],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $object  The publication.
	 * @param array<string, mixed> $catalog The catalog.
	 * @param string               $slug    The catalog slug.
	 * @param string               $world   Which OpenRegister services resolve.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testNodeMatchesTheRecording(array $object, array $catalog, string $slug, string $world): void {
		$name = $this->dataName();
		$node = $this->service(world: $world)->buildPublicationNode($object, $catalog, $slug);
		$actual = ['node' => $node, 'keys' => array_keys($node), 'asked' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testNodeMatchesTheRecording()

	/**
	 * Build the service with a real DcatService over the given world.
	 *
	 * @param string $world Which OpenRegister services resolve.
	 *
	 * @return SchemaOrgService The service.
	 */
	private function service(string $world): SchemaOrgService {
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

			public function find(int $id): array {
				$this->log[] = 'schema:' . $id;
				if ($id !== 5) {
					throw new \RuntimeException('no schema');
				}

				return ['x-schema-org' => 'schema:Report'];
			}
		};
		$files = new class($log, $world) {
			public function __construct(
				private array &$log,
				private string $world,
			) {
			}

			public function getFiles(mixed $object): array {
				$this->log[] = 'files:' . (string)$object;
				if ($this->world === 'failing-files') {
					throw new \RuntimeException('files unreadable');
				}

				return ['raw'];
			}

			public function formatFiles(array $files): array {
				return [
					'results' => [
						['downloadUrl' => 'https://f/1', 'mimetype' => 'application/pdf', 'title' => 'One', 'size' => '12'],
						['accessUrl' => 'https://f/2', 'mimeType' => 'text/csv', 'name' => 'Two', 'size' => 'big'],
						['downloadUrl' => ''],
					],
				];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($world, $mapper, $files): object {
				$this->log[] = 'container:' . $id;
				if ($id === 'OCA\OpenRegister\Db\SchemaMapper' && $world !== 'no-mapper') {
					return $mapper;
				}

				if ($id === 'OCA\OpenRegister\Service\FileService' && in_array($world, ['files', 'failing-files'], true) === true) {
					return $files;
				}

				throw new \RuntimeException('not resolvable: ' . $id);
			}
		);

		return new SchemaOrgService($container, $dcat, $urls, $this->createMock(LoggerInterface::class));

	}//end service()
}//end class
