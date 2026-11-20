<?php

/**
 * The trusted read of a publication for signed-in readers (REQ-PSR-003).
 *
 * `PublicationsController::show()` hands a miss that carries a feed consumer
 * key to {@see SignedInReadService::read()}; every decision of the trusted read
 * is made there, so this test drives it with real collaborators except the
 * boundaries: the consumer key check, OpenRegister's ObjectService and its
 * audit trail mapper. The controller half (the header, the 404 and
 * `Cache-Control`) is in PublicationsControllerTest.
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
 * @spec openspec/changes/publications-for-signed-in-readers/tasks.md#2-the-trusted-read
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Service\Publication\SignedInReadService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\SignedInReadService
 * @uses \OCA\OpenCatalogi\Service\PublicationQueryService
 */
final class SignedInReadControllerTest extends TestCase {

	private const KEY = 'oc_c1.k1.secret';

	private const UUID = '5f0e0d3a-8a1b-4c3e-9d2f-1b2c3d4e5f60';

	/**
	 * The consumer the key belongs to.
	 *
	 * @var array<string, mixed>
	 */
	private array $consumer = [
		'id' => 'c1',
		'name' => 'Wijkportaal',
		'catalog' => 'cat-1',
		'account' => 'feed-wijkportaal-abc123',
		'enabled' => true,
		'actForSignedInReaders' => true,
	];

	/**
	 * The stored publication.
	 *
	 * @var array<string, mixed>
	 */
	private array $publication = [];

	/**
	 * The audit entries written: action, context, actor.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $audited = [];

	/**
	 * The find calls: id, register, schema, rbac.
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed, 3: bool}>
	 */
	private array $finds = [];

	/**
	 * Whether writing the audit entry fails.
	 */
	private bool $auditFails = false;

	protected function setUp(): void {
		$this->publication = [
			'title' => 'Wijkplan Noord',
			'audience' => 'signedIn',
			'minimumAssurance' => 'substantial',
			'publicationDate' => gmdate('c', (time() - 86400)),
		];
	}//end setUp()

