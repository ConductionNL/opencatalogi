<?php
/**
 * Unit tests for RetentionService.
 *
 * Covers: expiry computation (RET-003), per-catalog default application without
 * overwriting officer choices (RET-004), warning-window configuration and the
 * daily evaluation pass per action (review/depublish/archive) with idempotency
 * (RET-005), human-decision recording with mandatory rationale (RET-007), and
 * the CSV report rendering with UTF-8 BOM (RET-009).
 *
 * The OpenRegister ObjectService is consumed (ADR-022) and faked here via a
 * duck-typed double, so the suite stays offline and deterministic.
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

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duck-typed fake of the consumed OpenRegister ObjectService.
 */
class FakeObjectService
{

    /** @var array<int, array<string, mixed>> */
    public array $objects = [];

    /** @var array<int, array<string, mixed>> */
    public array $saved = [];


    public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array
    {
        return ['results' => $this->objects, 'facets' => []];

    }//end searchObjectsPaginated()


    public function find(string $id)
    {
        foreach ($this->objects as $object) {
            if ((string) ($object['id'] ?? '') === $id) {
                return $object;
            }
        }

        return ['id' => $id];

    }//end find()


    public function saveObject(array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true)
    {
        $this->saved[] = $object;
        return $object;

    }//end saveObject()
}//end class

/**
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 */
class RetentionServiceTest extends TestCase
{

    private IAppConfig|MockObject $config;

    private ContainerInterface|MockObject $container;

    private IUserSession|MockObject $userSession;

    private LoggerInterface|MockObject $logger;

    private FakeObjectService $fake;

    private RetentionService $service;

    /** @var array<string, string> */
    private array $store = [];


    protected function setUp(): void
    {
        $this->config      = $this->createMock(IAppConfig::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->fake        = new FakeObjectService();

        $this->store = [
            'publication_register' => 'pub-reg',
            'publication_schema'   => 'pub-sch',
        ];

        $this->config->method('getValueString')
            ->willReturnCallback(
                fn (string $app, string $key, string $default='') => ($this->store[$key] ?? $default)
            );
        $this->config->method('setValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $value): void {
                    $this->store[$key] = $value;
                }
            );

        $this->container->method('get')->willReturn($this->fake);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('officer1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->service = new RetentionService(
            $this->config,
            $this->container,
            $this->userSession,
            $this->logger
        );

    }//end setUp()


    public function testComputeExpiryAddsTerm(): void
    {
        $this->assertSame(
            '2027-06-11',
            substr((string) $this->service->computeExpiry('2026-06-11T00:00:00+00:00', 12), 0, 10)
        );
        $this->assertNull($this->service->computeExpiry(null, 12));
        $this->assertNull($this->service->computeExpiry('2026-06-11', 0));

    }//end testComputeExpiryAddsTerm()


    public function testWarningWindowDefaultsTo30(): void
    {
        $this->assertSame(30, $this->service->getWarningWindowDays());

    }//end testWarningWindowDefaultsTo30()


    public function testApplyDefaultsFillsEmptyButNeverOverwrites(): void
    {
        $this->store['retention_defaults'] = json_encode(
            [
                'vergunningen' => [
                    'vergunningen' => ['termMonths' => 12, 'action' => 'depublish'],
                ],
            ]
        );

        // Empty retention fields -> default applied + expiry computed.
        $applied = $this->service->applyDefaults(
            ['publicatiedatum' => '2026-06-11T00:00:00+00:00', 'retentionCategory' => 'vergunningen'],
            'vergunningen'
        );
        $this->assertSame(12, $applied['retentionTermMonths']);
        $this->assertSame('depublish', $applied['retentionAction']);
        $this->assertSame('2027-06-11', substr((string) $applied['retentionExpiresAt'], 0, 10));

        // Officer's value preserved.
        $kept = $this->service->applyDefaults(
            ['publicatiedatum' => '2026-06-11T00:00:00+00:00', 'retentionCategory' => 'vergunningen', 'retentionTermMonths' => 60],
            'vergunningen'
        );
        $this->assertSame(60, $kept['retentionTermMonths']);

    }//end testApplyDefaultsFillsEmptyButNeverOverwrites()


