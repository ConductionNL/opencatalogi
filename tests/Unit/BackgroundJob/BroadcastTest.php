<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\Broadcast;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\BackgroundJob\Broadcast
 */
class BroadcastTest extends TestCase {
	private Broadcast $job;
	private BroadcastService $broadcastService;
	private LoggerInterface $logger;
	private ITimeFactory $timeFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->broadcastService = $this->createMock(BroadcastService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new Broadcast(
			$this->timeFactory,
			$this->broadcastService,
			$this->logger
		);
	}

	public function testRunCallsBroadcastService(): void {
		$this->broadcastService->expects($this->once())
			->method('broadcast')
			->with(null);

		$this->logger->expects($this->exactly(2))
			->method('info');

		$method = new \ReflectionMethod(Broadcast::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, []);
	}

	public function testRunLogsAndRethrowsOnException(): void {
		$exception = new \RuntimeException('Broadcast failed');

		$this->broadcastService->method('broadcast')
			->willThrowException($exception);

		$this->logger->expects($this->once())
			->method('info');

		$this->logger->expects($this->once())
			->method('error');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Broadcast failed');

		$method = new \ReflectionMethod(Broadcast::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, []);
	}

	public function testIntervalIsSetTo4Hours(): void {
		$reflection = new \ReflectionClass($this->job);
		$prop = $reflection->getProperty('interval');
		$prop->setAccessible(true);

		$this->assertSame(14400, $prop->getValue($this->job));
	}

	/**
	 * DIR-007 requires the 4-hourly directory broadcast, and a TimedJob that is
	 * not listed in info.xml's background-jobs block silently never runs: the
	 * class shipped for months without this registration and nothing noticed.
	 */
	public function testJobIsRegisteredInTheAppManifest(): void {
		$manifest = dirname(__DIR__, 3) . '/appinfo/info.xml';
		$xml = simplexml_load_file($manifest);
		$this->assertNotFalse($xml, 'appinfo/info.xml must parse');

		$jobs = [];
		foreach ($xml->{'background-jobs'}->job as $job) {
			$jobs[] = (string)$job;
		}

		$this->assertContains(
			'OCA\OpenCatalogi\BackgroundJob\Broadcast',
			$jobs,
			'The Broadcast job must be registered in info.xml or the DIR-007 schedule never fires.'
		);
	}
}
