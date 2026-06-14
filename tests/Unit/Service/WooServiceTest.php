<?php
/**
 * Unit tests for WooService.
 *
 * Covers the WOO-specific in-app domain logic: the weigeringsgronden catalogue +
 * search filter, batch creation persisting assessment objects, assessment update
 * with the niet_openbaar grounds requirement and the openbaar grounds-clearing
 * rule, the derived per-status document summary / progress, the ready-for-review
 * gate, the inventarislijst rows + CSV (UTF-8 BOM) + archival HTML, and the
 * publish path that excludes niet_openbaar documents and requires the
 * approval-gated ready_for_review state.
 *
 * The consumed OpenRegister ObjectService + deck leaf are faked via duck-typed
 * doubles so the suite stays offline and deterministic (ADR-022).
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

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\WooService;
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
class WooFakeObjectService
{

    /** @var array<int, array<string, mixed>> */
    public array $objects = [];

    public int $counter = 0;


    public function find(string $id)
    {
        foreach ($this->objects as $object) {
            if ((string) ($object['id'] ?? '') === $id) {
                return $object;
            }
        }

        throw new \RuntimeException('not found');

    }//end find()


    public function saveObject(array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true)
    {
        if (($uuid === null || $uuid === '') && empty($object['id']) === true) {
            $this->counter++;
            $object['id'] = 'obj-'.$this->counter;
        } else if (empty($object['id']) === true) {
            $object['id'] = $uuid;
        }

        // Upsert into the in-memory store.
        foreach ($this->objects as $i => $existing) {
            if ((string) ($existing['id'] ?? '') === (string) $object['id']) {
                $this->objects[$i] = $object;
                return $object;
            }
        }

        $this->objects[] = $object;
        return $object;

    }//end saveObject()
}//end class

/**
 * Duck-typed fake of the consumed OpenRegister DeckCardService (deck leaf).
 */
class WooFakeDeckService
{

    public bool $available = true;

    /** @var array<int, array<string, mixed>> */
    public array $links = [];


    public function isDeckAvailable(): bool
    {
        return $this->available;

    }//end isDeckAvailable()


    public function linkOrCreateCard(string $objectUuid, int $registerId, array $data)
    {
        $link = [
            'objectUuid' => $objectUuid,
            'boardId'    => ($data['boardId'] ?? 0),
            'stackId'    => ($data['stackId'] ?? 0),
            'title'      => ($data['title'] ?? ''),
        ];
        $this->links[] = $link;
        return new class($link) {
            /** @param array<string,mixed> $link */
            public function __construct(private array $link)
            {
            }
            /** @return array<string,mixed> */
            public function jsonSerialize(): array
            {
                return $this->link;
            }
        };

    }//end linkOrCreateCard()


    public function getCardsForObject(string $objectUuid): array
    {
        return ['results' => [], 'total' => 0];

    }//end getCardsForObject()
}//end class

/**
 * @covers \OCA\OpenCatalogi\Service\WooService
 */
class WooServiceTest extends TestCase
{

    private IAppConfig|MockObject $config;

    private ContainerInterface|MockObject $container;

    private IUserSession|MockObject $userSession;

    private LoggerInterface|MockObject $logger;

    private WooFakeObjectService $objects;

    private WooFakeDeckService $deck;

    private WooService $service;


    protected function setUp(): void
    {
        $this->config      = $this->createMock(IAppConfig::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->objects     = new WooFakeObjectService();
        $this->deck        = new WooFakeDeckService();

        $store = [
            'woo_register'          => '1',
            'woo_batch_schema'      => 'batch-sch',
            'woo_assessment_schema' => 'assess-sch',
        ];
        $this->config->method('getValueString')
            ->willReturnCallback(
                fn (string $app, string $key, string $default='') => ($store[$key] ?? $default)
            );

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objects;
                }

                if ($id === 'OCA\OpenRegister\Service\DeckCardService') {
                    return $this->deck;
                }

                throw new \RuntimeException('unknown service '.$id);
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->service = new WooService(
            $this->config,
            $this->container,
            $this->userSession,
            $this->logger,
            $this->createMock(PublicationService::class),
            $this->createMock(CatalogiService::class),
        );

    }//end setUp()


