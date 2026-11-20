<?php

/**
 * Characterization tests for the federated peer lookups of DirectoryService:
 * getPublications(), getUsed() and getPublication().
 *
 * The peer hosts are on the `local_federation_hosts` allowlist, so the SSRF guard
 * passes without DNS, and the Guzzle handler is a mock, so these tests run offline.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

class DirectoryPeerFetchTest extends TestCase {
	private const OWN = 'https://me.example.org/apps/opencatalogi/api/directory';
	private const PEER = 'https://peer.example.org/apps/opencatalogi/api/publications';
	private const OTHER = 'https://other.example.org/apps/opencatalogi/api/federation/publications';
	private const DOWN = 'https://down.example.org/api/publications';
	private const LAN = 'http://10.1.2.3/api/publications';

	private DirectoryService $service;

	/** @var array<int, array<string, mixed>> */
	private array $history = [];

	protected function setUp(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/opencatalogi/api/directory');
		$urlGenerator->method('getAbsoluteURL')->willReturn(self::OWN);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'local_federation_hosts' => 'peer.example.org,other.example.org,down.example.org',
				default => $default,
			}
		);

		$ref = new ReflectionClass(DirectoryService::class);
		$this->service = $ref->newInstanceWithoutConstructor();
		$props = [
			'appName' => 'opencatalogi',
			'urlGenerator' => $urlGenerator,
			'config' => $config,
			'appManager' => $this->createMock(IAppManager::class),
			'cachedUniqueDirs' => [self::OWN, self::LAN, self::PEER, self::DOWN, self::OTHER],
			'cacheTimestamp' => time(),
		];
		foreach ($props as $name => $value) {
			$ref->getProperty($name)->setValue($this->service, $value);
		}
	}

	/**
	 * Guzzle config whose handler answers with the given queue and records each request.
	 *
	 * @param array<int, mixed> $queue Responses or exceptions, in request order.
	 *
	 * @return array<string, mixed>
	 */
	private function guzzle(array $queue): array {
		$stack = HandlerStack::create(new MockHandler($queue));
		$stack->push(Middleware::history($this->history));
		return ['handler' => $stack, 'query_params' => ['q' => 'x']];
	}

	/**
	 * The requested URLs in order.
	 *
	 * @return array<int, string>
	 */
	private function requestedUrls(): array {
		return array_map(static fn (array $entry): string => urldecode((string)$entry['request']->getUri()), $this->history);
	}

	public function testGetPublicationsMergesPeersSkipsOwnAndUnsafeAndAggregatesFacets(): void {
		$peerBody = [
			'results' => [
				['id' => 'a', 'title' => 'A'],
				['id' => 'b', '@self' => 'flat'],
			],
			'facets' => ['type' => [['_id' => 'doc', 'count' => 2]]],
			'total' => 2,
		];
		$otherBody = [
			'results' => [
				['uuid' => 'b'],
				['id' => 'c', '@self' => ['schema' => 's']],
			],
			'facets' => ['type' => [['_id' => 'doc', 'count' => 1], ['_id' => 'img', 'count' => 5]]],
			'total' => 7,
		];

		$result = $this->service->getPublications(
			$this->guzzle([
				new Response(200, [], (string)json_encode($peerBody)),
				new Response(500, [], '{}'),
				new Response(200, [], (string)json_encode($otherBody)),
			])
		);

		$suffix = '?q=x&_aggregate=false&_include_catalogs=true&_extend[0]=@self.schema&_extend[1]=@self.register';
		$this->assertSame([self::PEER . $suffix, self::DOWN . $suffix, self::OTHER . $suffix], $this->requestedUrls());
		$this->assertFalse($this->history[0]['options']['allow_redirects']);
		$this->assertSame(
			[
				'results' => [
					['id' => 'a', 'title' => 'A', '@self' => ['directory' => 'peer.example.org']],
					['id' => 'b', '@self' => ['directory' => 'peer.example.org']],
					['id' => 'c', '@self' => ['schema' => 's', 'directory' => 'other.example.org']],
				],
				'sources' => [
					'peer.example.org' => self::PEER . $this->rawSuffix(),
					'other.example.org' => self::OTHER . $this->rawSuffix(),
				],
				'facets' => ['type' => [['_id' => 'img', 'count' => 5], ['_id' => 'doc', 'count' => 3]]],
				'total' => 9,
			],
			$result
		);
	}

	/**
	 * The query string exactly as http_build_query writes it for getPublications().
	 *
	 * @return string
	 */
	private function rawSuffix(): string {
		return '?' . http_build_query([
			'q' => 'x',
			'_aggregate' => 'false',
			'_include_catalogs' => 'true',
			'_extend' => ['@self.schema', '@self.register'],
		]);
	}

	public function testGetPublicationsTreatsBadPeerAnswersAsEmpty(): void {
		$result = $this->service->getPublications(
			$this->guzzle([
				new Response(200, [], 'not json'),
				new ConnectException('down', new Request('GET', self::DOWN)),
				new Response(200, [], 'null'),
			])
		);

		$this->assertSame(['results' => [], 'sources' => [], 'facets' => [], 'total' => 0], $result);
	}

	public function testGetPublicationsWithoutDirectoriesReturnsEmptyShape(): void {
		(new ReflectionClass(DirectoryService::class))->getProperty('cachedUniqueDirs')->setValue($this->service, []);

		$this->assertSame(['results' => [], 'sources' => []], $this->service->getPublications());
	}

	public function testGetUsedCallsThePeerFederationEndpointAndDeduplicates(): void {
		$result = $this->service->getUsed(
			'u/1',
			$this->guzzle([
				new Response(200, [], (string)json_encode(['results' => [['id' => 'x'], ['id' => 'y']]])),
				new Response(404, [], '{}'),
				new Response(200, [], (string)json_encode([['uuid' => 'y'], ['id' => 'z']])),
			])
		);

		$suffix = '?q=x&_aggregate=false&_extend[0]=@self.schema&_extend[1]=@self.register';
		$this->assertSame(
			[
				'https://peer.example.org/apps/opencatalogi/api/federation/publications/u/1/used' . $suffix,
				'https://down.example.org/api/federation/publications/u/1/used' . $suffix,
				self::OTHER . '/u/1/used' . $suffix,
			],
			$this->requestedUrls()
		);
		$this->assertSame([['id' => 'x'], ['id' => 'y'], ['id' => 'z']], $result['results']);
		$this->assertSame(['peer.example.org', 'other.example.org'], array_keys($result['sources']));
		$this->assertStringStartsWith(
			'https://peer.example.org/apps/opencatalogi/api/federation/publications/u%2F1/used?q=x&_aggregate=false',
			$result['sources']['peer.example.org']
		);
	}

	public function testGetUsedKeepsAnyArrayAnswerAndSkipsFailures(): void {
		$result = $this->service->getUsed(
			'u1',
			$this->guzzle([
				new Response(200, [], '{"results": "nope"}'),
				new ConnectException('down', new Request('GET', self::DOWN)),
				new Response(200, [], 'broken'),
			])
		);

		$this->assertSame(['nope'], $result['results']);
		$this->assertSame(['peer.example.org'], array_keys($result['sources']));
	}

	public function testGetUsedWithoutDirectoriesReturnsEmptyShape(): void {
		(new ReflectionClass(DirectoryService::class))->getProperty('cachedUniqueDirs')->setValue($this->service, []);

		$this->assertSame(['results' => [], 'sources' => []], $this->service->getUsed('u1'));
	}

	public function testGetPublicationReturnsTheFirstPeerThatHasIt(): void {
		$result = $this->service->getPublication(
			'p1',
			$this->guzzle([
				new Response(200, [], '[]'),
				new Response(200, [], (string)json_encode(['id' => 'p1', 'from' => 'down'])),
				new Response(200, [], (string)json_encode(['id' => 'p1', 'from' => 'other'])),
			])
		);

		$this->assertSame(
			[
				'https://peer.example.org/apps/opencatalogi/api/federation/publications/p1?q=x&_aggregate=false&_extend[0]=@self.schema&_extend[1]=@self.register',
				'https://down.example.org/api/federation/publications/p1?q=x&_aggregate=false&_extend[0]=@self.schema&_extend[1]=@self.register',
				self::OTHER . '/p1?q=x&_aggregate=false&_extend[0]=@self.schema&_extend[1]=@self.register',
			],
			$this->requestedUrls()
		);
		$this->assertSame(['id' => 'p1', 'from' => 'down'], $result['result']);
		$this->assertSame(['down.example.org'], array_keys($result['source']));
	}

	public function testGetPublicationReturnsNullWhenNoPeerHasIt(): void {
		$result = $this->service->getPublication(
			'p1',
			$this->guzzle([
				new Response(404, [], '{}'),
				new ConnectException('down', new Request('GET', self::DOWN)),
				new Response(200, [], 'broken'),
			])
		);

		$this->assertNull($result);
	}

	/**
	 * Wire a container answering OpenRegister's LanguageService with the
	 * language the visitor asked for and where it came from.
	 *
	 * @param string $language The preferred language.
	 * @param string $source Where it came from: query, header or default.
	 *
	 * @return void
	 */
	private function wireVisitorLanguage(string $language, string $source): void {
		$languageService = new class($language, $source) {
			public function __construct(private string $language, private string $source) {
			}

			public function getPreferredLanguage(): string {
				return $this->language;
			}

			public function getRequestedLanguageSource(): string {
				return $this->source;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\LanguageService')->willReturn($languageService);
		(new ReflectionClass(DirectoryService::class))->getProperty('container')->setValue($this->service, $container);
	}

	/**
	 * A peer read forwards the language the visitor asked for, so the peer
	 * answers in it (publication-translations REQ-PTR-002).
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testAPeerReadForwardsTheVisitorsLanguage(): void {
		$this->wireVisitorLanguage(language: 'en', source: 'header');

		$this->service->getPublications(
			$this->guzzle([
				new Response(200, [], '{"results":[],"total":0}'),
				new Response(200, [], '{"results":[],"total":0}'),
				new Response(200, [], '{"results":[],"total":0}'),
			])
		);

		$this->assertCount(3, $this->history);
		foreach ($this->history as $entry) {
			$this->assertSame('en', $entry['request']->getHeaderLine('Accept-Language'));
			$this->assertSame('application/json', $entry['request']->getHeaderLine('Accept'));
		}
	}

	/**
	 * A visitor who asked for no language gets the peer's default: nothing is forwarded.
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	public function testAPeerReadForwardsNoLanguageWhenNoneWasAsked(): void {
		$this->wireVisitorLanguage(language: 'nl', source: 'default');

		$this->service->getPublication(
			'x',
			$this->guzzle([new Response(404, [], '{}'), new Response(404, [], '{}'), new Response(404, [], '{}')])
		);

		$this->assertNotEmpty($this->history);
		foreach ($this->history as $entry) {
			$this->assertFalse($entry['request']->hasHeader('Accept-Language'));
		}
	}
}
