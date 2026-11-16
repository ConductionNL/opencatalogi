<?php

/**
 * The review of a Woo publication batch.
 *
 * The reviewer is the last person to see a batch before the public does, so
 * the review shows each document as the public will see it: the file that
 * will be published and the metadata the publication will carry. Approving
 * waits until the reviewer opened every one of those views. A rejection and a
 * single document's send-back go back with the reviewer's reason.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Review;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Contract\BatchReviewInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * What the reviewer of a Woo batch sees and does.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md
 */
class BatchReview implements BatchReviewInterface {
	use ReadsObjectProperties;

	/**
	 * The approval chain on the batch's `approve` transition.
	 *
	 * @var string
	 */
	public const CHAIN = 'batchReview';

	/**
	 * The batch state in which a review happens.
	 *
	 * @var string
	 */
	public const IN_REVIEW = 'ready_for_review';

	/**
	 * The assessments whose document is published, and so previewed.
	 *
	 * @var array<int, string>
	 */
	public const PREVIEWED = ['openbaar', 'deels_openbaar'];

	/**
	 * The publication fields the public read returns.
	 *
	 * @var array<int, string>
	 */
	public const PUBLIC_FIELDS = ['title', 'wooCategory', 'publicationKind', 'publicationDate', 'caseReference'];

