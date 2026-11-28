<?php

/**
 * Tests for the DCAT catalogue (JSON-LD) source fetcher.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Harvest;

use GuzzleHttp\Psr7\Response;
use OCA\OpenCatalogi\Harvest\DcatDatasetReader;
use OCA\OpenCatalogi\Harvest\DcatJsonLdFetcher;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\HarvestHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The fetcher reads the whole catalogue; the reader keys every dataset by its `@id`.
 */
class DcatJsonLdFetcherTest extends TestCase {

	private const URL = 'https://data.example.nl/catalog.jsonld';

	protected function setUp(): void {
		if (class_exists(SourceFetcherRegistry::class) === false) {
			$this->markTestSkipped('OpenRegister\'s harvest classes are not next to the app.');
		}
	}

	/**
	 * A container that has none of the asked services.
	 *
	 * @return ContainerInterface The container.
	 */
	private function emptyContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(
			new class('not installed') extends RuntimeException implements NotFoundExceptionInterface {
			}
		);
		return $container;
	}

	private function fetcher(?ContainerInterface $container = null): DcatJsonLdFetcher {
		return new DcatJsonLdFetcher(
			container: ($container ?? $this->emptyContainer()),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A feed source with the given config.
	 *
	 * @param array<string, mixed> $config The config.
	 *
	 * @return Source The source.
	 */
	private function source(array $config): Source {
		$source = new Source();
		$source->setConfig($config);
		return $source;
	}

	/**
	 * A guarded client that answers the feed URL with the body and status given.
	 */
	private function http(string $body, int $status = 200): HarvestHttpClient {
		$http = $this->createMock(HarvestHttpClient::class);
		$http->expects($this->once())->method('get')
			->with(self::URL, $this->arrayHasKey('Accept'))
			->willReturn(new Response($status, [], $body));
		return $http;
	}

	private function fixture(string $name): string {
		return (string) file_get_contents(__DIR__ . '/../../fixtures/harvest/' . $name);
	}

	private function urlSource(): Source {
		return $this->source(['sourceUrl' => self::URL, 'targetCatalog' => 'c0ffee00-0000-4000-8000-000000000001']);
	}

	public function testTheTypeAndNameAreTheOnesFormsList(): void {
		$fetcher = $this->fetcher();

		$this->assertSame('opencatalogi.dcat-jsonld', $fetcher->getType());
		$this->assertSame('DCAT catalogue (JSON-LD)', $fetcher->getDisplayName());
		$this->assertTrue($fetcher->supports('opencatalogi.dcat-jsonld'));
		$this->assertFalse($fetcher->supports('rest-api'));
	}

	public function testTheConfigNeedsACatalogueAndExactlyOneSource(): void {
		$schema = $this->fetcher()->getConfigSchema();

		$this->assertSame(['targetCatalog'], $schema['required']);
		$this->assertSame([['required' => ['sourceUrl']], ['required' => ['sourceSlug']]], $schema['oneOf']);
		$this->assertSame('uri', $schema['properties']['sourceUrl']['format']);
	}

	public function testDatasetsInAGraphAreItems(): void {
		$document = json_encode([
			'@graph' => [
				['@id' => 'https://data.example.nl/catalog', '@type' => 'dcat:Catalog'],
				['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset', 'dct:title' => 'A'],
				['@id' => 'https://data.example.nl/b', '@type' => ['dcat:Dataset'], 'dct:title' => 'B'],
				['@id' => 'https://data.example.nl/c', '@type' => 'http://www.w3.org/ns/dcat#Dataset'],
			],
		]);

		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http((string) $document));

		$this->assertTrue($batch->complete);
		$this->assertSame(
			['https://data.example.nl/a', 'https://data.example.nl/b', 'https://data.example.nl/c'],
			array_keys($batch->items)
		);
		$this->assertSame('A', $batch->items['https://data.example.nl/a']['dct:title']);
		$this->assertSame([], $batch->errors);
	}

	public function testDatasetsUnderTheCatalogueAreItems(): void {
		$document = json_encode([
			'@id' => 'https://data.example.nl/catalog',
			'@type' => 'dcat:Catalog',
			'dcat:dataset' => [
				['@id' => 'https://data.example.nl/a', 'dct:title' => 'A'],
				['@id' => 'https://data.example.nl/b', 'dct:title' => 'B'],
			],
		]);

		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http((string) $document));

		$this->assertTrue($batch->complete);
		$this->assertSame(['https://data.example.nl/a', 'https://data.example.nl/b'], array_keys($batch->items));
	}

	public function testASingleListedDatasetIsAnItem(): void {
		$batch = (new DcatDatasetReader())->batchOf([
			'@type' => 'Catalog',
			'dataset' => ['@id' => 'https://data.example.nl/only', 'title' => 'Only'],
		]);

		$this->assertSame(['https://data.example.nl/only'], array_keys($batch->items));
	}

	public function testABareReferenceIsNotCountedTwice(): void {
		$batch = (new DcatDatasetReader())->batchOf([
			'@graph' => [
				[
					'@type' => 'dcat:Catalog',
					'dcat:dataset' => [['@id' => 'https://data.example.nl/a']],
				],
				['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset', 'dct:title' => 'A'],
			],
		]);

		$this->assertSame(['https://data.example.nl/a'], array_keys($batch->items));
		$this->assertSame('A', $batch->items['https://data.example.nl/a']['dct:title']);
		$this->assertSame([], $batch->errors);
	}

	public function testADatasetWithoutIdIsAnError(): void {
		$batch = (new DcatDatasetReader())->batchOf([
			'@type' => 'dcat:Catalog',
			'dcat:dataset' => [
				['@id' => 'https://data.example.nl/a'  , 'dct:title' => 'A'],
				['@id' => 'https://data.example.nl/b'  , 'dct:title' => 'B'],
				['dct:title' => ['@value' => 'Zonder id', '@language' => 'nl']],
				['@id' => 'https://data.example.nl/c'  , 'dct:title' => 'C'],
			],
		]);

		$this->assertTrue($batch->complete);
		$this->assertCount(3, $batch->items);
		$this->assertCount(1, $batch->errors);
		$this->assertNull($batch->errors[0]['externalId']);
		$this->assertStringContainsString('"Zonder id"', $batch->errors[0]['message']);
	}

	public function testTheFixtureCatalogueHoldsThreeDatasets(): void {
		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http($this->fixture('dcat-catalog.jsonld')));

		$this->assertTrue($batch->complete);
		$this->assertSame(
			[
				'https://data.example.nl/dataset/afvalinzameling',
				'https://data.example.nl/dataset/speelplaatsen',
				'https://data.example.nl/dataset/parkeerzones',
			],
			array_keys($batch->items)
		);
		$this->assertSame([], $batch->errors);
	}

	public function testTheSecondFixtureChangesOneRemovesOneAndHasOneWithoutId(): void {
		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http($this->fixture('dcat-catalog-v2.jsonld')));

		$this->assertTrue($batch->complete);
		$this->assertSame(
			['https://data.example.nl/dataset/afvalinzameling', 'https://data.example.nl/dataset/parkeerzones'],
			array_keys($batch->items)
		);
		$this->assertSame('2026-11-20', $batch->items['https://data.example.nl/dataset/afvalinzameling']['dct:modified']);
		$this->assertCount(1, $batch->errors);
		$this->assertStringContainsString('Bomenregister', $batch->errors[0]['message']);
	}

	public function testAParseErrorIsIncomplete(): void {
		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http('{"@graph": [ not json'));

		$this->assertFalse($batch->complete);
		$this->assertSame([], $batch->items);
		$this->assertSame('The source did not answer with a readable JSON-LD document.', $batch->errors[0]['message']);
	}

	public function testAnErrorStatusIsIncomplete(): void {
		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $this->http('gone', 404));

		$this->assertFalse($batch->complete);
		$this->assertSame([], $batch->items);
		$this->assertSame('The source answered with status 404.', $batch->errors[0]['message']);
	}

	public function testAFetchFailureIsIncomplete(): void {
		$http = $this->createMock(HarvestHttpClient::class);
		$http->method('get')->willThrowException(new RuntimeException('Harvest request failed after 3 attempts: timeout'));

		$batch = $this->fetcher()->gatherItems($this->urlSource(), null, $http);

		$this->assertFalse($batch->complete);
		$this->assertSame([], $batch->items);
		$this->assertStringContainsString('timeout', $batch->errors[0]['message']);
	}

	public function testAFeedWithoutASourceIsIncomplete(): void {
		$http = $this->createMock(HarvestHttpClient::class);
		$http->expects($this->never())->method('get');

		$batch = $this->fetcher()->gatherItems($this->source(['targetCatalog' => 'x']), null, $http);

		$this->assertFalse($batch->complete);
		$this->assertSame('The feed names neither a source URL nor a source slug.', $batch->errors[0]['message']);
	}

	public function testASourceSlugWithoutIntegriqFailsWithAReason(): void {
		$http = $this->createMock(HarvestHttpClient::class);
		$http->expects($this->never())->method('get');

		$batch = $this->fetcher()->gatherItems($this->source(['sourceSlug' => 'data-overheid', 'targetCatalog' => 'x']), null, $http);

		$this->assertFalse($batch->complete);
		$this->assertSame([], $batch->items);
		$this->assertSame('integriq is not installed, so a source slug cannot be read', $batch->errors[0]['message']);
	}

	/**
	 * A container that answers integriq's call service with the given double.
	 */
	private function integriq(object $callService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($callService): object {
				if ($id === 'OCA\Integriq\Service\CallService') {
					return $callService;
				}

				throw new class('not installed') extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);
		return $container;
	}

	public function testASourceSlugIsReadThroughIntegriq(): void {
		$callService = new class($this->fixture('dcat-catalog.jsonld')) {
			/** @var array<int, array<string, string>> */
			public array $calls = [];

			public function __construct(private string $body) {
			}

			public function call(string $source, string $endpoint, string $method): array {
				$this->calls[] = ['source' => $source, 'endpoint' => $endpoint, 'method' => $method];
				return ['body' => $this->body];
			}
		};

		$http = $this->createMock(HarvestHttpClient::class);
		$http->expects($this->never())->method('get');

		$batch = $this->fetcher($this->integriq($callService))
			->gatherItems($this->source(['sourceSlug' => 'data-overheid', 'targetCatalog' => 'x']), null, $http);

		$this->assertTrue($batch->complete);
		$this->assertCount(3, $batch->items);
		$this->assertSame([['source' => 'data-overheid', 'endpoint' => '', 'method' => 'GET']], $callService->calls);
	}

	public function testAnIntegriqCallLogResponseIsRead(): void {
		$callLog = new class {
			public function getResponse(): array {
				return ['@graph' => [['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset']]];
			}
		};
		$callService = new class($callLog) {
			public function __construct(private object $log) {
			}

			public function call(string $source, string $endpoint, string $method): object {
				return $this->log;
			}
		};

		$batch = $this->fetcher($this->integriq($callService))
			->gatherItems($this->source(['sourceSlug' => 's', 'targetCatalog' => 'x']), null, $this->createMock(HarvestHttpClient::class));

		$this->assertTrue($batch->complete);
		$this->assertSame(['https://data.example.nl/a'], array_keys($batch->items));
	}

	public function testAnUnreadableIntegriqAnswerIsIncomplete(): void {
		$callService = new class {
			public function call(string $source, string $endpoint, string $method): int {
				return 500;
			}
		};

		$batch = $this->fetcher($this->integriq($callService))
			->gatherItems($this->source(['sourceSlug' => 's', 'targetCatalog' => 'x']), null, $this->createMock(HarvestHttpClient::class));

		$this->assertFalse($batch->complete);
		$this->assertStringContainsString('cannot read for source s', $batch->errors[0]['message']);
	}

	public function testItIsNeverAskedForAnIdListOrOneItem(): void {
		$fetcher = $this->fetcher();

		try {
			$fetcher->gather($this->urlSource());
			$this->fail('gather() must refuse');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('gatherItems', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$fetcher->fetch($this->urlSource(), 'https://data.example.nl/a');
	}
}
