<?php

declare(strict_types=1);

namespace Unit\Cron;

use OCA\OpenCatalogi\Cron\DirectorySync;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class DirectorySyncTest extends TestCase
{
    private DirectorySync $job;
    private DirectoryService $directoryService;
    private ITimeFactory $timeFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->directoryService = $this->createMock(DirectoryService::class);

        $this->job = new DirectorySync(
            $this->timeFactory,
            $this->directoryService
        );
    }

    public function testRunCallsDoCronSync(): void
    {
        $this->directoryService->expects($this->once())
            ->method('doCronSync');

        $method = new \ReflectionMethod(DirectorySync::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, []);
    }

    public function testIntervalIsSetTo1Hour(): void
    {
        $reflection = new \ReflectionClass($this->job);
        $prop = $reflection->getProperty('interval');
        $prop->setAccessible(true);

        $this->assertSame(3600, $prop->getValue($this->job));
    }
}