	/**
	 * The service over the fixtures.
	 *
	 * @return SignedInReadService
	 */
	private function service(): SignedInReadService {
		$consumers = $this->createMock(FeedConsumerServiceInterface::class);
		$consumers->method('authenticate')->willReturnCallback(
			fn (string $key): ?array => $key === self::KEY ? ['consumer' => $this->consumer, 'user' => null] : null
		);

		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				$this->finds[] = [(string)$id, $options?->register, $options?->schema, $_rbac];
				if ((string)$id !== self::UUID || $options?->schema !== 12) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid(self::UUID);
				$entity->setObject($this->publication);
				return $entity;
			}
		);
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objects);

		// OpenRegister's AuditTrailMapper::createAuditTrailEntry(), called with named arguments.
		$test = $this;
		$audit = new class($test) {

			public function __construct(private readonly SignedInReadControllerTest $test) {
			}

			public function createAuditTrailEntry(object $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null, ?string $ipAddress = null): void {
				$this->test->recordAudit(action: $action, context: $context, actorId: $actorId);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\AuditTrailMapper')->willReturn($audit);

		return new SignedInReadService(
			consumers: $consumers,
			settings: $settings,
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Record an audit entry, or fail like a store that is down.
	 *
	 * @param string               $action  The action.
	 * @param array<string, mixed> $context The context.
	 * @param string|null          $actorId The actor.
	 *
	 * @return void
	 */
	public function recordAudit(string $action, array $context, ?string $actorId): void {
		if ($this->auditFails === true) {
			throw new RuntimeException('audit store down');
		}

		$this->audited[] = [$action, $context, $actorId];
	}//end recordAudit()

	/**
	 * Run the read for catalogue cat-1 (register 7, schemas 11 and 12).
	 *
	 * @param string|null $key   The key.
	 * @param string|null $level The level.
	 *
	 * @return object|null
	 */
	private function read(?string $key=self::KEY, ?string $level='substantial'): ?object {
		return $this->service()->read(catalogId: 'cat-1', registers: [7], schemas: [11, 12], id: self::UUID, key: $key, level: $level);
	}//end read()

	public function testAKeyThatMayActForReadersAtTheRightLevelIsServed(): void {
		$object = $this->read(level: 'substantial');

		self::assertInstanceOf(ObjectEntity::class, $object);
		self::assertSame(self::UUID, $object->getUuid());
		self::assertSame([[self::UUID, 7, 11, false], [self::UUID, 7, 12, false]], $this->finds);
	}//end testAKeyThatMayActForReadersAtTheRightLevelIsServed()

	public function testAHigherLevelThanTheMinimumIsServed(): void {
		self::assertNotNull($this->read(level: 'High'));
	}//end testAHigherLevelThanTheMinimumIsServed()

	public function testALowerLevelAnswers404(): void {
		$this->publication['minimumAssurance'] = 'high';

		self::assertNull($this->read(level: 'substantial'));
		self::assertSame([], $this->audited);
	}//end testALowerLevelAnswers404()

	public function testAnUnknownLevelAnswers404(): void {
		self::assertNull($this->read(level: 'low'));
		self::assertNull($this->read(level: null));
		self::assertSame([], $this->finds);
	}//end testAnUnknownLevelAnswers404()

	public function testAKeyWithoutActForSignedInReadersAnswers404(): void {
		unset($this->consumer['actForSignedInReaders']);
		self::assertNull($this->read(level: 'high'));

		$this->consumer['actForSignedInReaders'] = 'true';
		self::assertNull($this->read(level: 'high'));
		self::assertSame([], $this->finds);
	}//end testAKeyWithoutActForSignedInReadersAnswers404()

	public function testNoKeyAnswers404(): void {
		self::assertNull($this->read(key: null));
		self::assertNull($this->read(key: ''));
		self::assertNull($this->read(key: 'oc_c1.k1.wrong'));
		self::assertSame([], $this->finds);
	}//end testNoKeyAnswers404()

	public function testAKeyOfAnotherCatalogueAnswers404(): void {
		$this->consumer['catalog'] = 'cat-2';

		self::assertNull($this->read());
		self::assertSame([], $this->finds);
	}//end testAKeyOfAnotherCatalogueAnswers404()

	public function testAPublicationForEveryoneIsNotServedThroughTheTrustedRead(): void {
		$this->publication['audience'] = 'public';

		self::assertNull($this->read());
	}//end testAPublicationForEveryoneIsNotServedThroughTheTrustedRead()

	public function testAPublicationWhoseWindowIsClosedAnswers404(): void {
		$this->publication['publicationDate'] = gmdate('c', (time() + 86400));
		self::assertNull($this->read());

		$this->publication['publicationDate'] = gmdate('c', (time() - 172800));
		$this->publication['depublicationDate'] = gmdate('c', (time() - 86400));
		self::assertNull($this->read());
		self::assertSame([], $this->audited);
	}//end testAPublicationWhoseWindowIsClosedAnswers404()

	public function testATrustedReadIsAudited(): void {
		$this->read(level: 'high');

		self::assertCount(1, $this->audited);
		[$action, $context, $actor] = $this->audited[0];
		self::assertSame('read.signedInReader', $action);
		self::assertSame('feed-wijkportaal-abc123', $actor);
		self::assertSame('c1', $context['consumer']);
		self::assertSame(self::UUID, $context['publication']);
		self::assertSame('high', $context['level']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $context['at']);
	}//end testATrustedReadIsAudited()

	public function testAReadThatCannotBeAuditedIsNotServed(): void {
		$this->auditFails = true;

		self::assertNull($this->read());
	}//end testAReadThatCannotBeAuditedIsNotServed()
}//end class