    public function testSetDefaultsRejectsInvalidAction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->setRetentionDefaults(['cat' => ['x' => ['action' => 'nope']]]);

    }//end testSetDefaultsRejectsInvalidAction()


    public function testEvaluateDepublishesExpiredAndRecordsDecision(): void
    {
        $this->fake->objects = [
            [
                'id'                 => 'p1',
                'title'              => 'Expired permit',
                'publicatiedatum'    => '2025-01-01T00:00:00+00:00',
                'retentionExpiresAt' => '2025-06-01T00:00:00+00:00',
                'retentionAction'    => 'depublish',
            ],
        ];

        $counts = $this->service->evaluate();

        $this->assertSame(1, $counts['depublished']);
        $this->assertCount(1, $this->fake->saved);
        $saved = $this->fake->saved[0];
        $this->assertArrayHasKey('depublicatiedatum', $saved);
        $this->assertArrayHasKey('retentionDecisionLog', $saved);
        $this->assertSame('auto-depublish', $saved['retentionDecisionLog'][0]['decision']);
        $this->assertSame('officer1', $saved['retentionDecisionLog'][0]['by']);

    }//end testEvaluateDepublishesExpiredAndRecordsDecision()


    public function testEvaluateArchivesExpiredViaLifecycle(): void
    {
        $this->fake->objects = [
            [
                'id'                 => 'p2',
                'publicatiedatum'    => '2024-01-01T00:00:00+00:00',
                'retentionExpiresAt' => '2025-01-01T00:00:00+00:00',
                'retentionAction'    => 'archive',
            ],
        ];

        $counts = $this->service->evaluate();
        $this->assertSame(1, $counts['archived']);
        $this->assertSame('archived', $this->fake->saved[0]['status']);
        $this->assertArrayHasKey('depublicatiedatum', $this->fake->saved[0]);

    }//end testEvaluateArchivesExpiredViaLifecycle()


    public function testEvaluateReviewActionNeverChangesVisibility(): void
    {
        $this->fake->objects = [
            [
                'id'                 => 'p3',
                'publicatiedatum'    => '2024-01-01T00:00:00+00:00',
                'retentionExpiresAt' => '2025-01-01T00:00:00+00:00',
                'retentionAction'    => 'review',
            ],
        ];

        $counts = $this->service->evaluate();
        $this->assertSame(1, $counts['reviewRequired']);
        // Only the idempotency stamp is written — no depublish/archive.
        $this->assertArrayNotHasKey('depublicatiedatum', $this->fake->saved[0]);
        $this->assertArrayNotHasKey('status', $this->fake->saved[0]);

    }//end testEvaluateReviewActionNeverChangesVisibility()


    public function testEvaluateIsIdempotentForToday(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->fake->objects = [
            [
                'id'                       => 'p4',
                'publicatiedatum'          => '2024-01-01T00:00:00+00:00',
                'retentionExpiresAt'       => '2025-01-01T00:00:00+00:00',
                'retentionAction'          => 'depublish',
                'retentionLastEvaluatedAt' => $today,
            ],
        ];

        $counts = $this->service->evaluate();
        $this->assertSame(0, $counts['depublished']);
        $this->assertCount(0, $this->fake->saved);

    }//end testEvaluateIsIdempotentForToday()


    public function testRecordHumanDecisionRequiresRationale(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->recordHumanDecision('p1', 'extend', '   ', 12);

    }//end testRecordHumanDecisionRequiresRationale()


    public function testRecordHumanDecisionExtendMovesExpiry(): void
    {
        $this->fake->objects = [
            ['id' => 'p5', 'retentionExpiresAt' => '2026-01-01T00:00:00+00:00'],
        ];

        $result = $this->service->recordHumanDecision('p5', 'extend', 'legal hold', 12);
        $this->assertSame('2027-01-01', substr((string) $result['retentionExpiresAt'], 0, 10));
        $this->assertSame('legal hold', $result['retentionNote']);
        $this->assertSame('extend', $result['retentionDecisionLog'][0]['decision']);

    }//end testRecordHumanDecisionExtendMovesExpiry()


    public function testRenderReportCsvHasBomAndHeaders(): void
    {
        $csv = $this->service->renderReportCsv(
            [
                ['id' => 'p1', 'title' => 'A', 'status' => 'archived', 'actionTaken' => 'auto-archive'],
            ]
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('retentionExpiresAt', $csv);
        $this->assertStringContainsString('auto-archive', $csv);

    }//end testRenderReportCsvHasBomAndHeaders()
}//end class