    public function testWeigeringsgrondenCatalogueAndSearch(): void
    {
        $all = $this->service->getWeigeringsgronden();
        $this->assertNotEmpty($all);
        $articles = array_column($all, 'article');
        $this->assertContains('5.1.2.e', $articles);
        $this->assertContains('5.2.e', $articles);

        $filtered  = $this->service->getWeigeringsgronden('persoonlijke');
        $fArticles = array_column($filtered, 'article');
        $this->assertContains('5.1.2.e', $fArticles);
        $this->assertContains('5.2.e', $fArticles);
        $this->assertNotContains('5.1.1.a', $fArticles);

    }//end testWeigeringsgrondenCatalogueAndSearch()


    public function testCreateBatchPersistsAssessmentsAndBatch(): void
    {
        $batch = $this->service->createBatch(
            'WOO-2026-001',
            [
                ['fileName' => 'a.pdf', 'fileType' => 'application/pdf', 'documentReference' => 'doc-a'],
                ['fileName' => 'b.pdf', 'fileType' => 'application/pdf', 'documentReference' => 'doc-b'],
            ],
            42
        );

        $this->assertSame('in_progress', $batch['status']);
        $this->assertCount(2, $batch['assessments']);
        $this->assertSame('te_beoordelen', $batch['assessments'][0]['assessment']);
        // Deck cards were linked via the leaf.
        $this->assertCount(2, $this->deck->links);

    }//end testCreateBatchPersistsAssessmentsAndBatch()


    public function testCreateBatchDeckUnavailableSurfacesWarning(): void
    {
        $this->deck->available = false;
        $batch = $this->service->createBatch('WOO-2026-002', [['fileName' => 'a.pdf']], 7);
        $this->assertSame('Deck integration required for the WOO queue', $batch['deckWarning']);
        $this->assertCount(0, $this->deck->links);

    }//end testCreateBatchDeckUnavailableSurfacesWarning()


    public function testUpdateAssessmentRequiresGroundsForNietOpenbaar(): void
    {
        $this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen', 'weigeringsgronden' => []];

        $this->expectException(\RuntimeException::class);
        $this->service->updateAssessment('a1', 'niet_openbaar', []);

    }//end testUpdateAssessmentRequiresGroundsForNietOpenbaar()


    public function testUpdateAssessmentRejectsUnknownGround(): void
    {
        $this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen'];

        $this->expectException(\RuntimeException::class);
        $this->service->updateAssessment('a1', 'niet_openbaar', ['9.9.9']);

    }//end testUpdateAssessmentRejectsUnknownGround()


    public function testUpdateAssessmentNietOpenbaarStoresGrounds(): void
    {
        $this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen'];
        $result = $this->service->updateAssessment('a1', 'niet_openbaar', ['5.1.2.e', '5.2.e']);
        $this->assertSame('niet_openbaar', $result['assessment']);
        $this->assertSame(['5.1.2.e', '5.2.e'], $result['weigeringsgronden']);
        $this->assertSame('alice', $result['assessedBy']);

    }//end testUpdateAssessmentNietOpenbaarStoresGrounds()


    public function testChangingToOpenbaarClearsGrounds(): void
    {
        $this->objects->objects[] = ['id' => 'a1', 'assessment' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']];
        $result = $this->service->updateAssessment('a1', 'openbaar', []);
        $this->assertSame('openbaar', $result['assessment']);
        $this->assertSame([], $result['weigeringsgronden']);

    }//end testChangingToOpenbaarClearsGrounds()


    public function testGetBatchProducesDocumentSummary(): void
    {
        $this->seedBatchWithAssessments();
        $batch   = $this->service->getBatch('batch-1');
        $summary = $batch['documentSummary'];
        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['assessed']);
        $this->assertSame('3/4', $summary['progressLabel']);
        $this->assertSame(2, $summary['counts']['openbaar']);
        $this->assertSame(1, $summary['counts']['te_beoordelen']);

    }//end testGetBatchProducesDocumentSummary()


