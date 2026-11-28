<?php

/**
 * Tests for the DCAT catalogue (JSON-LD) source fetcher and its dataset reader.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Harvest
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Harvest;

use GuzzleHttp\Psr7\Response;
use OCA\OpenCatalogi\Harvest\DcatDatasetReader;
use OCA\OpenCatalogi\Harvest\DcatJsonLdFetcher;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\HarvestHttpClient;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The fetcher reads the whole catalogue; the reader keys every dataset by its `@id`.
 */
class DcatJsonLdFetcherTest extends TestCase {

	/**
	 * The feed's address.
	 */
	private const URL = 'https://data.example.nl/catalog.jsonld';

	/**
	 * Skip where OpenRegister's harvest classes are not next to the app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(SourceFetcherRegistry::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s harvest classes are not next to the app.');
		}

	}//end setUp()

	/**
	 * A container that has none of the asked services.
	 *
	 * @return ContainerInterface
	 */
	private function emptyContainer(): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(self::notFound());
		return $container;

	}//end emptyContainer()

	/**
	 * The container's answer for a service that is not installed.
	 *
	 * @return RuntimeException
	 */
	private static function notFound(): RuntimeException {
		return new class('not installed') extends RuntimeException implements NotFoundExceptionInterface {
		};

	}//end notFound()

	/**
	 * The fetcher under test.
	 *
	 * @param ContainerInterface|null $container The container, or one without integriq.
	 *
	 * @return DcatJsonLdFetcher
	 */
	private function fetcher(?ContainerInterface $container=null): DcatJsonLdFetcher {
		return new DcatJsonLdFetcher(
			container: ($container ?? $this->emptyContainer()),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end fetcher()

	/**
	 * A feed source with the given config.
	 *
	 * @param array<string, mixed> $config The config.
	 *
	 * @return Source
	 */
	private function source(array $config): Source {
		$source = new Source();
		$source->setConfig($config);
		return $source;

	}//end source()

	/**
	 * A feed with a source URL.
	 *
	 * @return Source
	 */
	private function urlSource(): Source {
		return $this->source(config: ['sourceUrl' => self::URL, 'targetCatalog' => 'c0ffee00-0000-4000-8000-000000000001']);

	}//end urlSource()

	/**
	 * A feed with a source slug.
	 *
	 * @param string $slug The integriq source.
	 *
	 * @return Source
	 */
	private function slugSource(string $slug='data-overheid'): Source {
		return $this->source(config: ['sourceSlug' => $slug, 'targetCatalog' => 'c0ffee00-0000-4000-8000-000000000001']);

	}//end slugSource()

	/**
	 * A guarded client that answers the feed URL once.
	 *
	 * @param string $body   The body.
	 * @param int    $status The status.
	 *
	 * @return HarvestHttpClient
	 */
	private function http(string $body, int $status=200): HarvestHttpClient {
		$http = $this->createMock(originalClassName: HarvestHttpClient::class);
		$http->expects($this->once())->method('get')
			->with(self::URL, $this->arrayHasKey(key: 'Accept'))
			->willReturn(new Response(status: $status, headers: [], body: $body));
		return $http;

	}//end http()

	/**
	 * A guarded client that must not be asked.
	 *
	 * @return HarvestHttpClient
	 */
	private function unusedHttp(): HarvestHttpClient {
		$http = $this->createMock(originalClassName: HarvestHttpClient::class);
		$http->expects($this->never())->method('get');
		return $http;

	}//end unusedHttp()

	/**
	 * A fixture document.
	 *
	 * @param string $name The fixture file.
	 *
	 * @return string
	 */
	private function fixture(string $name): string {
		return (string) file_get_contents(__DIR__.'/../../fixtures/harvest/'.$name);

	}//end fixture()

	/**
	 * A container that answers integriq's call service with the given double.
	 *
	 * @param object $callService The call service double.
	 *
	 * @return ContainerInterface
	 */
	private function integriq(object $callService): ContainerInterface {
		$notFound  = self::notFound();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($callService, $notFound): object {
				if ($id === 'OCA\Integriq\Service\CallService') {
					return $callService;
				}

				throw $notFound;
			}
		);
		return $container;

	}//end integriq()

	/**
	 * The type and name are the ones forms list.
	 *
	 * @return void
	 */
	public function testTheTypeAndNameAreTheOnesFormsList(): void {
		$fetcher = $this->fetcher();

		$this->assertSame(expected: 'opencatalogi.dcat-jsonld', actual: $fetcher->getType());
		$this->assertSame(expected: 'DCAT catalogue (JSON-LD)', actual: $fetcher->getDisplayName());
		$this->assertTrue(condition: $fetcher->supports(type: 'opencatalogi.dcat-jsonld'));
		$this->assertFalse(condition: $fetcher->supports(type: 'rest-api'));

	}//end testTheTypeAndNameAreTheOnesFormsList()

	/**
	 * The config needs a catalogue and exactly one of URL and slug.
	 *
	 * @return void
	 */
	public function testTheConfigNeedsACatalogueAndExactlyOneSource(): void {
		$schema = $this->fetcher()->getConfigSchema();

		$this->assertSame(expected: ['targetCatalog'], actual: $schema['required']);
		$this->assertSame(expected: [['required' => ['sourceUrl']], ['required' => ['sourceSlug']]], actual: $schema['oneOf']);
		$this->assertSame(expected: 'uri', actual: $schema['properties']['sourceUrl']['format']);

	}//end testTheConfigNeedsACatalogueAndExactlyOneSource()

	/**
	 * Datasets typed in a graph are items keyed by their `@id`.
	 *
	 * @return void
	 */
	public function testDatasetsInAGraphAreItems(): void {
		$document = (string) json_encode(
			[
				'@graph' => [
					['@id' => 'https://data.example.nl/catalog', '@type' => 'dcat:Catalog'],
					['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset', 'dct:title' => 'A'],
					['@id' => 'https://data.example.nl/b', '@type' => ['dcat:Dataset'], 'dct:title' => 'B'],
					['@id' => 'https://data.example.nl/c', '@type' => 'http://www.w3.org/ns/dcat#Dataset'],
				],
			]
		);

		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $this->http(body: $document));

		$this->assertTrue(condition: $batch->complete);
		$this->assertSame(
			expected: ['https://data.example.nl/a', 'https://data.example.nl/b', 'https://data.example.nl/c'],
			actual: array_keys($batch->items)
		);
		$this->assertSame(expected: 'A', actual: $batch->items['https://data.example.nl/a']['dct:title']);
		$this->assertSame(expected: [], actual: $batch->errors);

	}//end testDatasetsInAGraphAreItems()

	/**
	 * Datasets a catalogue lists are items, typed or not.
	 *
	 * @return void
	 */
	public function testDatasetsUnderTheCatalogueAreItems(): void {
		$document = (string) json_encode(
			[
				'@id' => 'https://data.example.nl/catalog',
				'@type' => 'dcat:Catalog',
				'dcat:dataset' => [
					['@id' => 'https://data.example.nl/a', 'dct:title' => 'A'],
					['@id' => 'https://data.example.nl/b', 'dct:title' => 'B'],
				],
			]
		);

		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $this->http(body: $document));

		$this->assertTrue(condition: $batch->complete);
		$this->assertSame(expected: ['https://data.example.nl/a', 'https://data.example.nl/b'], actual: array_keys($batch->items));

	}//end testDatasetsUnderTheCatalogueAreItems()

	/**
	 * One dataset listed as an object, not a list, is an item.
	 *
	 * @return void
	 */
	public function testASingleListedDatasetIsAnItem(): void {
		$batch = (new DcatDatasetReader())->batchOf(
			document: [
				'@type' => 'Catalog',
				'dataset' => ['@id' => 'https://data.example.nl/only', 'title' => 'Only'],
			]
		);

		$this->assertSame(expected: ['https://data.example.nl/only'], actual: array_keys($batch->items));

	}//end testASingleListedDatasetIsAnItem()

	/**
	 * A catalogue's bare reference to a dataset in the graph counts once.
	 *
	 * @return void
	 */
	public function testABareReferenceIsNotCountedTwice(): void {
		$batch = (new DcatDatasetReader())->batchOf(
			document: [
				'@graph' => [
					['@type' => 'dcat:Catalog', 'dcat:dataset' => [['@id' => 'https://data.example.nl/a']]],
					['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset', 'dct:title' => 'A'],
				],
			]
		);

		$this->assertSame(expected: ['https://data.example.nl/a'], actual: array_keys($batch->items));
		$this->assertSame(expected: 'A', actual: $batch->items['https://data.example.nl/a']['dct:title']);
		$this->assertSame(expected: [], actual: $batch->errors);

	}//end testABareReferenceIsNotCountedTwice()

	/**
	 * A dataset without `@id` is an error naming its title, not an item.
	 *
	 * @return void
	 */
	public function testADatasetWithoutIdIsAnError(): void {
		$batch = (new DcatDatasetReader())->batchOf(
			document: [
				'@type' => 'dcat:Catalog',
				'dcat:dataset' => [
					['@id' => 'https://data.example.nl/a', 'dct:title' => 'A'],
					['@id' => 'https://data.example.nl/b', 'dct:title' => 'B'],
					['dct:title' => ['@value' => 'Zonder id', '@language' => 'nl']],
					['@id' => 'https://data.example.nl/c', 'dct:title' => 'C'],
				],
			]
		);

		$this->assertTrue(condition: $batch->complete);
		$this->assertCount(expectedCount: 3, haystack: $batch->items);
		$this->assertCount(expectedCount: 1, haystack: $batch->errors);
		$this->assertNull(actual: $batch->errors[0]['externalId']);
		$this->assertStringContainsString(needle: '"Zonder id"', haystack: $batch->errors[0]['message']);

	}//end testADatasetWithoutIdIsAnError()

	/**
	 * An untitled dataset without `@id` still says what it is.
	 *
	 * @return void
	 */
	public function testAnUntitledDatasetWithoutIdIsNamedUntitled(): void {
		$batch = (new DcatDatasetReader())->batchOf(document: [['@type' => 'dcat:Dataset', '@id' => '  ']]);

		$this->assertSame(expected: [], actual: $batch->items);
		$this->assertStringContainsString(needle: '(untitled)', haystack: $batch->errors[0]['message']);

	}//end testAnUntitledDatasetWithoutIdIsNamedUntitled()

	/**
	 * The fixture catalogue holds three datasets.
	 *
	 * @return void
	 */
	public function testTheFixtureCatalogueHoldsThreeDatasets(): void {
		$http  = $this->http(body: $this->fixture(name: 'dcat-catalog.jsonld'));
		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $http);

		$this->assertTrue(condition: $batch->complete);
		$this->assertSame(
			expected: [
				'https://data.example.nl/dataset/afvalinzameling',
				'https://data.example.nl/dataset/speelplaatsen',
				'https://data.example.nl/dataset/parkeerzones',
			],
			actual: array_keys($batch->items)
		);
		$this->assertSame(expected: [], actual: $batch->errors);

	}//end testTheFixtureCatalogueHoldsThreeDatasets()

	/**
	 * The second fixture changes one, removes one and has one without `@id`.
	 *
	 * @return void
	 */
	public function testTheSecondFixtureChangesOneRemovesOneAndHasOneWithoutId(): void {
		$http  = $this->http(body: $this->fixture(name: 'dcat-catalog-v2.jsonld'));
		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $http);

		$this->assertTrue(condition: $batch->complete);
		$this->assertSame(
			expected: ['https://data.example.nl/dataset/afvalinzameling', 'https://data.example.nl/dataset/parkeerzones'],
			actual: array_keys($batch->items)
		);
		$this->assertSame(expected: '2026-11-20', actual: $batch->items['https://data.example.nl/dataset/afvalinzameling']['dct:modified']);
		$this->assertCount(expectedCount: 1, haystack: $batch->errors);
		$this->assertStringContainsString(needle: 'Bomenregister', haystack: $batch->errors[0]['message']);

	}//end testTheSecondFixtureChangesOneRemovesOneAndHasOneWithoutId()

	/**
	 * A document that is not JSON gives an incomplete batch with no items.
	 *
	 * @return void
	 */
	public function testAParseErrorIsIncomplete(): void {
		$http  = $this->http(body: '{"@graph": [ not json');
		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $http);

		$this->assertFalse(condition: $batch->complete);
		$this->assertSame(expected: [], actual: $batch->items);
		$this->assertSame(expected: 'The source did not answer with a readable JSON-LD document.', actual: $batch->errors[0]['message']);

	}//end testAParseErrorIsIncomplete()

	/**
	 * An error status gives an incomplete batch naming the status.
	 *
	 * @return void
	 */
	public function testAnErrorStatusIsIncomplete(): void {
		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $this->http(body: 'gone', status: 404));

		$this->assertFalse(condition: $batch->complete);
		$this->assertSame(expected: [], actual: $batch->items);
		$this->assertSame(expected: 'The source answered with status 404.', actual: $batch->errors[0]['message']);

	}//end testAnErrorStatusIsIncomplete()

	/**
	 * A request that fails gives an incomplete batch with the reason.
	 *
	 * @return void
	 */
	public function testAFetchFailureIsIncomplete(): void {
		$http = $this->createMock(originalClassName: HarvestHttpClient::class);
		$http->method('get')->willThrowException(new RuntimeException(message: 'Harvest request failed after 3 attempts: timeout'));

		$batch = $this->fetcher()->gatherItems(source: $this->urlSource(), since: null, http: $http);

		$this->assertFalse(condition: $batch->complete);
		$this->assertSame(expected: [], actual: $batch->items);
		$this->assertStringContainsString(needle: 'timeout', haystack: $batch->errors[0]['message']);

	}//end testAFetchFailureIsIncomplete()

	/**
	 * A feed with neither URL nor slug asks nothing and says why.
	 *
	 * @return void
	 */
	public function testAFeedWithoutASourceIsIncomplete(): void {
		$source = $this->source(config: ['targetCatalog' => 'c0ffee00-0000-4000-8000-000000000001']);
		$batch  = $this->fetcher()->gatherItems(source: $source, since: null, http: $this->unusedHttp());

		$this->assertFalse(condition: $batch->complete);
		$this->assertSame(expected: 'The feed names neither a source URL nor a source slug.', actual: $batch->errors[0]['message']);

	}//end testAFeedWithoutASourceIsIncomplete()

	/**
	 * A source slug without integriq says what is missing.
	 *
	 * @return void
	 */
	public function testASourceSlugWithoutIntegriqFailsWithAReason(): void {
		$batch = $this->fetcher()->gatherItems(source: $this->slugSource(), since: null, http: $this->unusedHttp());

		$this->assertFalse(condition: $batch->complete);
		$this->assertSame(expected: [], actual: $batch->items);
		$this->assertSame(expected: 'integriq is not installed, so a source slug cannot be read', actual: $batch->errors[0]['message']);

	}//end testASourceSlugWithoutIntegriqFailsWithAReason()

	/**
	 * A source slug is read through integriq's call service.
	 *
	 * @return void
	 */
	public function testASourceSlugIsReadThroughIntegriq(): void {
		$callService = new class($this->fixture(name: 'dcat-catalog.jsonld')) {

			/**
			 * The calls asked.
			 *
			 * @var array<int, array<string, string>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param string $body The body to answer with.
			 */
			public function __construct(private string $body) {
			}//end __construct()

			/**
			 * Integriq's call, recorded.
			 *
			 * @param string $source   The source.
			 * @param string $endpoint The endpoint.
			 * @param string $method   The method.
			 *
			 * @return array<string, string>
			 */
			public function call(string $source, string $endpoint, string $method): array {
				$this->calls[] = ['source' => $source, 'endpoint' => $endpoint, 'method' => $method];
				return ['body' => $this->body];
			}//end call()
		};

		$fetcher = $this->fetcher(container: $this->integriq(callService: $callService));
		$batch   = $fetcher->gatherItems(source: $this->slugSource(), since: null, http: $this->unusedHttp());

		$this->assertTrue(condition: $batch->complete);
		$this->assertCount(expectedCount: 3, haystack: $batch->items);
		$this->assertSame(expected: [['source' => 'data-overheid', 'endpoint' => '', 'method' => 'GET']], actual: $callService->calls);

	}//end testASourceSlugIsReadThroughIntegriq()

	/**
	 * Integriq's call log answer is read through its response.
	 *
	 * @return void
	 */
	public function testAnIntegriqCallLogResponseIsRead(): void {
		$callService = new class {

			/**
			 * Integriq's call, answered with a call log.
			 *
			 * @param string $source   The source.
			 * @param string $endpoint The endpoint.
			 * @param string $method   The method.
			 *
			 * @return object
			 */
			public function call(string $source, string $endpoint, string $method): object {
				return new class {

					/**
					 * The decoded response.
					 *
					 * @return array<string, mixed>
					 */
					public function getResponse(): array {
						return ['@graph' => [['@id' => 'https://data.example.nl/a', '@type' => 'dcat:Dataset']]];
					}//end getResponse()
				};
			}//end call()
		};

		$fetcher = $this->fetcher(container: $this->integriq(callService: $callService));
		$batch   = $fetcher->gatherItems(source: $this->slugSource(slug: 's'), since: null, http: $this->unusedHttp());

		$this->assertTrue(condition: $batch->complete);
		$this->assertSame(expected: ['https://data.example.nl/a'], actual: array_keys($batch->items));

	}//end testAnIntegriqCallLogResponseIsRead()

	/**
	 * An integriq answer the fetcher cannot read is incomplete.
	 *
	 * @return void
	 */
	public function testAnUnreadableIntegriqAnswerIsIncomplete(): void {
		$callService = new class {

			/**
			 * Integriq's call, answered with something unreadable.
			 *
			 * @param string $source   The source.
			 * @param string $endpoint The endpoint.
			 * @param string $method   The method.
			 *
			 * @return integer
			 */
			public function call(string $source, string $endpoint, string $method): int {
				return 500;
			}//end call()
		};

		$fetcher = $this->fetcher(container: $this->integriq(callService: $callService));
		$batch   = $fetcher->gatherItems(source: $this->slugSource(slug: 's'), since: null, http: $this->unusedHttp());

		$this->assertFalse(condition: $batch->complete);
		$this->assertStringContainsString(needle: 'cannot read for source s', haystack: $batch->errors[0]['message']);

	}//end testAnUnreadableIntegriqAnswerIsIncomplete()

	/**
	 * A batch fetcher refuses the per-item questions.
	 *
	 * @return void
	 */
	public function testItIsNeverAskedForAnIdListOrOneItem(): void {
		$fetcher = $this->fetcher();

		try {
			$fetcher->gather(source: $this->urlSource());
			$this->fail(message: 'gather() must refuse');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'gatherItems', haystack: $e->getMessage());
		}

		$this->expectException(exception: RuntimeException::class);
		$fetcher->fetch(source: $this->urlSource(), externalId: 'https://data.example.nl/a');

	}//end testItIsNeverAskedForAnIdListOrOneItem()
}//end class
