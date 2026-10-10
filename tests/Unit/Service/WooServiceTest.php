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

use OCA\OpenCatalogi\Service\WooService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duck-typed fake of the consumed OpenRegister ObjectService.
 */
class WooFakeObjectService {

	/** @var array<int, array<string, mixed>> */
	public array $objects = [];

	public int $counter = 0;

	public function find(string $id, mixed ...$rest) {
		foreach ($this->objects as $object) {
			if ((string)($object['id'] ?? '') === $id) {
				return $object;
			}
		}

		throw new \RuntimeException('not found');
	}//end find()

	/** @var array<int, array{register: mixed, schema: mixed}> */
	public array $saveTargets = [];

	public function saveObject(array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) {
		$this->saveTargets[] = ['register' => $register, 'schema' => $schema];
		if (($uuid === null || $uuid === '') && empty($object['id']) === true) {
			$this->counter++;
			$object['id'] = 'obj-' . $this->counter;
		} elseif (empty($object['id']) === true) {
			$object['id'] = $uuid;
		}

		// Upsert into the in-memory store.
		foreach ($this->objects as $i => $existing) {
			if ((string)($existing['id'] ?? '') === (string)$object['id']) {
				$this->objects[$i] = $object;
				return $object;
			}
		}

		$this->objects[] = $object;
		return $object;
	}//end saveObject()
}//end class

/**
 * Duck-typed fake of the consumed OpenRegister FileService.
 */
class WooFakeFileService {

	/** @var array<int, array{target: mixed, name: string, content: string}> */
	public array $added = [];

	/** @var array<int, int> */
	public array $published = [];

	public ?string $failOn = null;

	public function addFile(mixed $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], mixed $_schema = null, mixed $_register = null, mixed $registerId = null): object {
		if ($this->failOn === $fileName) {
			throw new \RuntimeException('disk full');
		}

		$this->added[] = ['target' => $objectEntity, 'name' => $fileName, 'content' => (string)stream_get_contents($content)];
		$id = (100 + count($this->added));
		return new class($id) {
			public function __construct(private int $id) {
			}
			public function getId(): int {
				return $this->id;
			}
		};
	}//end addFile()

	public function publishFile(mixed $object, string|int $file): object {
		$this->published[] = (int)$file;
		return new \stdClass();
	}//end publishFile()

	/** What the redaction returns; null makes it throw, as a broken pipeline does. */
	public ?\OCP\Files\Node $redacted = null;

	public int $redactions = 0;

	/** Mirrors openregister FileService::anonymizeDocument. */
	public function anonymizeDocument(\OCP\Files\Node $node, array $entities, string $scope='document', ?string $fileKey=null, ?bool $preserveStructure=null): \OCP\Files\Node {
		$this->redactions++;
		if ($this->redacted === null) {
			throw new \RuntimeException('Presidio unreachable');
		}

		return $this->redacted;
	}//end anonymizeDocument()

	/** Mirrors openregister FileService::getLastResidualEntities. */
	public function getLastResidualEntities(): array {
		return [];
	}//end getLastResidualEntities()
}//end class

/**
 * Duck-typed fake of the consumed OpenRegister EntityRelationMapper.
 */
class WooFakeEntityRelationMapper {

	/** Mirrors openregister EntityRelationMapper::findEntitiesForAnonymization. */
	public function findEntitiesForAnonymization(int $fileId): array {
		return [['entity_value' => 'Jan Jansen', 'entity_type' => 'PERSON']];
	}//end findEntitiesForAnonymization()
}//end class

/**
 * Duck-typed fake of the consumed OpenRegister DeckCardService (deck leaf).
 */
class WooFakeDeckService {

	public bool $available = true;

	/** @var array<int, array<string, mixed>> */
	public array $links = [];

	public function isDeckAvailable(): bool {
		return $this->available;
	}//end isDeckAvailable()