    public function testCanMarkReadyForReviewFalseWhenUnassessed(): void
    {
        $this->seedBatchWithAssessments();
        $this->assertFalse($this->service->canMarkReadyForReview('batch-1'));
        $this->expectException(\RuntimeException::class);
        $this->service->markReadyForReview('batch-1');

    }//end testCanMarkReadyForReviewFalseWhenUnassessed()


    public function testMarkReadyForReviewWhenAllAssessed(): void
    {
        $this->seedBatchWithAssessments(false);
        $this->assertTrue($this->service->canMarkReadyForReview('batch-1'));
        $batch = $this->service->markReadyForReview('batch-1');
        $this->assertSame('ready_for_review', $batch['status']);

    }//end testMarkReadyForReviewWhenAllAssessed()


    public function testInventarislijstRowsAndCsv(): void
    {
        $this->seedBatchWithAssessments(false);
        $rows = $this->service->buildInventarislijst('batch-1');
        $this->assertCount(4, $rows);
        $this->assertSame('1', $rows[0]['volgnummer']);

        $csv = $this->service->renderInventarislijstCsv($rows);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Volgnummer', $csv);
        $this->assertStringContainsString('Weigeringsgronden', $csv);

        $html = $this->service->renderInventarislijstHtml('batch-1', $rows);
        $this->assertStringContainsString('Inventarislijst', $html);
        $this->assertStringContainsString('4 documenten', $html);

    }//end testInventarislijstRowsAndCsv()


    public function testPublishRequiresReadyForReview(): void
    {
        $this->seedBatchWithAssessments(false);
        // Still in_progress → publish must reject.
        $this->expectException(\RuntimeException::class);
        $this->service->publishBatch('batch-1');

    }//end testPublishRequiresReadyForReview()


    public function testPublishExcludesNietOpenbaar(): void
    {
        $this->seedBatchWithAssessments(false);
        $this->service->markReadyForReview('batch-1');
        $result = $this->service->publishBatch('batch-1');
        $this->assertSame('published', $result['status']);
        // 2 openbaar + 1 deels_openbaar published; 1 niet_openbaar excluded.
        $this->assertSame(3, $result['wooPublication']['publishedCount']);
        $this->assertSame(4, $result['wooPublication']['documentCount']);
        $this->assertCount(3, $result['wooPublication']['listings']);
        $this->assertSame('woo_reading_room', $result['wooPublication']['catalogType']);
        $this->assertNotEmpty($result['wooPublication']['readingRoomUrl']);

    }//end testPublishExcludesNietOpenbaar()


    /**
     * Seed a batch + 4 assessments. By default one stays "te_beoordelen"; when
     * $leaveUnassessed is false all four are assessed (2 openbaar, 1 deels, 1 niet).
     *
     * @param bool $leaveUnassessed Whether to leave one document unassessed.
     *
     * @return void
     */
    private function seedBatchWithAssessments(bool $leaveUnassessed=true): void
    {
        $fourth = ($leaveUnassessed === true
            ? ['id' => 'a4', 'assessment' => 'te_beoordelen', 'fileName' => 'd.pdf']
            : ['id' => 'a4', 'assessment' => 'niet_openbaar', 'fileName' => 'd.pdf', 'weigeringsgronden' => ['5.1.2.e']]);

        $this->objects->objects = [
            ['id' => 'a1', 'assessment' => 'openbaar', 'fileName' => 'a.pdf', 'documentReference' => 'doc-a'],
            ['id' => 'a2', 'assessment' => 'openbaar', 'fileName' => 'b.pdf', 'documentReference' => 'doc-b'],
            ['id' => 'a3', 'assessment' => 'deels_openbaar', 'fileName' => 'c.pdf', 'anonymizedDocument' => 'doc-c-anon', 'weigeringsgronden' => ['5.2.e']],
            $fourth,
            [
                'id'        => 'batch-1',
                'status'    => 'in_progress',
                'caseReference' => 'WOO-2026-009',
                'documents' => ['a1', 'a2', 'a3', 'a4'],
            ],
        ];

    }//end seedBatchWithAssessments()
}//end class
