<?php

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\WOO536RepairReadRules;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the WOO-536 read-rule backfill repair step.
 *
 * Verifies:
 *   - single-rule shape (pre-fix) is upgraded to two-rule shape
 *   - two-rule shape (already-fixed) is left alone (idempotency)
 *   - admin-customised shapes are left alone
 *   - "authenticated" element is preserved through the upgrade
 *   - graceful skip when OR is not installed or SchemaMapper unavailable
 */
class WOO536RepairReadRulesTest extends TestCase
{
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;
    private WOO536RepairReadRules $step;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->step       = new WOO536RepairReadRules(
            $this->appManager,
            $this->container,
            $this->logger
        );
    }

    public function testGetName(): void
    {
        $this->assertStringContainsString('WOO-536', $this->step->getName());
    }

    public function testSkipsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['files', 'calendar']);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with(
            $this->stringContains('OpenRegister app is not installed')
        );

        $this->step->run($output);
    }

    public function testUpgradesSingleRuleShapeToTwoRule(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        // Fake OR schema with the pre-fix single-rule shape.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]],
                'authenticated',
            ],
        ]);

        // Capture the setAuthorization call to verify the upgraded shape.
        $captured = null;
        $fakeSchema->expects($this->once())
            ->method('setAuthorization')
            ->willReturnCallback(function ($auth) use (&$captured) {
                $captured = $auth;
            });

        // Return the schema only for the publication slug lookup; return empty
        // for the document slug so setAuthorization is called exactly once.
        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturnCallback(
            fn (array $filters = []) => ($filters['slug'] ?? null) === 'publication' ? [$fakeSchema] : []
        );
        $fakeMapper->expects($this->atLeastOnce())->method('update')->with($fakeSchema);

        $this->container->method('get')->willReturn($fakeMapper);

        $output = $this->createMock(IOutput::class);
        $this->step->run($output);

        $this->assertIsArray($captured, 'setAuthorization should be called with the upgraded shape');
        $this->assertArrayHasKey('read', $captured);
        $read = $captured['read'];

        $this->assertCount(3, $read, 'Upgraded read should have 3 elements: 2 conditional rules + authenticated');
        $this->assertSame(['$lte' => '$now'], $read[0]['match']['publicatiedatum']);
        $this->assertSame(['$gte' => '$now'], $read[0]['match']['depublicatiedatum']);
        $this->assertSame(['$exists' => false], $read[1]['match']['depublicatiedatum']);
        $this->assertSame('authenticated', $read[2], 'authenticated element must be preserved');
    }

    public function testIsIdempotentOnTwoRuleShape(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        // Already on two-rule shape.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => [
                    'publicatiedatum'   => ['$lte' => '$now'],
                    'depublicatiedatum' => ['$gte' => '$now'],
                ]],
                ['group' => 'public', 'match' => [
                    'publicatiedatum'   => ['$lte' => '$now'],
                    'depublicatiedatum' => ['$exists' => false],
                ]],
                'authenticated',
            ],
        ]);

        // MUST NOT call setAuthorization or update.
        $fakeSchema->expects($this->never())->method('setAuthorization');

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([$fakeSchema]);
        $fakeMapper->expects($this->never())->method('update');

        $this->container->method('get')->willReturn($fakeMapper);

        $this->step->run($this->createMock(IOutput::class));
    }

    public function testPreservesAdminCustomisedShape(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        // Admin has added an extra field to the match — leave alone.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => [
                    'publicatiedatum' => ['$lte' => '$now'],
                    'status'          => 'approved',
                ]],
                'authenticated',
            ],
        ]);

        $fakeSchema->expects($this->never())->method('setAuthorization');

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([$fakeSchema]);
        $fakeMapper->expects($this->never())->method('update');

        $this->container->method('get')->willReturn($fakeMapper);

        $this->step->run($this->createMock(IOutput::class));
    }

    public function testSkipsSchemaThatDoesNotExist(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([]);
        $fakeMapper->expects($this->never())->method('update');

        $this->container->method('get')->willReturn($fakeMapper);

        $this->step->run($this->createMock(IOutput::class));
    }
}//end class

/**
 * Fake OR SchemaMapper — a plain class we can mock without importing
 * the actual OpenRegister type into the test file (keeps OC tests
 * dependency-light and PHPUnit-friendly).
 */
abstract class FakeSchemaMapper
{
    abstract public function findAll(array $filters = []): array;
    abstract public function update(object $schema): object;
}

/**
 * Fake OR Schema — same reasoning as FakeSchemaMapper.
 */
abstract class FakeSchema
{
    abstract public function getId(): int;
    abstract public function getAuthorization(): ?array;
    abstract public function setAuthorization(?array $authorization): void;
}