	public function linkOrCreateCard(string $objectUuid, int $registerId, array $data) {
		$link = [
			'objectUuid' => $objectUuid,
			'boardId' => ($data['boardId'] ?? 0),
			'stackId' => ($data['stackId'] ?? 0),
			'title' => ($data['title'] ?? ''),
		];
		$this->links[] = $link;
		return new class($link) {
			/** @param array<string,mixed> $link */
			public function __construct(
				private array $link,
			) {
			}
			/** @return array<string,mixed> */
			public function jsonSerialize(): array {
				return $this->link;
			}
		};

	}//end linkOrCreateCard()

	public function getCardsForObject(string $objectUuid): array {
		return ['results' => [], 'total' => 0];
	}//end getCardsForObject()
}//end class

/**
 * Duck-typed fake of the consumed OpenRegister TaskSequenceMapper. Parameter
 * names mirror the real mapper because the service calls it with named
 * arguments; a fake with different names would silently diverge.
 */
class WooFakeTaskSequenceMapper {

	/** @var string|null Status the newest sequence reports; null = no sequence recorded. */
	public ?string $status = null;

	/** @var array<int, array{anchor: string, template: string}> */
	public array $lookups = [];

	public ?\Throwable $throwOnLookup = null;

	public function findNewestForAnchor(string $anchorObjectUuid, string $templateId): ?object {
		$this->lookups[] = ['anchor' => $anchorObjectUuid, 'template' => $templateId];
		if ($this->throwOnLookup !== null) {
			throw $this->throwOnLookup;
		}

		if ($this->status === null) {
			return null;
		}

		$status = $this->status;
		return new class($status) {
			public function __construct(
				private string $status,
			) {
			}
			public function getStatus(): string {
				return $this->status;
			}
		};

	}//end findNewestForAnchor()
}//end class

/**
 * @covers \OCA\OpenCatalogi\Service\WooService
 * @covers \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 * @covers \OCA\OpenCatalogi\Service\Woo\DocumentRedactor
 */
class WooServiceTest extends TestCase {

	private IAppConfig|MockObject $config;

	private ContainerInterface|MockObject $container;

	private IUserSession|MockObject $userSession;

	private LoggerInterface|MockObject $logger;

	private IL10N|MockObject $l10n;

	private WooFakeObjectService $objects;

	private WooFakeDeckService $deck;

	private WooFakeTaskSequenceMapper $sequences;

	private WooFakeFileService $files;

	/** @var array<string, \OCP\Files\File> Documents the root folder knows, by reference. */
	private array $documents = [];

	private WooService $service;

	/** @var array<string, string> */
	private array $store = [];

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->objects = new WooFakeObjectService();
		$this->deck = new WooFakeDeckService();
		$this->sequences = new WooFakeTaskSequenceMapper();
		$this->files = new WooFakeFileService();
		$id = 0;
		foreach (['doc-a' => 'a.pdf', 'doc-b' => 'b.pdf', 'doc-c' => 'c.pdf', 'doc-c-anon' => 'c-gelakt.pdf'] as $reference => $name) {
			$id++;
			$this->documents['/alice/files/'.$reference] = $this->document($name, $id);
		}

