<?php

/**
 * Characterization tests for DirectoryService::syncListing().
 *
 * Pins the exact payload saved to OpenRegister on create, update and on the
 * error path, so the method can be split without changing what is stored.
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
use RuntimeException;

class DirectorySyncListingTest extends TestCase {
	private ObjectService|MockObject $objectService;
	private DirectoryService $service;

	/** @var array<int, array> named arguments of every saveObject() call */
	private array $saved = [];

	/** Whether the first saveObject() call throws. */
	private bool $failFirstSave = false;

	/** Whether the code currently runs inside the listing write context. */
	private bool $elevated = false;

	/** @var array<int, bool> per saveObject() call, whether it ran elevated */
	private array $savedElevated = [];

	/** @var array<int, bool> per searchObjects() call, whether it ran elevated */
	private array $searchedElevated = [];

	protected function setUp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'listing_schema' => 'ls',
				'listing_register' => 'lr',
				default => $default,
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$names = array_map(
					static fn (\ReflectionParameter $param): string => $param->getName(),
					(new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters()
				);
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$this->saved[] = array_intersect_key($named, array_flip(['object', 'extend', 'register', 'schema', 'uuid']));
				$this->savedElevated[] = $this->elevated;
				if ($this->failFirstSave === true && count($this->saved) === 1) {
					throw new RuntimeException('save refused');
				}

				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->service = new DirectoryService(
			$this->createMock(IURLGenerator::class),
			$config,
			$container,
			$appManager,
			$this->createMock(BroadcastService::class),
			$this->createMock(IRequest::class)
		);
	}

	/**
	 * An entity whose jsonSerialize() returns the given data.
	 *
	 * @param array $data The serialised entity.
	 *
	 * @return \OCP\AppFramework\Db\Entity
	 */
	private function entity(array $data): \OCP\AppFramework\Db\Entity {
		return new class($data) extends \OCP\AppFramework\Db\Entity {
			private array $fakeData;

			public function __construct(array $data) {
				$this->fakeData = $data;
			}

			public function jsonSerialize(): array {
				return $this->fakeData;
			}
		};
	}

	/**
	 * Assert the time-stamped keys are ISO 8601 and replace them with a marker.
	 *
	 * @param array $object The saved object.
	 * @param array $keys   The keys holding "now".
	 *
	 * @return array
	 */
	private function maskNow(array $object, array $keys): array {
		foreach ($keys as $key) {
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $object[$key]);
			$object[$key] = 'NOW';
		}

		return $object;
	}

	public function testCreateSavesTheNormalisedListing(): void {
		$searched = null;
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (...$args) use (&$searched) {
				$searched = $args;
				return [];
			}
		);

		$result = $this->service->syncListing(
			[
				'id' => 'l-1',
				'summary' => '',
				'published' => '2024-03-01 10:00:00',
				'schemas' => ['a', 'b'],
				'@self' => [
					'id' => 'self-uuid',
					'name' => 'From self',
					'relations' => [
						'catalog' => 'cat-9',
						'publications' => 'https://peer.example.org/api/publications',
						'search' => '',
						'directory' => 'https://peer.example.org/api/directory',
					],
				],
			],
			'https://source.example.org/api/directory'
		);

		$this->assertSame(
			['listing_id' => 'l-1', 'listing_title' => 'From self', 'action' => 'created', 'success' => true, 'error' => null],
			$result
		);
		$this->assertSame(
			[
				'@self' => ['register' => 'lr', 'schema' => 'ls'],
				'catalogusId' => 'cat-9',
				'directory' => 'https://peer.example.org/api/directory',
			],
			$searched[0]
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame(['extend' => [], 'register' => 'lr', 'schema' => 'ls', 'uuid' => 'self-uuid'], array_diff_key($this->saved[0], ['object' => 1]));
		$object = $this->maskNow($this->saved[0]['object'], ['lastSync', 'lastSuccessAt']);
		$this->assertSame(
			[
				'id' => 'l-1',
				'summary' => 'unknown',
				'published' => (new \DateTime('2024-03-01 10:00:00'))->format('c'),
				'schemas' => ['a', 'b'],
				'catalog' => 'cat-9',
				'catalogusId' => 'cat-9',
				'title' => 'From self',
				'publications' => 'https://peer.example.org/api/publications',
				'catalogDirectory' => 'https://peer.example.org/api/directory',
				'directory' => 'https://peer.example.org/api/directory',
				'lastSync' => 'NOW',
				'lastSuccessAt' => 'NOW',
				'lastError' => '',
				'schemaCount' => 2,
				'default' => false,
				'statusCode' => 200,
				'status' => 'development',
				'integrationLevel' => 'search',
			],
			$object
		);
	}

	/**
	 * oc-f5: a peer that sends the UUID of another directory's listing gets a new
	 * listing under a fresh UUID; the other listing is not overwritten.
	 */
	public function testCreateNeverReusesTheUuidOfAnExistingListing(): void {
		$this->objectService->method('searchObjects')->willReturn([]);
		$this->objectService->expects($this->once())
			->method('find')
			->with('victim-uuid')
			->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());

		$result = $this->service->syncListing(
			['id' => 'victim-uuid', 'title' => 'Hijack', 'directory' => 'https://source.example.org/api/directory', 'catalogusId' => 'cat-x'],
			'https://source.example.org/api/directory'
		);

		$this->assertSame('created', $result['action']);
		$this->assertCount(1, $this->saved);
		// dir-f2: OpenRegister falls back to the object's `id` when no uuid is passed,
		// so the taken UUID must be absent from both the uuid and the object.
		$this->assertNotSame('victim-uuid', $this->saved[0]['uuid']);
		$this->assertTrue(\Symfony\Component\Uid\Uuid::isValid((string)$this->saved[0]['uuid']));
		$this->assertSame($this->saved[0]['uuid'], $this->saved[0]['object']['id']);
		$this->assertArrayNotHasKey('@self', $this->saved[0]['object']);
	}

	/**
	 * oc-f5: when the UUID cannot be checked, it is not reused (fail closed).
	 */
	public function testCreateDoesNotReuseAUuidThatCannotBeChecked(): void {
		$this->objectService->method('searchObjects')->willReturn([]);
		$this->objectService->method('find')->willThrowException(new \RuntimeException('db down'));

		$this->service->syncListing(
			['id' => 'some-uuid', 'title' => 'T', 'directory' => 'https://source.example.org/api/directory'],
			'https://source.example.org/api/directory'
		);

		$this->assertNotSame('some-uuid', $this->saved[0]['uuid']);
		$this->assertSame($this->saved[0]['uuid'], $this->saved[0]['object']['id']);
	}

	public function testCreateWithoutDirectoryFallsBackToTheSourceAndDetectsTheEndpoint(): void {
		$this->objectService->method('searchObjects')->willReturn([]);

		$result = $this->service->syncListing(
			['id' => 'l-2', 'title' => 'T', 'published' => 'not a date', 'summary' => 'S', 'search' => 'https://x.example.org/api/search'],
			'https://source.example.org/api/directory'
		);

		$this->assertSame('created', $result['action']);
		$object = $this->maskNow($this->saved[0]['object'], ['lastSync', 'lastSuccessAt', 'published']);
		$this->assertSame('l-2', $this->saved[0]['uuid']);
		$this->assertSame('https://source.example.org/api/directory', $object['directory']);
		$this->assertSame(0, $object['schemaCount']);
		$this->assertSame('S', $object['summary']);
		$this->assertSame(
			['id', 'title', 'published', 'summary', 'search', 'catalog', 'catalogusId', 'publications', 'directory', 'lastSync', 'lastSuccessAt', 'lastError', 'schemaCount', 'default', 'statusCode', 'status', 'integrationLevel'],
			array_keys($object)
		);
	}

	public function testUpdatePreservesStatusAndSavesUnderTheExistingUuid(): void {
		$this->objectService->method('searchObjects')->willReturn([
			$this->entity(['id' => 'existing-uuid', 'object' => ['default' => true, 'status' => 'production']]),
		]);

		$result = $this->service->syncListing(
			['id' => 'l-3', 'catalog' => 'c-3', 'directory' => 'https://peer.example.org/api/directory'],
			'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory'
		);

		$this->assertSame(['listing_id' => 'l-3', 'listing_title' => 'Unknown', 'action' => 'updated', 'success' => true, 'error' => null], $result);
		$this->assertSame('existing-uuid', $this->saved[0]['uuid']);
		$object = $this->saved[0]['object'];
		$this->assertTrue($object['default']);
		$this->assertSame('production', $object['status']);
		$this->assertSame('search', $object['integrationLevel']);
		$this->assertSame(200, $object['statusCode']);
	}

	public function testUpdateWithOlderIncomingDataIsSkipped(): void {
		$this->objectService->method('searchObjects')->willReturn([
			$this->entity(['id' => 'e', 'object' => ['lastSync' => '2030-01-01T00:00:00+00:00', 'updated' => '2030-01-01T00:00:00+00:00']]),
		]);

		$result = $this->service->syncListing(
			['id' => 'l-4', 'catalog' => 'c-4', 'updated' => '2001-01-01T00:00:00+00:00'],
			'https://source.example.org/api/directory'
		);

		$this->assertSame(
			[
				'listing_id' => 'l-4',
				'listing_title' => 'Unknown',
				'action' => 'skipped_outdated',
				'success' => true,
				'error' => null,
				'reason' => 'Incoming listing data is older than existing data',
			],
			$result
		);
		$this->assertSame([], $this->saved);
	}

	/**
	 * aud-f1: with the cron sync's write context, only the listing lookup and save
	 * are elevated; recording the failure on the stored listing is not.
	 */
	public function testOnlyTheListingLookupAndSaveRunInTheWriteContext(): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			function () {
				$this->searchedElevated[] = $this->elevated;
				return [$this->entity(['id' => 'existing-uuid', 'object' => ['title' => 'Kept']])];
			}
		);
		$this->failFirstSave = true;
		(new \ReflectionProperty(DirectoryService::class, 'listingWriteContext'))->setValue(
			$this->service,
			function (callable $operation) {
				$this->elevated = true;
				try {
					return $operation();
				} finally {
					$this->elevated = false;
				}
			}
		);

		$this->service->syncListing(['id' => 'l-6', 'catalog' => 'c-6'], 'https://source.example.org/api/directory');

		$this->assertSame([true], $this->searchedElevated);
		$this->assertSame([true, false], $this->savedElevated);
	}

	/**
	 * pp-f1: a listing the default directory relays for a directory that is not
	 * crawled this run is updated from the relay, not skipped as "processed separately".
	 */
	public function testRelayedListingOfAnUncrawledDirectoryIsUpdated(): void {
		(new \ReflectionProperty(DirectoryService::class, 'uniqueDirectories'))->setValue(
			$this->service,
			['https://source.example.org/api/directory']
		);
		$this->objectService->method('searchObjects')->willReturn([
			$this->entity(['id' => 'stored-uuid', 'object' => ['title' => 'Old', 'published' => '2020-01-01T00:00:00+00:00']]),
		]);

		$result = $this->service->syncListing(
			['id' => 'l-7', 'catalog' => 'c-7', 'title' => 'New', 'published' => '2024-01-01T00:00:00+00:00', 'directory' => 'https://peer.example.org/api/directory'],
			'https://source.example.org/api/directory'
		);

		$this->assertSame('updated', $result['action']);
		$this->assertSame('stored-uuid', $this->saved[0]['uuid']);
		$this->assertSame('https://peer.example.org/api/directory', $this->saved[0]['object']['directory']);
	}

	/**
	 * pp-f1: a relayed listing that names a directory crawled in this run is left
	 * to that directory's own sync.
	 */
	public function testRelayedListingOfACrawledDirectoryIsSkipped(): void {
		(new \ReflectionProperty(DirectoryService::class, 'uniqueDirectories'))->setValue(
			$this->service,
			['https://source.example.org/api/directory', 'https://peer.example.org/api/directory']
		);

		$result = $this->service->syncListing(
			['id' => 'l-8', 'catalog' => 'c-8', 'directory' => 'https://peer.example.org/api/directory'],
			'https://source.example.org/api/directory'
		);

		$this->assertSame('skipped_other_directory', $result['action']);
		$this->assertSame([], $this->saved);
	}

	public function testFailedSaveRecordsTheErrorOnTheExistingListing(): void {
		$this->objectService->method('searchObjects')->willReturn([
			$this->entity(['id' => 'existing-uuid', 'object' => ['title' => 'Kept', 'lastSuccessAt' => 'earlier']]),
		]);
		$this->failFirstSave = true;

		$result = $this->service->syncListing(['id' => 'l-5', 'catalog' => 'c-5'], 'https://source.example.org/api/directory');

		$this->assertSame(
			['listing_id' => 'l-5', 'listing_title' => 'Unknown', 'action' => 'none', 'success' => false, 'error' => 'save refused'],
			$result
		);
		$this->assertCount(2, $this->saved);
		$this->assertSame('existing-uuid', $this->saved[1]['uuid']);
		$errorObject = $this->maskNow($this->saved[1]['object'], ['lastSync']);
		$this->assertSame('Kept', $errorObject['title']);
		$this->assertSame('earlier', $errorObject['lastSuccessAt']);
		$this->assertSame(500, $errorObject['statusCode']);
		$this->assertSame('save refused', $errorObject['lastError']);
	}

	public function testMissingCatalogFailsWithoutSaving(): void {
		$result = $this->service->syncListing(['title' => 'No id'], 'https://source.example.org/api/directory');

		$this->assertSame(
			['listing_id' => 'unknown', 'listing_title' => 'No id', 'action' => 'none', 'success' => false, 'error' => 'Invalid listing data: missing id'],
			$result
		);
		$this->assertSame([], $this->saved);
	}
}