	/**
	 * Constructor.
	 *
	 * @param TransparencyWorkflowServiceInterface $woo         The batch, its OpenRegister wiring and its transitions.
	 * @param BatchPublicationWriter               $writer      The publication a batch becomes, and its files.
	 * @param IUserSession                         $userSession Who is reviewing.
	 * @param IURLGenerator                        $urls        Links to the files.
	 * @param LoggerInterface                      $logger      The log.
	 */
	public function __construct(
		private readonly TransparencyWorkflowServiceInterface $woo,
		private readonly BatchPublicationWriter $writer,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an OpenRegister task sequence is the review chain of a Woo batch.
	 *
	 * @param string $sequenceUuid The sequence uuid a task carries.
	 *
	 * @return boolean False when the sequence cannot be read.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function isBatchReviewSequence(string $sequenceUuid): bool {
		$mapper = $this->woo->getTaskSequenceMapper();
		if ($sequenceUuid === '' || $mapper === null || method_exists($mapper, 'findByUuid') === false) {
			return false;
		}

		try {
			$sequence = $mapper->findByUuid($sequenceUuid);
		} catch (Throwable $e) {
			$this->logger->info('[BatchReview] task sequence ' . $sequenceUuid . ' not readable: ' . $e->getMessage());
			return false;
		}

		return (string)$sequence->getChainKey() === self::CHAIN;
	}//end isBatchReviewSequence()

	/**
	 * Run the batch's `reject` transition with the reviewer's reason.
	 *
	 * @param string $batchId The batch uuid.
	 * @param string $reason  Why the batch goes back.
	 *
	 * @return array<string, mixed> The batch after the rejection.
	 *
	 * @throws RuntimeException When the reason is empty or the engine refuses.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function rejectBatch(string $batchId, string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException('A rejection needs a reason');
		}

		return $this->woo->runBatchTransition(batchId: $batchId, action: 'reject', data: ['reason' => $reason]);
	}//end rejectBatch()

	/**
	 * Run the batch's `approve` transition for the signed-in reviewer.
	 *
	 * The transition's guard checks the same again inside the engine; this
	 * check answers first so the refusal names the documents.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The batch after the transition.
	 *
	 * @throws RuntimeException Naming the documents whose public view was not opened, or what the engine refused.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function approveBatch(string $batchId): array {
		$batch = $this->batchInReview(batchId: $batchId);
		$unseen = $this->unseenPreviews(batch: $batch, userId: $this->signedInUid());
		if ($unseen !== []) {
			throw new RuntimeException(self::unseenMessage(unseen: $unseen));
		}

		return $this->woo->runBatchTransition(batchId: $batchId, action: 'approve');
	}//end approveBatch()

	/**
	 * Each document of a batch as the public will see it.
	 *
	 * Per document: the assessment, a link to the file that will be published
	 * (the verified redacted file of a partly public document, the original of
	 * a public one, none for a withheld one) and the publication metadata as the
	 * public read returns it. The redaction log of a partly public document
	 * comes along for the reviewer; this answer is not public.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The batch id and status, the documents and what the reviewer has not opened yet.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
	 */
	public function publicPreview(string $batchId): array {
		$batch = $this->woo->getBatch(batchId: $batchId);
		$uid = $this->signedInUid();
		$seen = $this->seenBy(batch: $batch, userId: $uid);
		$metadata = $this->publicMetadata(batch: $batch);
		$owner = (string)($batch['createdBy'] ?? '');

		$documents = [];
		foreach ($this->assessments(batch: $batch) as $documentId => $assessment) {
			$kind = (string)($assessment['assessment'] ?? '');
			$title = (string)($assessment['fileName'] ?? '');
			$document = [
				'documentId' => $documentId,
				'title' => $title,
				'assessment' => $kind,
				'viewerUrl' => $this->viewerUrl(assessment: $assessment, owner: $owner),
				'publicMetadata' => null,
				'seen' => in_array($documentId, $seen, true),
			];
			if (in_array($kind, self::PREVIEWED, true) === true) {
				$document['publicMetadata'] = array_merge($metadata, ['documentTitle' => $title]);
			}

			if ($kind === 'deels_openbaar') {
				$document['redactionLog'] = ($assessment['redactionLog'] ?? null);
			}

			if (empty($assessment['sentBackReason']) === false) {
				$document['sentBackReason'] = $assessment['sentBackReason'];
			}

			$documents[] = $document;
		}//end foreach

		return [
			'batchId' => $batchId,
			'status' => (string)($batch['status'] ?? ''),
			'documents' => $documents,
			'unseen' => $this->unseenPreviews(batch: $batch, userId: $uid),
		];
	}//end publicPreview()

	/**
	 * Record that the signed-in reviewer opened the public view of one document.
	 *
	 * @param string $batchId    The batch uuid.
	 * @param string $documentId The assessment uuid of the document.
	 *
	 * @return array<string, mixed> The documents this reviewer opened and the ones still unseen.
	 *
	 * @throws RuntimeException When the document is not the batch's or OpenRegister is unavailable.
	 * @throws BatchNotInReviewException When the batch is not in review.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function markPreviewSeen(string $batchId, string $documentId): array {
		$uid = $this->signedInUid();
		$batch = $this->batchInReview(batchId: $batchId);
		self::assertBatchDocument(batch: $batch, documentId: $documentId);

		$seen = $this->seenBy(batch: $batch, userId: $uid);
		if (in_array($documentId, $seen, true) === false) {
			$seen[] = $documentId;
		}

		$previewsSeen = ($batch['previewsSeen'] ?? []);
		if (is_array($previewsSeen) === false) {
			$previewsSeen = [];
		}

		$previewsSeen[$uid] = $seen;
		$batch['previewsSeen'] = $previewsSeen;
		unset($batch['documentSummary']);
		$this->save(schema: $this->woo->getBatchSchema(), data: $batch);

		return [
			'documentId' => $documentId,
			'seen' => $seen,
			'unseen' => $this->unseenPreviews(batch: $batch, userId: $uid),
		];
	}//end markPreviewSeen()

	/**
	 * The published documents of a batch whose public view a user has not opened.
	 *
	 * A document that cannot be read counts as unseen: approving waits for it.
	 *
	 * @param array<string, mixed> $batch  The batch.
	 * @param string               $userId The reviewer.
	 *
	 * @return array<int, array{documentId: string, title: string}> Empty when every one was opened.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function unseenPreviews(array $batch, string $userId): array {
		$seen = $this->seenBy(batch: $batch, userId: $userId);
		$assessments = $this->assessments(batch: $batch);

		$unseen = [];
		foreach (self::documentIds(batch: $batch) as $documentId) {
			$assessment = ($assessments[$documentId] ?? null);
			if ($assessment !== null && in_array((string)($assessment['assessment'] ?? ''), self::PREVIEWED, true) === false) {
				continue;
			}

			if ($userId !== '' && in_array($documentId, $seen, true) === true) {
				continue;
			}

			$unseen[] = ['documentId' => $documentId, 'title' => (string)($assessment['fileName'] ?? '')];
		}

		return $unseen;
	}//end unseenPreviews()

	/**
	 * Send one document of a batch in review back to assessment, with the reason.
	 *
	 * The assessment returns to `te_beoordelen` and keeps the reason, who sent
	 * it back and when as `sentBackReason`.
	 *
	 * @param string $batchId      The batch uuid.
	 * @param string $assessmentId The assessment uuid.
	 * @param string $reason       Why it goes back.
	 *
	 * @return array<string, mixed> The assessment after the send-back.
	 *
	 * @throws RuntimeException When the reason is empty or the document is not the batch's.
	 * @throws BatchNotInReviewException When the batch is not in review.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function sendBack(string $batchId, string $assessmentId, string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException('Sending a document back needs a reason');
		}

		$uid = $this->signedInUid();
		$batch = $this->batchInReview(batchId: $batchId);
		self::assertBatchDocument(batch: $batch, documentId: $assessmentId);

		$assessment = ($this->assessments(batch: ['documents' => [$assessmentId]])[$assessmentId] ?? null);
		if ($assessment === null) {
			throw new RuntimeException('Assessment not found: ' . $assessmentId);
		}

		$assessment['assessment'] = 'te_beoordelen';
		$assessment['sentBackReason'] = [
			'reason' => $reason,
			'by' => $uid,
			'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
		];

		return $this->plainArray(object: $this->save(schema: $this->woo->getAssessmentSchema(), data: $assessment));
	}//end sendBack()

	/**
	 * The batch, refused unless it is in review.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The batch.
	 *
	 * @throws BatchNotInReviewException When the batch is not `ready_for_review`.
	 */
	private function batchInReview(string $batchId): array {
		$batch = $this->woo->getBatch(batchId: $batchId);
		if ((string)($batch['status'] ?? '') !== self::IN_REVIEW) {
			throw new BatchNotInReviewException('The batch is not in review');
		}

		return $batch;
	}//end batchInReview()

	/**
	 * Refuse a document id that is not one of the batch's.
	 *
	 * @param array<string, mixed> $batch      The batch.
	 * @param string               $documentId The assessment uuid.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the batch does not hold the document.
	 */
	private static function assertBatchDocument(array $batch, string $documentId): void {
		if ($documentId === '' || in_array($documentId, self::documentIds(batch: $batch), true) === false) {
			throw new RuntimeException('Assessment not found: ' . $documentId);
		}
	}//end assertBatchDocument()

	/**
	 * The assessment ids of a batch.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<int, string>
	 */
	private static function documentIds(array $batch): array {
		$refs = ($batch['documents'] ?? []);
		if (is_array($refs) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $refs), static fn (string $ref): bool => $ref !== ''));
	}//end documentIds()

	/**
	 * The documents a user opened the public view of.
	 *
	 * @param array<string, mixed> $batch  The batch.
	 * @param string               $userId The reviewer.
	 *
	 * @return array<int, string> Assessment ids.
	 */
	private function seenBy(array $batch, string $userId): array {
		$seen = ($batch['previewsSeen'][$userId] ?? []);
		if ($userId === '' || is_array($seen) === false) {
			return [];
		}

		return array_values(array_map('strval', $seen));
	}//end seenBy()

	/**
	 * The batch's assessments, keyed by id; one that cannot be read is left out.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function assessments(array $batch): array {
		$objectService = $this->woo->getObjectService();
		$out = [];
		if ($objectService === null) {
			return $out;
		}

		foreach (self::documentIds(batch: $batch) as $ref) {
			try {
				$out[$ref] = $this->plainArray(object: $objectService->find($ref));
			} catch (Throwable $e) {
				$this->logger->info('[BatchReview] assessment ' . $ref . ' not loadable: ' . $e->getMessage());
			}
		}

		return $out;
	}//end assessments()

	/**
	 * The publication metadata as the public read returns it.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, mixed>
	 */
	private function publicMetadata(array $batch): array {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		return array_intersect_key($this->writer->payload(batch: $batch, now: $now), array_flip(self::PUBLIC_FIELDS));
	}//end publicMetadata()

	/**
	 * A link to the file a document is published as, or null when none is.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param string               $owner      The batch's creator, for a relative path.
	 *
	 * @return string|null
	 */
	private function viewerUrl(array $assessment, string $owner): ?string {
		$kind = (string)($assessment['assessment'] ?? '');
		$reference = '';
		if ($kind === 'openbaar') {
			$reference = (string)($assessment['documentReference'] ?? '');
		}

		// Only the verified redacted file is published, never the original.
		if ($kind === 'deels_openbaar' && (string)($assessment['redactionStatus'] ?? '') === 'verified') {
			$reference = (string)($assessment['anonymizedDocument'] ?? '');
		}

		$file = $this->writer->resolve(reference: $reference, owner: $owner);
		if ($file === null) {
			return null;
		}

		return $this->urls->linkToRoute('files.View.showFile', ['fileid' => $file->getId()]);
	}//end viewerUrl()

	/**
	 * Save an object of the Woo register.
	 *
	 * @param string|null          $schema The schema id.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return mixed The saved object.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function save(?string $schema, array $data): mixed {
		$objectService = $this->woo->getObjectService();
		$register = $this->woo->getRegister();
		if ($objectService === null || $register === null || $schema === null) {
			throw new RuntimeException('OpenRegister WOO register/schema unavailable');
		}

		return $objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $register, schema: $schema),
				uuid: (string)($data['id'] ?? ($data['uuid'] ?? ''))
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The signed-in reviewer; a review needs a person.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When nobody is signed in.
	 */
	private function signedInUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Not logged in');
		}

		return $user->getUID();
	}//end signedInUid()

	/**
	 * The refusal that names the documents not opened.
	 *
	 * @param array<int, array{documentId: string, title: string}> $unseen The documents.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public static function unseenMessage(array $unseen): string {
		$names = [];
		foreach ($unseen as $doc) {
			$name = $doc['title'];
			if ($name === '') {
				$name = $doc['documentId'];
			}

			$names[] = $name;
		}

		return 'Approve refused: the public view of these documents was not opened: ' . implode(', ', $names);
	}//end unseenMessage()
}//end class