		$this->store = [
			'woo_register' => '1',
			'woo_batch_schema' => 'batch-sch',
			'woo_assessment_schema' => 'assess-sch',
			'woo_publish_approval_chain' => 'chain-1',
		];
		$this->config->method('getValueString')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
			);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objects;
				}

				if ($id === 'OCA\OpenRegister\Service\DeckCardService') {
					return $this->deck;
				}

				if ($id === 'OCA\OpenRegister\Db\TaskSequenceMapper') {
					return $this->sequences;
				}

				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $this->files;
				}

				if ($id === 'OCA\OpenRegister\Db\EntityRelationMapper') {
					return new WooFakeEntityRelationMapper();
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$this->l10n->method('t')
			->willReturnCallback(
				static function (string $text, mixed $parameters = []) {
					if (is_array($parameters) === false) {
						$parameters = [$parameters];
					}

					return vsprintf($text, $parameters);
				}
			);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(\OCP\Files\Folder::class);
		$userFolder->method('get')->willReturnCallback(
			fn (string $path) => ($this->documents['/alice/files/'.$path] ?? throw new \OCP\Files\NotFoundException($path))
		);
		$root = $this->createMock(\OCP\Files\IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$root->method('get')->willReturnCallback(
			fn (string $path) => ($this->documents[$path] ?? throw new \OCP\Files\NotFoundException($path))
		);
		$root->method('getFirstNodeById')->willReturnCallback(
			fn (int $id) => ($this->documents['id:'.$id] ?? null)
		);
		$linker = $this->createMock(\OCA\OpenCatalogi\Service\Portal\PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/'.$id);

		$writer = new \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter($root, $this->container, $linker, $this->l10n);
		$this->service = new WooService(
			$this->config,
			$this->container,
			$this->userSession,
			$this->logger,
			$this->l10n,
			$writer,
			new \OCA\OpenCatalogi\Service\Woo\DocumentRedactor($writer, $this->container, $this->l10n, $this->logger),
		);

	}//end setUp()

	public function testWeigeringsgrondenCatalogueAndSearch(): void {
		$all = $this->service->getWeigeringsgronden();
		$this->assertNotEmpty($all);
		$articles = array_column($all, 'article');
		$this->assertContains('5.1.2.e', $articles);
		$this->assertContains('5.2.e', $articles);

		$filtered = $this->service->getWeigeringsgronden('persoonlijke');
		$fArticles = array_column($filtered, 'article');
		$this->assertContains('5.1.2.e', $fArticles);
		$this->assertContains('5.2.e', $fArticles);
		$this->assertNotContains('5.1.1.a', $fArticles);

	}//end testWeigeringsgrondenCatalogueAndSearch()

	public function testCreateBatchPersistsAssessmentsAndBatch(): void {
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

	public function testCreateBatchDeckUnavailableSurfacesWarning(): void {
		$this->deck->available = false;
		$batch = $this->service->createBatch('WOO-2026-002', [['fileName' => 'a.pdf']], 7);
		$this->assertSame('Deck integration required for the WOO queue', $batch['deckWarning']);
		$this->assertCount(0, $this->deck->links);

	}//end testCreateBatchDeckUnavailableSurfacesWarning()

	public function testUpdateAssessmentRequiresGroundsForNietOpenbaar(): void {
		$this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen', 'weigeringsgronden' => []];

		$this->expectException(\RuntimeException::class);
		$this->service->updateAssessment('a1', 'niet_openbaar', []);

	}//end testUpdateAssessmentRequiresGroundsForNietOpenbaar()

	public function testUpdateAssessmentRejectsUnknownGround(): void {
		$this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen'];

		$this->expectException(\RuntimeException::class);
		$this->service->updateAssessment('a1', 'niet_openbaar', ['9.9.9']);

	}//end testUpdateAssessmentRejectsUnknownGround()

	public function testUpdateAssessmentNietOpenbaarStoresGrounds(): void {
		$this->objects->objects[] = ['id' => 'a1', 'assessment' => 'te_beoordelen'];
		$result = $this->service->updateAssessment('a1', 'niet_openbaar', ['5.1.2.e', '5.2.e']);
		$this->assertSame('niet_openbaar', $result['assessment']);
		$this->assertSame(['5.1.2.e', '5.2.e'], $result['weigeringsgronden']);
		$this->assertSame('alice', $result['assessedBy']);

	}//end testUpdateAssessmentNietOpenbaarStoresGrounds()

	public function testChangingToOpenbaarClearsGrounds(): void {
		$this->objects->objects[] = ['id' => 'a1', 'assessment' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']];
		$result = $this->service->updateAssessment('a1', 'openbaar', []);
		$this->assertSame('openbaar', $result['assessment']);
		$this->assertSame([], $result['weigeringsgronden']);

	}//end testChangingToOpenbaarClearsGrounds()

	public function testGetBatchProducesDocumentSummary(): void {
		$this->seedBatchWithAssessments();
		$batch = $this->service->getBatch('batch-1');
		$summary = $batch['documentSummary'];
		$this->assertSame(4, $summary['total']);
		$this->assertSame(3, $summary['assessed']);
		$this->assertSame('3/4', $summary['progressLabel']);
		$this->assertSame(2, $summary['counts']['openbaar']);
		$this->assertSame(1, $summary['counts']['te_beoordelen']);

	}//end testGetBatchProducesDocumentSummary()

	public function testCanMarkReadyForReviewFalseWhenUnassessed(): void {
		$this->seedBatchWithAssessments();
		$this->assertFalse($this->service->canMarkReadyForReview('batch-1'));
		$this->expectException(\RuntimeException::class);
		$this->service->markReadyForReview('batch-1');

	}//end testCanMarkReadyForReviewFalseWhenUnassessed()

	public function testMarkReadyForReviewWhenAllAssessed(): void {
		$this->seedBatchWithAssessments(false);
		$this->assertTrue($this->service->canMarkReadyForReview('batch-1'));
		$batch = $this->service->markReadyForReview('batch-1');
		$this->assertSame('ready_for_review', $batch['status']);

	}//end testMarkReadyForReviewWhenAllAssessed()

	public function testInventarislijstRowsAndCsv(): void {
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

	public function testPublishRequiresReadyForReview(): void {
		$this->seedBatchWithAssessments(false);
		// Still in_progress → publish must reject.
		$this->expectException(\RuntimeException::class);
		$this->service->publishBatch('batch-1');

	}//end testPublishRequiresReadyForReview()

	public function testPublishRefusesWhenNoApprovalChainConfigured(): void {
		unset($this->store['woo_publish_approval_chain']);
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no approval chain is configured');
		$this->service->publishBatch('batch-1');

	}//end testPublishRefusesWhenNoApprovalChainConfigured()

	public function testPublishRefusesWhenChainHasNoRecordedApproval(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');

		// No sequence recorded for the anchor at all.
		$this->sequences->status = null;

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('has not recorded a completed approval');
		$this->service->publishBatch('batch-1');

	}//end testPublishRefusesWhenChainHasNoRecordedApproval()

	public function testPublishRefusesWhileChainIsStillRunning(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'running';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('chain "chain-1" has not recorded a completed approval');
		$this->service->publishBatch('batch-1');

	}//end testPublishRefusesWhileChainIsStillRunning()

	public function testPublishRefusesWhenChainRejected(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'rejected';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('has not recorded a completed approval');
		$this->service->publishBatch('batch-1');

	}//end testPublishRefusesWhenChainRejected()

	public function testPublishFailsClosedWhenApprovalStoreErrors(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->throwOnLookup = new \RuntimeException('database gone');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('cannot be verified');
		$this->service->publishBatch('batch-1');

	}//end testPublishFailsClosedWhenApprovalStoreErrors()

	public function testPublishEvaluatesTheChainAnchoredOnTheBatch(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'completed';

		$this->service->publishBatch('batch-1');

		$this->assertSame(
			[['anchor' => 'batch-1', 'template' => 'chain-1']],
			$this->sequences->lookups
		);

	}//end testPublishEvaluatesTheChainAnchoredOnTheBatch()

	public function testPublishExcludesNietOpenbaar(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'completed';
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
	 * REQ-WPC-004: a batch published from a Woo request is filed under
	 * infocat014 (Woo-verzoeken en -besluiten), read back from the store.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testPublishFilesTheBatchUnderTheWooRequestCategory(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'completed';
		$this->service->publishBatch('batch-1');

		$stored = $this->service->getBatch('batch-1');
		$this->assertSame('infocat014', $stored['wooPublication']['wooCategory']);

	}//end testPublishFilesTheBatchUnderTheWooRequestCategory()

	/**
	 * A Nextcloud file double with a name and content.
	 *
	 * @param string $name The file name.
	 * @param int    $id   The file id.
	 *
	 * @return \OCP\Files\File
	 */
	private function document(string $name, int $id=0): \OCP\Files\File {
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('hash')->willReturnCallback(static fn (string $type): string => hash($type, 'inhoud van '.$name));
		$file->method('fopen')->willReturnCallback(static function () use ($name) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, 'inhoud van '.$name);
			rewind($stream);
			return $stream;
		});
		return $file;
	}//end document()

	/**
	 * Get a batch ready and approved for publishing.
	 *
	 * @return void
	 */
	private function approvedBatch(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		$this->sequences->status = 'completed';
	}//end approvedBatch()

	/**
	 * The publications the batch publish created.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function createdPublications(): array {
		return array_values(array_filter($this->objects->objects, static fn (array $o): bool => (($o['publicationKind'] ?? '') === 'actief')));
	}//end createdPublications()

	/**
	 * REQ-WBP-001: a published batch is a public publication with its documents attached.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function testPublishingCreatesAnActivePublicationWithTheDisclosableDocumentsAttached(): void {
		$this->approvedBatch();
		$result = $this->service->publishBatch('batch-1');

		$publications = $this->createdPublications();
		$this->assertCount(1, $publications);
		$publication = $publications[0];
		$this->assertSame('infocat014', $publication['wooCategory']);
		$this->assertSame('WOO-2026-009', $publication['caseReference']);
		$this->assertSame('Woo-publicatie WOO-2026-009', $publication['title']);
		$this->assertNotEmpty($publication['publicationDate']);
		$this->assertContains(['register' => 'publication', 'schema' => 'publication'], $this->objects->saveTargets);

		$this->assertSame(['a.pdf', 'b.pdf', 'c-gelakt.pdf'], array_column($this->files->added, 'name'));
		$this->assertSame('inhoud van c-gelakt.pdf', $this->files->added[2]['content']);
		$this->assertSame([101, 102, 103], $this->files->published);

		$this->assertSame($publication['id'], $result['wooPublication']['publication']);
		$this->assertSame('https://example.org/p/'.$publication['id'], $result['wooPublication']['publicationUrl']);
		$this->assertSame('published', $result['status']);

		// The payload passes the real, merged publication schema.
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root.'/lib/Settings/publication_register.json'), true)['components']['schemas']['publication'];
		$fields = json_decode((string)file_get_contents($root.'/lib/Settings/register.d/woo-dossier-publication.json'), true)['components']['schemas']['publication']['properties'];
		$schema = ['type' => 'object', 'properties' => array_merge($base['properties'], $fields), 'required' => $base['required']];
		$payload = $publication;
		unset($payload['id']);
		$this->assertTrue((new \Opis\JsonSchema\Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema))->isValid());
	}//end testPublishingCreatesAnActivePublicationWithTheDisclosableDocumentsAttached()

	/**
	 * REQ-WBP-001: the batch's own category and title win.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function testTheBatchsCategoryAndTitleAreUsed(): void {
		$this->approvedBatch();
		foreach ($this->objects->objects as $i => $object) {
			if ($object['id'] === 'batch-1') {
				$this->objects->objects[$i]['wooCategory'] = 'infocat010';
				$this->objects->objects[$i]['title'] = 'Adviezen windpark';
			}
		}

		$result = $this->service->publishBatch('batch-1');
		$this->assertSame('infocat010', $this->createdPublications()[0]['wooCategory']);
		$this->assertSame('Adviezen windpark', $this->createdPublications()[0]['title']);
		$this->assertSame('infocat010', $result['wooPublication']['wooCategory']);
	}//end testTheBatchsCategoryAndTitleAreUsed()

	/**
	 * REQ-WBP-002: a missing document stops the publish before anything is written.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function testAMissingDocumentStopsThePublishAndCreatesNothing(): void {
		$this->approvedBatch();
		unset($this->documents['/alice/files/doc-b']);

		try {
			$this->service->publishBatch('batch-1');
			$this->fail('Expected the publish to be refused');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('b.pdf', $e->getMessage());
		}

		$this->assertSame([], $this->createdPublications());
		$this->assertSame([], $this->files->added);
		$this->assertSame('ready_for_review', $this->service->getBatch('batch-1')['status']);
	}//end testAMissingDocumentStopsThePublishAndCreatesNothing()

	/**
	 * REQ-WBP-002: publishing again after a partial failure reuses the publication.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function testPublishingAgainAfterAFailedAttachmentContinuesTheSamePublication(): void {
		$this->approvedBatch();
		$this->files->failOn = 'b.pdf';
		try {
			$this->service->publishBatch('batch-1');
			$this->fail('Expected the publish to stop');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('b.pdf', $e->getMessage());
		}

		$this->assertSame('ready_for_review', $this->service->getBatch('batch-1')['status']);
		$this->files->failOn = null;
		$this->service->publishBatch('batch-1');

		$this->assertCount(1, $this->createdPublications());
		$this->assertSame(['a.pdf', 'b.pdf', 'c-gelakt.pdf'], array_column($this->files->added, 'name'));
	}//end testPublishingAgainAfterAFailedAttachmentContinuesTheSamePublication()

	/**
	 * REQ-WBP-002: a reference is a file id, an absolute path or a path in the
	 * creator's files; a folder or nothing is not a document.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function testDocumentReferencesResolveByIdPathAndUserPathButNeverToAFolder(): void {
		$this->documents['id:42'] = $this->document('by-id.pdf');
		$this->documents['/alice/files/map'] = $this->createMock(\OCP\Files\Folder::class);
		$root = $this->createMock(\OCP\Files\IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(fn (int $id) => ($this->documents['id:'.$id] ?? null));
		$root->method('get')->willReturnCallback(fn (string $path) => ($this->documents[$path] ?? throw new \OCP\Files\NotFoundException($path)));
		$userFolder = $this->createMock(\OCP\Files\Folder::class);
		$userFolder->method('get')->willReturnCallback(fn (string $path) => ($this->documents['/alice/files/'.$path] ?? throw new \OCP\Files\NotFoundException($path)));
		$root->method('getUserFolder')->willReturn($userFolder);
		$writer = new \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter($root, $this->container, $this->createMock(\OCA\OpenCatalogi\Service\Portal\PublicationLinker::class), $this->l10n);

		$this->assertSame('by-id.pdf', $writer->resolve('42', 'alice')?->getName());
		$this->assertSame('a.pdf', $writer->resolve('/alice/files/doc-a', '')?->getName());
		$this->assertSame('b.pdf', $writer->resolve('doc-b', 'alice')?->getName());
		$this->assertNull($writer->resolve('/alice/files/map', 'alice'));
		$this->assertNull($writer->resolve('doc-b', ''));
		$this->assertNull($writer->resolve('7', 'alice'));
		$this->assertNull($writer->resolve('', 'alice'));
	}//end testDocumentReferencesResolveByIdPathAndUserPathButNeverToAFolder()

	/**
	 * REQ-WBP-002: without an approval nothing is created.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function testNoApprovalNoPublication(): void {
		$this->seedBatchWithAssessments(false);
		$this->service->markReadyForReview('batch-1');
		try {
			$this->service->publishBatch('batch-1');
		} catch (\RuntimeException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame([], $this->createdPublications());
	}//end testNoApprovalNoPublication()

	/**
	 * Names of the files the publish attached.
	 *
	 * @return array<int, string>
	 */
	private function attachedNames(): array {
		return array_column($this->files->added, 'name');
	}//end attachedNames()

	/**
	 * REQ-WRP-001, the fail-closed half: when the redaction call breaks, the
	 * original of a partly public document never reaches the publication.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testABrokenRedactionNeverPublishesTheOriginal(): void {
		$this->approvedBatch();
		$this->files->redacted = null;

		$assessed = $this->service->updateAssessment('a3', 'deels_openbaar', ['5.2.e'], 'batch-1');
		$this->assertSame(1, $this->files->redactions);
		$this->assertSame('failed', $assessed['redactionStatus']);
		$this->assertSame('', $assessed['anonymizedDocument']);
		$this->assertNotSame('', $assessed['redactionMessage']);

		// No fail() inside the try: PHPUnit's own failure is a RuntimeException too.
		$refusal = null;
		try {
			$this->service->publishBatch('batch-1');
		} catch (\RuntimeException $e) {
			$refusal = $e->getMessage();
		}

		$this->assertNotNull($refusal, 'A partly public document without a redacted version must block the publish');
		$this->assertStringContainsString('no verified redacted version', (string)$refusal);
		$this->assertStringContainsString('c.pdf', (string)$refusal);

		$this->assertNotContains('c.pdf', $this->attachedNames());
		$this->assertSame([], $this->files->added);
		$this->assertSame([], $this->createdPublications());
		$this->assertSame('ready_for_review', $this->service->getBatch('batch-1')['status']);
	}//end testABrokenRedactionNeverPublishesTheOriginal()

	/**
	 * REQ-WRP-001: a working redaction publishes the redacted file in place of the original.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testAVerifiedRedactionIsWhatGetsPublished(): void {
		$this->approvedBatch();
		$redacted = $this->documents['/alice/files/doc-c-anon'];
		$this->documents['id:4'] = $redacted;
		$this->files->redacted = $redacted;

		$assessed = $this->service->updateAssessment('a3', 'deels_openbaar', ['5.2.e'], 'batch-1');
		$this->assertSame('verified', $assessed['redactionStatus']);
		$this->assertSame('4', $assessed['anonymizedDocument']);

		$this->service->publishBatch('batch-1');
		$this->assertSame(['a.pdf', 'b.pdf', 'c-gelakt.pdf'], $this->attachedNames());
	}//end testAVerifiedRedactionIsWhatGetsPublished()

	/**
	 * REQ-WRP-001: a redacted file whose bytes changed since it was verified blocks the publish.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testARedactedFileChangedSinceVerificationBlocksThePublish(): void {
		$this->approvedBatch();
		foreach ($this->objects->objects as $i => $object) {
			if ($object['id'] === 'a3') {
				$this->objects->objects[$i]['anonymizedDocumentHash'] = hash('sha256', 'iets anders');
			}
		}

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no verified redacted version: c.pdf');
		try {
			$this->service->publishBatch('batch-1');
		} finally {
			$this->assertSame([], $this->files->added);
		}
	}//end testARedactedFileChangedSinceVerificationBlocksThePublish()

	/**
	 * REQ-WRP-001: a "redacted version" that is the original itself blocks the publish.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testARedactedVersionThatIsTheOriginalBlocksThePublish(): void {
		$this->approvedBatch();
		$this->documents['id:3'] = $this->documents['/alice/files/doc-c'];
		foreach ($this->objects->objects as $i => $object) {
			if ($object['id'] === 'a3') {
				// Same file reached two ways: by id and by path.
				$this->objects->objects[$i]['anonymizedDocument'] = '3';
				$this->objects->objects[$i]['anonymizedDocumentHash'] = hash('sha256', 'inhoud van c.pdf');
			}
		}

		$refusal = null;
		try {
			$this->service->publishBatch('batch-1');
		} catch (\RuntimeException $e) {
			$refusal = $e->getMessage();
		}

		$this->assertNotNull($refusal, 'The original must not pass as its own redacted version');
		$this->assertStringContainsString('no verified redacted version: c.pdf', (string)$refusal);

		$this->assertSame([], $this->files->added);
	}//end testARedactedVersionThatIsTheOriginalBlocksThePublish()

	/**
	 * REQ-WRP-002: the batch tells the officer which partly public documents cannot be published, and why.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-the-officer-sees-why-a-partly-public-document-cannot-be-published-req-wrp-002
	 */
	public function testTheBatchNamesEveryUnredactedDocumentWithItsReason(): void {
		$this->seedBatchWithAssessments(false);
		$this->assertSame([], $this->service->getBatch('batch-1')['documentSummary']['unredacted']);

		$this->files->redacted = null;
		$this->service->updateAssessment('a3', 'deels_openbaar', ['5.2.e'], 'batch-1');
		$this->objects->objects[] = ['id' => 'a5', 'assessment' => 'deels_openbaar', 'fileName' => 'e.pdf'];
		foreach ($this->objects->objects as $i => $object) {
			if ($object['id'] === 'batch-1') {
				$this->objects->objects[$i]['documents'][] = 'a5';
			}
		}

		$unredacted = $this->service->getBatch('batch-1')['documentSummary']['unredacted'];
		$this->assertSame(['c.pdf', 'e.pdf'], array_column($unredacted, 'fileName'));
		$this->assertStringContainsString('Redaction failed in OpenRegister', $unredacted[0]['reason']);
		$this->assertSame('No verified redacted version exists yet.', $unredacted[1]['reason']);
	}//end testTheBatchNamesEveryUnredactedDocumentWithItsReason()

	/**
	 * REQ-WRP-001: assessing away from partly public drops the redaction and runs none.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testAssessingAwayFromPartlyPublicDropsTheRedaction(): void {
		$this->seedBatchWithAssessments(false);
		$result = $this->service->updateAssessment('a3', 'openbaar', []);

		$this->assertSame(0, $this->files->redactions);
		$this->assertSame('', $result['anonymizedDocument']);
		$this->assertSame('', $result['redactionStatus']);
	}//end testAssessingAwayFromPartlyPublicDropsTheRedaction()

	/**
	 * REQ-WRP-001: without a batch the acting officer owns relative references.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function testWithoutABatchTheOfficersFilesAreSearched(): void {
		$this->seedBatchWithAssessments(false);
		$this->files->redacted = $this->documents['/alice/files/doc-c-anon'];

		$this->assertSame('verified', $this->service->updateAssessment('a3', 'deels_openbaar', ['5.2.e'])['redactionStatus']);
		$this->assertSame('verified', $this->service->updateAssessment('a3', 'deels_openbaar', ['5.2.e'], 'no-such-batch')['redactionStatus']);
	}//end testWithoutABatchTheOfficersFilesAreSearched()

	/**
	 * Seed a batch + 4 assessments. By default one stays "te_beoordelen"; when
	 * $leaveUnassessed is false all four are assessed (2 openbaar, 1 deels, 1 niet).
	 *
	 * @param bool $leaveUnassessed Whether to leave one document unassessed.
	 *
	 * @return void
	 */
	private function seedBatchWithAssessments(bool $leaveUnassessed = true): void {
		$fourth = ($leaveUnassessed === true
			? ['id' => 'a4', 'assessment' => 'te_beoordelen', 'fileName' => 'd.pdf']
			: ['id' => 'a4', 'assessment' => 'niet_openbaar', 'fileName' => 'd.pdf', 'weigeringsgronden' => ['5.1.2.e']]);

		$this->objects->objects = [
			['id' => 'a1', 'assessment' => 'openbaar', 'fileName' => 'a.pdf', 'documentReference' => 'doc-a'],
			['id' => 'a2', 'assessment' => 'openbaar', 'fileName' => 'b.pdf', 'documentReference' => 'doc-b'],
			[
				'id' => 'a3',
				'assessment' => 'deels_openbaar',
				'fileName' => 'c.pdf',
				'documentReference' => 'doc-c',
				'anonymizedDocument' => 'doc-c-anon',
				'anonymizedDocumentHash' => hash('sha256', 'inhoud van c-gelakt.pdf'),
				'redactionStatus' => 'verified',
				'weigeringsgronden' => ['5.2.e'],
			],
			$fourth,
			[
				'id' => 'batch-1',
				'status' => 'in_progress',
				'caseReference' => 'WOO-2026-009',
				'documents' => ['a1', 'a2', 'a3', 'a4'],
				'createdBy' => 'alice',
			],
		];

	}//end seedBatchWithAssessments()
}//end class
