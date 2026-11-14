<?php

/**
 * Unit tests for PortalObjectStore's reads, writes and the public publication read.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The portal's object store over OpenRegister, as the system.
 */
#[CoversClass(PortalObjectStore::class)]
#[UsesClass(OpenRegisterLookup::class)]
#[UsesClass(PublicationQueryService::class)]
final class PortalObjectStoreTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A store over an object service, OpenRegister installed or not.
	 *
	 * @param mixed $service   The object service the container resolves, or a Throwable it throws.
	 * @param bool  $installed Whether OpenRegister is installed.
	 *
	 * @return PortalObjectStore
	 */
	private function store(mixed $service, bool $installed = true): PortalObjectStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->willReturn($service);
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);

		return new PortalObjectStore(
			openRegister: new OpenRegisterLookup($container, $apps),
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			logger: $this->logger
		);
	}//end store()

	/**
	 * A fake object service that records calls and answers from fixed rows.
	 *
	 * @param array<string,mixed> $answers What each method answers.
	 *
	 * @return object
	 */
	private function service(array $answers): object {
		return new class($answers) {
			/**
			 * Calls as [method, named arguments].
			 *
			 * @var list<array{0:string,1:array<string,mixed>}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $answers The answers.
			 */
			public function __construct(private readonly array $answers) {
			}

			/**
			 * Answer one call.
			 *
			 * @param string              $method The method.
			 * @param array<string,mixed> $args   Its arguments.
			 *
			 * @return mixed
			 */
			private function answer(string $method, array $args): mixed {
				$this->calls[] = [$method, $args];
				$answer = ($this->answers[$method] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			/**
			 * Find.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return mixed
			 */
			public function find(mixed ...$args): mixed {
				return $this->answer('find', $args);
			}

			/**
			 * Save.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return mixed
			 */
			public function saveObject(mixed ...$args): mixed {
				return $this->answer('saveObject', $args);
			}

			/**
			 * Delete.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return mixed
			 */
			public function deleteObject(mixed ...$args): mixed {
				return $this->answer('deleteObject', $args);
			}

			/**
			 * Search.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return mixed
			 */
			public function searchObjects(mixed ...$args): mixed {
				return $this->answer('searchObjects', $args);
			}
		};
	}//end service()

	/**
	 * An entity serialising to the data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return \JsonSerializable
	 */
	private function entity(array $data): \JsonSerializable {
		return new class($data) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data The data.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * The data.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * find reads as the system and lifts the @self id; an empty id or a failing read is nothing.
	 *
	 * @return void
	 */
	public function testFindReadsAsTheSystemAndLiftsTheId(): void {
		$service = $this->service(['find' => $this->entity(['title' => 'Lijst', '@self' => ['id' => 'obj-1']])]);
		$store = $this->store($service);

		self::assertSame(['title' => 'Lijst', '@self' => ['id' => 'obj-1'], 'id' => 'obj-1'], $store->find('collection', 'obj-1'));
		self::assertSame(
			['id' => 'obj-1', 'register' => 'publication', 'schema' => 'collection', '_rbac' => false, '_multitenancy' => false],
			$service->calls[0][1]
		);
		self::assertNull($store->find('collection', ''));
		self::assertCount(1, $service->calls);

		$this->logger->expects(self::once())->method('debug')
			->with('OpenCatalogi: portal object not found', ['schema' => 'collection', 'reason' => 'gone']);
		self::assertNull($this->store($this->service(['find' => new RuntimeException('gone')]))->find('collection', 'x'));
	}//end testFindReadsAsTheSystemAndLiftsTheId()

	/**
	 * save drops @self and id from the data, passes the uuid, and refuses an empty answer.
	 *
	 * @return void
	 */
	public function testSaveStripsIdentityAndRefusesAnEmptyAnswer(): void {
		$service = $this->service(['saveObject' => ['uuid' => 'new-1', 'name' => 'Mijn lijst']]);

		$saved = $this->store($service)->save('collection', ['@self' => ['x' => 1], 'id' => 'forged', 'name' => 'Mijn lijst'], 'c-1');

		self::assertSame(['uuid' => 'new-1', 'name' => 'Mijn lijst', 'id' => 'new-1'], $saved);
		self::assertSame(
			['object' => ['name' => 'Mijn lijst'], 'register' => 'publication', 'schema' => 'collection', 'uuid' => 'c-1', '_rbac' => false, '_multitenancy' => false],
			$service->calls[0][1]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister returned nothing for the save');
		$this->store($this->service(['saveObject' => 'not an object']))->save('collection', []);
	}//end testSaveStripsIdentityAndRefusesAnEmptyAnswer()

	/**
	 * delete answers the service's verdict as a boolean.
	 *
	 * @return void
	 */
	public function testDeleteAnswersABoolean(): void {
		$service = $this->service(['deleteObject' => 1]);

		self::assertTrue($this->store($service)->delete('collection', 'c-1'));
		self::assertSame(
			['uuid' => 'c-1', 'register' => 'publication', 'schema' => 'collection', '_rbac' => false, '_multitenancy' => false],
			$service->calls[0][1]
		);
		self::assertFalse($this->store($this->service(['deleteObject' => null]))->delete('collection', 'c-1'));
	}//end testDeleteAnswersABoolean()

	/**
	 * findByOwner searches the owner's rows and drops rows the search wrongly returned.
	 *
	 * @return void
	 */
	public function testFindByOwnerKeepsOnlyTheOwnersRows(): void {
		$service = $this->service(
			['searchObjects' => [['id' => 'a', 'owner' => 'anna'], $this->entity(['id' => 'b', 'owner' => 'bert']), 'junk']]
		);

		self::assertSame([['id' => 'a', 'owner' => 'anna']], $this->store($service)->findByOwner('collection', 'anna'));
		self::assertSame(
			[
				'query' => ['owner' => 'anna', '@self' => ['register' => 'publication', 'schema' => 'collection'], '_limit' => 1000],
				'_rbac' => false,
				'_multitenancy' => false,
			],
			$service->calls[0][1]
		);
		self::assertSame([], $this->store($service)->findByOwner('collection', ''));
	}//end testFindByOwnerKeepsOnlyTheOwnersRows()

	/**
	 * findWhere without filters keeps every row; a non-scalar filter does not narrow.
	 *
	 * @return void
	 */
	public function testFindWhereFilters(): void {
		$rows = [['id' => 'a', 'tags' => ['x']], ['id' => 'b']];

		self::assertSame($rows, $this->store($this->service(['searchObjects' => $rows]))->findWhere('s', [], 5));
		self::assertSame($rows, $this->store($this->service(['searchObjects' => $rows]))->findWhere('s', ['tags' => ['x']], 5));
	}//end testFindWhereFilters()

	/**
	 * publicPublication answers only a publication that is public now, read anonymously.
	 *
	 * @return void
	 */
	public function testPublicPublicationAnswersOnlyAPublicOne(): void {
		$published = ['id' => 'p1', 'publicationDate' => '2020-01-01T00:00:00+00:00'];
		$service = $this->service(['find' => $published]);

		self::assertSame($published, $this->store($service)->publicPublication('p1'));
		self::assertSame(
			['id' => 'p1', 'register' => 'publication', 'schema' => 'publication', '_rbac' => true, '_multitenancy' => false],
			$service->calls[0][1]
		);

		$withdrawn = $published + ['depublicationDate' => '2021-01-01T00:00:00+00:00'];
		self::assertNull($this->store($this->service(['find' => $withdrawn]))->publicPublication('p1'));
		self::assertNull($this->store($this->service(['find' => new RuntimeException('denied')]))->publicPublication('p1'));
		self::assertNull($this->store($this->service([]))->publicPublication(''));
	}//end testPublicPublicationAnswersOnlyAPublicOne()

	/**
	 * Where the service can run as anonymous, the read happens inside it.
	 *
	 * @return void
	 */
	public function testPublicPublicationReadsInsideRunAsAnonymous(): void {
		$service = new class() {
			/**
			 * Whether the read ran inside runAsAnonymous.
			 *
			 * @var bool
			 */
			public bool $anonymous = false;

			/**
			 * Run as anonymous.
			 *
			 * @param callable $read The read.
			 *
			 * @return mixed
			 */
			public function runAsAnonymous(callable $read): mixed {
				$this->anonymous = true;
				$result = $read();
				$this->anonymous = false;
				return $result;
			}

			/**
			 * Find, only answering inside the anonymous run.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(mixed ...$args): ?array {
				if ($this->anonymous === false) {
					return null;
				}

				return ['uuid' => 'p2', 'publicationDate' => '2020-01-01'];
			}
		};

		self::assertSame(['uuid' => 'p2', 'publicationDate' => '2020-01-01', 'id' => 'p2'], $this->store($service)->publicPublication('p2'));
	}//end testPublicPublicationReadsInsideRunAsAnonymous()

	/**
	 * Without OpenRegister, or when it cannot be resolved, every call says so.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryCallSaysSo(): void {
		try {
			$this->store($this->service([]), installed: false)->delete('s', 'x');
			self::fail('A store without OpenRegister answered.');
		} catch (RuntimeException $e) {
			self::assertSame('OpenRegister is not installed', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available: container broken');
		$this->store(new RuntimeException('container broken'))->writePortalMessage(['subject' => 'x']);
	}//end testWithoutOpenRegisterEveryCallSaysSo()
}//end class
