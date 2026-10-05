<?php

/**
 * Unit tests for CommentPeriodWithdrawalService.
 *
 * The daily pass takes a publication down, which is the hardest action in this
 * change to undo, so the tests are mostly about what it must NOT do: withdraw a
 * period that did not ask, withdraw one twice, or report a pass it could not run
 * as a pass with nothing to do.
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

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A duck-typed fake of the consumed OpenRegister ObjectService.
 */
class PeriodFakeObjectService {

	/** @var array<string, array<string, mixed>> */
	public array $objects = [];

	/** @var array<int, array<string, mixed>> */
	public array $writes = [];

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		return ['results' => array_values($this->objects)];
	}//end searchObjectsPaginated()

	public function saveObject(
		array $object,
		?array $extend = [],
		$register = null,
		$schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$this->writes[] = ['schema' => $schema, 'object' => $object];
		$id = (string)($uuid ?? ($object['id'] ?? 'dep-1'));
		$object['id'] = $id;
		$this->objects[$id] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Unit tests for CommentPeriodWithdrawalService.
 */
class CommentPeriodWithdrawalServiceTest extends TestCase {

	private PeriodFakeObjectService $objects;
	private DepublicationService|MockObject $depublications;
	private CommentPeriodWithdrawalService $service;

	/**
	 * Build the pass over a faked register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new PeriodFakeObjectService();

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'channel_sources') {
					return '{"national-woo-index":"woo-source"}';
				}

				return '42';
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $this->objects
		);

		// onlyMethods against the real class, so a double cannot invent a
		// method the real depublication service lacks.
		$this->depublications = $this->getMockBuilder(DepublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['depublish'])
			->getMock();

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$periods = new CommentPeriodService(
			new TermRoll($this->createMock(ContainerInterface::class), $this->appManager(installed: true)),
			$config,
			$urls
		);

		$this->service = new CommentPeriodWithdrawalService(
			$periods,
			$this->depublications,
			$config,
			$container,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A closed period that asked for a withdrawal gets one, addressed to the
	 * channels this instance has a source for.
	 *
	 * @return void
	 */
	public function testAClosedPeriodThatAskedForItIsWithdrawn(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => true,
		];

		$this->depublications->expects($this->once())
			->method('depublish')
			->with(
				$this->anything(),
				$this->stringContains('comment period'),
				CommentPeriodWithdrawalService::ACTOR,
				['national-woo-index'],
				$this->anything()
			)
			->willReturn(['publication' => 'pub-1', 'reason' => 'x', 'withdrawals' => []]);

		$counts = $this->service->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));

		$this->assertSame(['considered' => 1, 'withdrawn' => 1, 'failed' => 0], $counts);
		$this->assertNotEmpty($this->objects->objects['p-1']['withdrawnAt'] ?? '');

	}//end testAClosedPeriodThatAskedForItIsWithdrawn()

	/**
	 * A period that did not ask is left alone.
	 *
	 * @return void
	 */
	public function testAPeriodThatDidNotAskIsLeftAlone(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => false,
		];

		$this->depublications->expects($this->never())->method('depublish');

		$counts = $this->service->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));

		$this->assertSame(0, $counts['withdrawn']);

	}//end testAPeriodThatDidNotAskIsLeftAlone()

	/**
	 * An already withdrawn period is not withdrawn a second time.
	 *
	 * @return void
	 */
	public function testAWithdrawalHappensOnce(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => true,
			'withdrawnAt' => '2026-04-14T00:00:00+00:00',
		];

		$this->depublications->expects($this->never())->method('depublish');

		$counts = $this->service->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-20T00:00:00+00:00'));

		$this->assertSame(0, $counts['withdrawn']);

	}//end testAWithdrawalHappensOnce()

	/**
	 * An open period is not withdrawn.
	 *
	 * @return void
	 */
	public function testAnOpenPeriodIsNotWithdrawn(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => true,
		];

		$this->depublications->expects($this->never())->method('depublish');

		$counts = $this->service->withdrawClosedPeriods(now: new DateTimeImmutable('2026-03-20T00:00:00+00:00'));

		$this->assertSame(1, $counts['considered']);
		$this->assertSame(0, $counts['withdrawn']);

	}//end testAnOpenPeriodIsNotWithdrawn()

	/**
	 * A pass that could not read the periods is NOT a pass with nothing to do.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterIsNamedNotCountedAsNothingToDo(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('NOT'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $this->objects);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$service = new CommentPeriodWithdrawalService(
			new CommentPeriodService(
				new TermRoll($this->createMock(ContainerInterface::class), $this->appManager(installed: true)),
				$config,
				$urls
			),
			$this->depublications,
			$config,
			$container,
			$logger
		);

		$this->assertSame(
			['considered' => 0, 'withdrawn' => 0, 'failed' => 0],
			$service->withdrawClosedPeriods()
		);

	}//end testAnUnconfiguredRegisterIsNamedNotCountedAsNothingToDo()

	/**
	 * An app manager that answers whether OpenRegister is installed.
	 *
	 * @param bool $installed What it answers.
	 *
	 * @return IAppManager The app manager.
	 */
	private function appManager(bool $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		return $appManager;

	}//end appManager()
	/**
	 * Build the pass with a given object service, config and logger.
	 *
	 * @param object|null     $objects The object service, or null for none bound.
	 * @param string          $sources What the channel_sources key answers.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return CommentPeriodWithdrawalService The pass.
	 */
	private function buildPass(
		?object $objects,
		string $sources,
		LoggerInterface $logger
	): CommentPeriodWithdrawalService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($sources): string {
				if ($key === 'channel_sources') {
					return $sources;
				}

				return '42';
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects) {
				if ($objects === null) {
					throw new \RuntimeException('not bound: ' . $id);
				}

				return $objects;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		return new CommentPeriodWithdrawalService(
			new CommentPeriodService(
				new TermRoll($this->createMock(ContainerInterface::class), $this->appManager(installed: true)),
				$config,
				$urls
			),
			$this->depublications,
			$config,
			$container,
			$logger
		);

	}//end buildPass()

	/**
	 * Without OpenRegister the pass warns and considers nothing, rather than
	 * reporting a clean pass over periods it never read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePassWarnsAndConsidersNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeast(2))->method('warning');

		$pass = $this->buildPass(objects: null, sources: '{}', logger: $logger);

		$this->assertSame(
			['considered' => 0, 'withdrawn' => 0, 'failed' => 0],
			$pass->withdrawClosedPeriods()
		);
		$this->assertNull($pass->getObjectService());

	}//end testWithoutOpenRegisterThePassWarnsAndConsidersNothing()

	/**
	 * Periods that cannot be read are logged as an error and counted as nothing
	 * considered, never as nothing to withdraw.
	 *
	 * @return void
	 */
	public function testPeriodsThatCannotBeReadAreLoggedAsAnError(): void {
		$unreadable = new class {
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new \RuntimeException('the register is down');
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('could not be read'));

		$pass = $this->buildPass(objects: $unreadable, sources: '{}', logger: $logger);

		$this->assertSame(
			['considered' => 0, 'withdrawn' => 0, 'failed' => 0],
			$pass->withdrawClosedPeriods()
		);

	}//end testPeriodsThatCannotBeReadAreLoggedAsAnError()

	/**
	 * A withdrawal that fails is counted as failed and logged, not counted as
	 * withdrawn.
	 *
	 * @return void
	 */
	public function testAFailedWithdrawalIsCountedAsFailed(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => true,
		];
		$this->depublications->method('depublish')->willThrowException(
			new \RuntimeException('the publication is already gone')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('p-1'));

		$pass = $this->buildPass(
			objects: $this->objects,
			sources: '{"national-woo-index":"woo-source"}',
			logger: $logger
		);

		$counts = $pass->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));

		$this->assertSame(['considered' => 1, 'withdrawn' => 0, 'failed' => 1], $counts);

	}//end testAFailedWithdrawalIsCountedAsFailed()

	/**
	 * A channel-sources value that is not a map addresses no channels, rather
	 * than every channel.
	 *
	 * @return void
	 */
	public function testAChannelSourcesValueThatIsNotAMapAddressesNoChannels(): void {
		$this->objects->objects['p-1'] = [
			'id' => 'p-1',
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'automaticWithdrawal' => true,
		];
		$this->depublications->expects($this->once())
			->method('depublish')
			->with($this->anything(), $this->anything(), $this->anything(), [], $this->anything())
			->willReturn(['publication' => 'pub-1', 'reason' => 'x', 'withdrawals' => []]);

		$pass = $this->buildPass(
			objects: $this->objects,
			sources: 'not json at all',
			logger: $this->createMock(LoggerInterface::class)
		);

		$counts = $pass->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));

		$this->assertSame(['considered' => 1, 'withdrawn' => 1, 'failed' => 0], $counts);

	}//end testAChannelSourcesValueThatIsNotAMapAddressesNoChannels()

	/**
	 * A row that wraps its properties under `object` is read, and a row that is
	 * not a row at all is considered and skipped rather than crashing the pass.
	 *
	 * @return void
	 */
	public function testWrappedAndUnreadableRowsAreBothHandled(): void {
		$shaped = new class {
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return [
					'results' => [
						[
							'id' => 'p-1',
							'object' => [
								'publication' => 'pub-1',
								'startDate' => '2026-03-02T00:00:00+00:00',
								'endDate' => '2026-04-13T00:00:00+00:00',
								'automaticWithdrawal' => true,
							],
						],
						// An entity that serialises itself, which is the shape
						// OpenRegister actually returns.
						new class implements \JsonSerializable {
							public function jsonSerialize(): array {
								return [
									'id' => 'p-2',
									'publication' => 'pub-2',
									'startDate' => '2026-03-02T00:00:00+00:00',
									'endDate' => '2026-04-13T00:00:00+00:00',
									'automaticWithdrawal' => false,
								];
							}
						},
						'not a row at all',
					],
				];
			}

			public function saveObject(array $object, mixed ...$rest): array {
				return $object;
			}
		};

		$this->depublications->method('depublish')->willReturn(
			['publication' => 'pub-1', 'reason' => 'x', 'withdrawals' => []]
		);

		$pass = $this->buildPass(
			objects: $shaped,
			sources: '{"national-woo-index":"woo-source"}',
			logger: $this->createMock(LoggerInterface::class)
		);

		$counts = $pass->withdrawClosedPeriods(now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));

		$this->assertSame(3, $counts['considered']);
		$this->assertSame(1, $counts['withdrawn']);
		$this->assertSame(0, $counts['failed']);

	}//end testWrappedAndUnreadableRowsAreBothHandled()
}//end class
