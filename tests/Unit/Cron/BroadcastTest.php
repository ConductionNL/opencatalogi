<?php

declare(strict_types=1);

namespace Unit\Cron;

use OCA\OpenCatalogi\Cron\Broadcast;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BroadcastTest extends TestCase
{
    private Broadcast $job;
    private BroadcastService $broadcastService;
    private LoggerInterface $logger;
    private ITimeFactory $timeFactory;

    protected function setUp(): void
    {
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

    public function testRunCallsBroadcastService(): void
    {
        $this->broadcastService->expects($this->once())
            ->method('broadcast')
            ->with(null);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $method = new \ReflectionMethod(Broadcast::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, []);
    }

    public function testRunLogsAndRethrowsOnException(): void
    {
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

    public function testIntervalIsSetTo4Hours(): void
    {
        $reflection = new \ReflectionClass($this->job);
        $prop = $reflection->getProperty('interval');
        $prop->setAccessible(true);

        $this->assertSame(14400, $prop->getValue($this->job));
    }
}
