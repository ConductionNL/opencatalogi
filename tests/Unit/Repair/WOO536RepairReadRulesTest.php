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
        $this->appManager->method('isEnabledForAnyone')->willReturn(false);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with(
            $this->stringContains('OpenRegister app is not enabled')
        );

        $this->step->run($output);
    }

    public function testUpgradesSingleRuleShapeToTwoRule(): void
    {
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        // Fake OR schema with the pre-fix single-rule shape.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]],
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

        $fakeSchema->method('getSlug')->willReturn('publication');
        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([$fakeSchema]);
        $fakeMapper->expects($this->atLeastOnce())->method('update')->with($fakeSchema);

        $this->container->method('get')->willReturn($fakeMapper);

        $output = $this->createMock(IOutput::class);
        $this->step->run($output);

        $this->assertIsArray($captured, 'setAuthorization should be called with the upgraded shape');
        $this->assertArrayHasKey('read', $captured);
        $read = $captured['read'];

        $this->assertCount(3, $read, 'Upgraded read should have 3 elements: 2 conditional rules + authenticated');
        $this->assertSame(['$lte' => '$now'], $read[0]['match']['publicationDate']);
        $this->assertSame(['$gte' => '$now'], $read[0]['match']['depublicationDate']);
        $this->assertSame(['$exists' => false], $read[1]['match']['depublicationDate']);
        $this->assertSame('authenticated', $read[2], 'authenticated element must be preserved');
    }

    public function testIsIdempotentOnTwoRuleShape(): void
    {
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        // Already on two-rule shape.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getSlug')->willReturn('publication');
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => [
                    'publicationDate'   => ['$lte' => '$now'],
                    'depublicationDate' => ['$gte' => '$now'],
                ]],
                ['group' => 'public', 'match' => [
                    'publicationDate'   => ['$lte' => '$now'],
                    'depublicationDate' => ['$exists' => false],
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
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        // Admin has added an extra field to the match — leave alone.
        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(42);
        $fakeSchema->method('getSlug')->willReturn('publication');
        $fakeSchema->method('getAuthorization')->willReturn([
            'read' => [
                ['group' => 'public', 'match' => [
                    'publicationDate' => ['$lte' => '$now'],
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
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([]);
        $fakeMapper->expects($this->never())->method('update');

        $this->container->method('get')->willReturn($fakeMapper);

        $this->step->run($this->createMock(IOutput::class));
    }

    /**
     * WOO-573: the step walks EVERY schema (one unfiltered findAll) so Dutch keys
     * on customer-created schemas are translated too, while the two-rule
     * upgrade stays limited to the bundled publication/document slugs.
     *
     * @return void
     */
    public function testWalksEverySchemaAndTranslatesCustomerSchemaWithoutTwoRuleUpgrade(): void
    {
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        $customer = $this->createMock(FakeSchema::class);
        $customer->method('getId')->willReturn(9);
        $customer->method('getSlug')->willReturn('besluit');
        $customer->method('getAuthorization')->willReturn([
            'read' => [['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]], 'authenticated'],
        ]);
        $capturedCustomer = null;
        $customer->expects($this->once())->method('setAuthorization')->willReturnCallback(function ($auth) use (&$capturedCustomer) {
            $capturedCustomer = $auth;
        });

        $english = $this->createMock(FakeSchema::class);
        $english->method('getId')->willReturn(10);
        $english->method('getSlug')->willReturn('organization');
        $english->method('getAuthorization')->willReturn(['read' => ['public']]);
        $english->expects($this->never())->method('setAuthorization');

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->expects($this->once())->method('findAll')->willReturn([$customer, $english]);
        $fakeMapper->expects($this->once())->method('update')->with($customer);
        $this->container->method('get')->willReturn($fakeMapper);

        $this->step->run($this->createMock(IOutput::class));

        $this->assertSame(
            [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], 'authenticated'],
            $capturedCustomer['read'],
            'customer schema: keys translated, single-rule shape kept (no two-rule template outside publication/document)'
        );
    }

    /**
     * WOO-573: dropping a Dutch duplicate in favour of an existing English key
     * must be visible to the operator.
     */
    public function testDroppedDutchDuplicateIsReportedAsWarning(): void
    {
        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with(
            $this->stringContains("Dutch key dropped at read[0].publicatiedatum")
        );
        $this->runStepOnDocumentRead(
            read: [
                ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '2020-01-01'], 'publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]]],
                ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']]],
            ],
            output: $output
        );
    }

    /**
     * WOO-573: a legacy 1.x `document` schema still carries the stock 1.0.9
     * single Dutch rule after RenameDutchPublicationColumns renamed the
     * columns. It must come out English AND two-rule, with "authenticated"
     * preserved — otherwise anonymous /api/search is a 500 on 2.x.
     */
    public function testTranslatesDutchSingleRuleToEnglishTwoRule(): void
    {
        $captured = $this->runStepOnDocumentRead([
            ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]],
            'authenticated',
        ]);

        $read = $captured['read'];
        $this->assertCount(3, $read);
        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']], $read[0]['match']);
        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]], $read[1]['match']);
        $this->assertSame('authenticated', $read[2]);
        $this->assertStringNotContainsString('publicatiedatum', json_encode($captured));
    }

    /**
     * WOO-573: the WOO-572 hotfix shape (two Dutch rules) must be translated
     * key-for-key without changing the rule structure.
     */
    public function testTranslatesDutchTwoRuleToEnglishTwoRule(): void
    {
        $captured = $this->runStepOnDocumentRead([
            ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now'], 'depublicatiedatum' => ['$gte' => '$now']]],
            ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now'], 'depublicatiedatum' => ['$exists' => false]]],
            'authenticated',
        ]);

        $read = $captured['read'];
        $this->assertCount(3, $read);
        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']], $read[0]['match']);
        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]], $read[1]['match']);
        $this->assertSame('authenticated', $read[2]);
    }

    /**
     * WOO-573: an admin-customised Dutch rule set keeps its structure; only the
     * two known keys are renamed, other keys/rules stay exactly as they were.
     */
    public function testTranslatesDutchKeysButKeepsAdminCustomisedStructure(): void
    {
        $captured = $this->runStepOnDocumentRead([
            ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now'], 'status' => ['$eq' => 'published']]],
            ['group' => 'redactie', 'match' => ['organisatie' => ['$eq' => '$organisation']]],
        ]);

        $read = $captured['read'];
        $this->assertCount(2, $read, 'admin-customised structure must not be replaced by the two-rule template');
        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'status' => ['$eq' => 'published']], $read[0]['match']);
        $this->assertSame(['organisatie' => ['$eq' => '$organisation']], $read[1]['match']);
    }

    /**
     * WOO-573: Dutch keys in the other actions are translated too.
     */
    public function testTranslatesDutchKeysInNonReadActions(): void
    {
        $captured = $this->runStepOnDocumentRead(
            read: [
                ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]]],
                ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']]],
            ],
            extra: ['update' => [['group' => 'redactie', 'match' => ['depublicatiedatum' => ['$exists' => false]]]]]
        );

        $this->assertSame(['depublicationDate' => ['$exists' => false]], $captured['update'][0]['match']);
    }

    /**
     * WOO-573: an existing English key wins over its Dutch duplicate.
     */
    public function testEnglishKeyWinsOverDutchDuplicate(): void
    {
        $captured = $this->runStepOnDocumentRead([
            ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '2020-01-01'], 'publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]]],
            ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']]],
        ]);

        $this->assertSame(['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]], $captured['read'][0]['match']);
    }

    /**
     * Run the step against a single fake `document` schema carrying the given
     * read block (+ optional other actions) and return what setAuthorization
     * received. Fails the test when the step did not write.
     *
     * @param array $read  The read block to put on the fake schema.
     * @param array $extra Additional authorization actions (e.g. update).
     *
     * @return array The authorization array passed to setAuthorization().
     */
    private function runStepOnDocumentRead(array $read, array $extra = [], ?IOutput $output = null): array
    {
        $this->appManager->method('isEnabledForAnyone')->willReturnCallback(
            fn (string $appId) => $appId === 'openregister'
        );

        $fakeSchema = $this->createMock(FakeSchema::class);
        $fakeSchema->method('getId')->willReturn(7);
        $fakeSchema->method('getSlug')->willReturn('document');
        $fakeSchema->method('getAuthorization')->willReturn(array_merge(['read' => $read], $extra));

        $captured = null;
        $fakeSchema->expects($this->once())
            ->method('setAuthorization')
            ->willReturnCallback(function ($auth) use (&$captured) {
                $captured = $auth;
            });

        $fakeMapper = $this->createMock(FakeSchemaMapper::class);
        $fakeMapper->method('findAll')->willReturn([$fakeSchema]);
        $fakeMapper->expects($this->once())->method('update')->with($fakeSchema);

        $this->container->method('get')->willReturn($fakeMapper);
        $this->step->run($output ?? $this->createMock(IOutput::class));

        $this->assertIsArray($captured, 'setAuthorization should have been called');
        return $captured;
    }
}//end class

/**
 * Fake OR SchemaMapper — a plain class we can mock without importing
 * the actual OpenRegister type into the test file (keeps OC tests
 * dependency-light and PHPUnit-friendly).
 */
abstract class FakeSchemaMapper
{
    abstract public function findAll(): array;
    abstract public function update(object $schema): object;
}

/**
 * Fake OR Schema — same reasoning as FakeSchemaMapper.
 */
abstract class FakeSchema
{
    abstract public function getId(): int;
    abstract public function getSlug(): ?string;
    abstract public function getAuthorization(): ?array;
    abstract public function setAuthorization(?array $authorization): void;
}
