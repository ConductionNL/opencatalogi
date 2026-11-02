<?php

/**
 * Characterization tests for DirectoryService::syncDirectory().
 *
 * The peer host is on the `local_federation_hosts` allowlist, so the SSRF guard
 * passes without DNS and these tests run offline.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

class DirectorySyncDirectoryTest extends TestCase {
	private const PEER = 'https://peer.example.org/api/directory';

	private Client|MockObject $client;
	private ObjectService|MockObject $objectService;
	private BroadcastService|MockObject $broadcastService;
	private IRequest|MockObject $request;
	private IURLGenerator|MockObject $urlGenerator;
	private DirectoryService $service;

	protected function setUp(): void {
		$this->client = $this->createMock(Client::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->broadcastService = $this->createMock(BroadcastService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://me.example.org');
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/opencatalogi/api/directory');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://me.example.org/apps/opencatalogi/api/directory');

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'listing_schema' => 'ls',
				'listing_register' => 'lr',
				'local_federation_hosts' => 'peer.example.org,other.example.org',
				default => $default,
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$ref = new ReflectionClass(DirectoryService::class);
		$this->service = $ref->newInstanceWithoutConstructor();
		$props = [
			'appName' => 'opencatalogi',
			'client' => $this->client,
			'urlGenerator' => $this->urlGenerator,
			'config' => $config,
			'container' => $container,
			'appManager' => $appManager,
			'broadcastService' => $this->broadcastService,
			'request' => $this->request,
			'connectionReporter' => null,
			'uniqueDirectories' => [self::PEER, 'https://other.example.org/api/directory'],
			'cachedUniqueDirs' => null,
			'cacheTimestamp' => 0,
		];
		foreach ($props as $name => $value) {
			$ref->getProperty($name)->setValue($this->service, $value);
		}
	}

	/**
	 * Make the peer answer with the given body.
	 *
	 * @param string $body The response body.
	 *
	 * @return void
	 */
	private function peerAnswers(string $body): void {
		$this->client->method('get')->willReturnCallback(
			static function (string $url) use ($body): Response {
				self::assertSame(self::PEER . '?_limit=10000&include-federated=1', $url);
				return new Response(200, [], $body);
			}
		);
	}

	public function testListingsAreCountedPerOutcomeAndOwnListingsSuppressTheBroadcast(): void {
		$this->peerAnswers((string)json_encode(['results' => [
			['id' => 'new', 'catalog' => 'c1', 'directory' => self::PEER],
			['id' => 'elsewhere', 'catalog' => 'c2', 'directory' => 'https://other.example.org/api/directory'],
			['title' => 'no id', 'directory' => self::PEER],
			['id' => 'mine', 'catalog' => 'c3', 'directory' => 'https://me.example.org/apps/opencatalogi/api/directory'],
			['id' => 'lan', 'catalog' => 'c4', 'directory' => 'http://192.168.1.4/api/directory'],
		]]));
		$this->objectService->method('searchObjects')->willReturn([]);
		$this->broadcastService->expects($this->never())->method('broadcast');

		$result = $this->service->syncDirectory(self::PEER);

		$this->assertInstanceOf(\DateTime::class, $result['sync_time']);
		unset($result['sync_time']);
		$this->assertSame(
			[
				'directory_url' => self::PEER,
				'listings_created' => 1,
				'listings_updated' => 0,
				'listings_unchanged' => 0,
				'listings_skipped' => 1,
				'listings_failed' => 1,
				'total_processed' => 3,
				'errors' => ['Listing unknown: Invalid listing data: missing id'],
				'listing_details' => [
					['listing_id' => 'new', 'listing_title' => 'Unknown', 'action' => 'created', 'success' => true, 'error' => null],
					[
						'listing_id' => 'elsewhere',
						'listing_title' => 'Unknown',
						'action' => 'skipped_other_directory',
						'success' => true,
						'error' => null,
						'reason' => 'Listing belongs to directory https://other.example.org/api/directory which is processed separately',
					],
					['listing_id' => 'unknown', 'listing_title' => 'no id', 'action' => 'none', 'success' => false, 'error' => 'Invalid listing data: missing id'],
				],
			],
			$result
		);
	}

	public function testAPeerWithoutOurListingsIsBroadcastToAndABroadcastFailureIsIgnored(): void {
		$this->peerAnswers((string)json_encode(['results' => []]));
		$this->request->method('getHeader')->willReturn('');
		$this->broadcastService->expects($this->once())->method('broadcast')->with(self::PEER)
			->willThrowException(new RuntimeException('peer down'));

		$result = $this->service->syncDirectory(self::PEER);

		$this->assertSame(0, $result['total_processed']);
		$this->assertSame([], $result['errors']);
	}

	public function testASystemBroadcastIsNotBroadcastBack(): void {
		$this->peerAnswers((string)json_encode(['results' => []]));
		$this->request->method('getHeader')->willReturn('OpenCatalogi-Broadcast/1.0');
		$this->broadcastService->expects($this->never())->method('broadcast');

		$this->assertSame(0, $this->service->syncDirectory(self::PEER)['total_processed']);
	}

	public function testAnswerWithoutResultsChangesNothing(): void {
		$this->peerAnswers((string)json_encode(['message' => 'hello']));
		$this->broadcastService->expects($this->never())->method('broadcast');

		$result = $this->service->syncDirectory(self::PEER);

		$this->assertSame([0, 0, []], [$result['total_processed'], $result['listings_failed'], $result['listing_details']]);
	}

	public function testInvalidJsonIsRethrownAfterMarkingTheDirectoryListings(): void {
		$this->peerAnswers('{not json');
		$listing = new class extends \OCP\AppFramework\Db\Entity {
			public function jsonSerialize(): array {
				return ['id' => 'stored', 'object' => []];
			}
		};
		$this->objectService->method('searchObjects')->willReturn([$listing]);
		$saved = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved) {
				$saved = $args;
				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		);

		try {
			$this->service->syncDirectory(self::PEER);
			$this->fail('expected an exception');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Invalid JSON response from directory', $e->getMessage());
		}

		$this->assertSame(500, $saved[0]['statusCode']);
		$this->assertSame('Sync failed: Invalid JSON response from directory', $saved[0]['lastError']);
	}

	public function testAnHttpFailureIsRethrownAsARequestExceptionWithTheStatusRecorded(): void {
		$request = new Request('GET', self::PEER);
		$this->client->method('get')->willThrowException(
			new RequestException('Server error', $request, new Response(502))
		);
		$listing = new class extends \OCP\AppFramework\Db\Entity {
			public function jsonSerialize(): array {
				return ['id' => 'stored', 'object' => ['title' => 'Stored']];
			}
		};
		$this->objectService->method('searchObjects')->willReturn([$listing]);
		$saved = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved) {
				$saved = $args;
				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		);

		try {
			$this->service->syncDirectory(self::PEER);
			$this->fail('expected an exception');
		} catch (RequestException $e) {
			$this->assertSame('Failed to fetch directory data: Server error', $e->getMessage());
			$this->assertSame($request, $e->getRequest());
			$this->assertSame(502, $e->getResponse()->getStatusCode());
		}

		$this->assertSame('Stored', $saved[0]['title']);
		$this->assertSame(502, $saved[0]['statusCode']);
		$this->assertSame('Failed to fetch directory data: Server error', $saved[0]['lastError']);
		$this->assertSame('stored', $saved[4]);
	}

	public function testAConnectionFailureIsRethrownWithASyntheticRequestAndStatus500(): void {
		$this->client->method('get')->willThrowException(
			new ConnectException('refused', new Request('GET', 'https://elsewhere'))
		);
		$this->objectService->method('searchObjects')->willReturn([]);

		try {
			$this->service->syncDirectory(self::PEER);
			$this->fail('expected an exception');
		} catch (RequestException $e) {
			$this->assertSame('Failed to fetch directory data: refused', $e->getMessage());
			$this->assertSame(self::PEER, (string)$e->getRequest()->getUri());
			$this->assertNull($e->getResponse());
		}
	}

	public function testInvalidUrlsAreRefusedBeforeAnyRequest(): void {
		$this->client->expects($this->never())->method('get');

		foreach (['' => 'Directory URL cannot be empty', 'nope' => 'Invalid directory URL provided', 'https://me.example.org/x' => 'Cannot sync with current directory'] as $url => $message) {
			try {
				$this->service->syncDirectory((string)$url);
				$this->fail('expected an exception for ' . $url);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}
}
