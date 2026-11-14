<?php

/**
 * OpenCatalogi attachment reading job.
 *
 * Runs after an attachment of a publication is published, outside the publish
 * request, and records whether search can read it: OpenRegister's extracted
 * text, or else filinq's OCR fallback, which hands the recognised words to
 * OpenRegister's chunk index. Without filinq it says so and does nothing else.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one published attachment and records its reading state.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
class AttachmentReadingJob extends QueuedJob {

	/**
	 * OpenRegister extracted typed text from the file.
	 */
	public const STATE_TYPED = 'typed';

	/**
	 * OCR read the file and OpenRegister took its text.
	 */
	public const STATE_OCR = 'ocr';

	/**
	 * OCR ran, but its text is not in the index yet.
	 */
	public const STATE_WAITING = 'waiting';

	/**
	 * The file has no text search can read.
	 */
	public const STATE_NONE = 'none';

	/**
	 * No OCR is installed.
	 */
	public const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * Reading failed; the reason says why.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * OpenRegister's text extraction service.
	 */
	public const TEXT_EXTRACTION_CLASS = 'OCA\OpenRegister\Files\Service\TextExtractionService';

	/**
	 * The app that owns OCR (ADR-022), as its appinfo/info.xml names it.
	 */
	public const OCR_APP = 'filinq';

	/**
	 * filinq's OCR fallback, looked up by name so opencatalogi does not depend on filinq.
	 */
	public const OCR_FALLBACK_CLASS = 'OCA\Filinq\Service\Ocr\OcrExtractionFallback';

	/**
	 * The fallback's method: afterExtraction(int $fileId, object $textExtractor, bool $force): array.
	 */
	public const OCR_FALLBACK_METHOD = 'afterExtraction';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ContainerInterface $container Resolves OpenRegister's extraction and filinq's fallback.
	 * @param IAppManager $appManager Whether filinq is enabled.
	 * @param IRootFolder $rootFolder The file's MIME type.
	 * @param AttachmentReadingStore $store Records the state.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IRootFolder $rootFolder,
		private readonly AttachmentReadingStore $store,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Read the queued attachment and record its state.
	 *
	 * @param mixed $argument `{fileId, publication, force}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$fileId = (int)($argument['fileId'] ?? 0);
		$publication = (string)($argument['publication'] ?? '');
		if ($fileId <= 0 || $publication === '') {
			return;
		}

		[$state, $reason] = $this->read(fileId: $fileId, force: (($argument['force'] ?? false) === true));

		try {
			$this->store->record(fileId: $fileId, publicationId: $publication, state: $state, reason: $reason);
		} catch (Throwable $e) {
			$this->logger->error(
				'[AttachmentReadingJob] the reading state could not be recorded',
				['fileId' => $fileId, 'state' => $state, 'exception' => $e->getMessage()]
			);
		}

	}//end run()

	/**
	 * The reading state of one file, and its reason.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param bool $force Whether an editor asked to read it again.
	 *
	 * @return array{0: string, 1: string} The state and the reason.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-without-filinq-the-state-says-so-and-nothing-breaks-req-socr-002
	 */
	public function read(int $fileId, bool $force): array {
		$extractor = $this->textExtractor();
		if ($extractor === null) {
			return [self::STATE_FAILED, 'Text extraction is not available'];
		}

		$typed = $this->hasTypedText(extractor: $extractor, fileId: $fileId);
		if ($typed === null) {
			return [self::STATE_FAILED, 'The extracted text could not be read'];
		}

		if ($typed === true) {
			return [self::STATE_TYPED, ''];
		}

		return $this->readScan(fileId: $fileId, extractor: $extractor, force: $force);

	}//end read()

	/**
	 * The state for the fallback's answer.
	 *
	 * An `ocrSkipped` answer failed with its reason; `ocrDetectionPending` true
	 * ran OCR but OpenRegister did not take the text yet; an ingested run with
	 * text was read by OCR; an empty answer, or a run that found no text, is none.
	 *
	 * @param mixed $answer What afterExtraction() returned.
	 *
	 * @return array{0: string, 1: string} The state and the reason.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-without-filinq-the-state-says-so-and-nothing-breaks-req-socr-002
	 */
	public function fromOcrAnswer(mixed $answer): array {
		if (is_array($answer) === false || $answer === []) {
			return [self::STATE_NONE, 'No text found'];
		}

		if (array_key_exists('ocrSkipped', $answer) === true) {
			$reason = trim((string)$answer['ocrSkipped']);
			return [self::STATE_FAILED, ($reason === '' ? 'OCR was skipped' : $reason)];
		}

		if (($answer['ocrDetectionPending'] ?? false) === true) {
			return [self::STATE_WAITING, ''];
		}

		return $this->fromOcrRun(ocr: ($answer['ocr'] ?? []));

	}//end fromOcrAnswer()

	/**
	 * The state for the `ocr` summary of a run OpenRegister took.
	 *
	 * @param mixed $ocr The `ocr` field of the answer.
	 *
	 * @return array{0: string, 1: string} The state and the reason.
	 */
	private function fromOcrRun(mixed $ocr): array {
		if (is_array($ocr) === false || ($ocr['ran'] ?? false) !== true || ($ocr['ingested'] ?? false) !== true) {
			return [self::STATE_FAILED, 'OCR did not complete'];
		}

		if (isset($ocr['textLength']) === true && (int)$ocr['textLength'] === 0) {
			return [self::STATE_NONE, 'No text found'];
		}

		return [self::STATE_OCR, ''];

	}//end fromOcrRun()

	/**
	 * Whether OpenRegister extracted text from the file, or null when that cannot be read.
	 *
	 * @param object $extractor OpenRegister's TextExtractionService.
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool|null True for typed text.
	 */
	private function hasTypedText(object $extractor, int $fileId): ?bool {
		try {
			$text = $extractor->getExtractedText($fileId);
		} catch (Throwable $e) {
			$this->logger->warning('[AttachmentReadingJob] extracted text unreadable', ['fileId' => $fileId, 'exception' => $e->getMessage()]);
			return null;
		}

		return is_string($text) === true && trim($text) !== '';

	}//end hasTypedText()

	/**
	 * Send a PDF or image without text to filinq's OCR fallback.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param object $extractor OpenRegister's TextExtractionService, which the fallback ingests into.
	 * @param bool $force Whether an editor asked to read it again.
	 *
	 * @return array{0: string, 1: string} The state and the reason.
	 */
	private function readScan(int $fileId, object $extractor, bool $force): array {
		$mimeType = $this->mimeType(fileId: $fileId);
		if ($mimeType === null) {
			return [self::STATE_FAILED, 'The file cannot be found'];
		}

		if ($mimeType !== 'application/pdf' && str_starts_with($mimeType, 'image/') === false) {
			return [self::STATE_NONE, 'No text found'];
		}

		$fallback = $this->ocrFallback();
		if ($fallback === null) {
			return [self::STATE_UNAVAILABLE, 'OCR is not installed'];
		}

		try {
			$answer = $fallback->{self::OCR_FALLBACK_METHOD}($fileId, $extractor, $force);
		} catch (Throwable $e) {
			$this->logger->warning('[AttachmentReadingJob] the OCR fallback failed', ['fileId' => $fileId, 'exception' => $e->getMessage()]);
			return [self::STATE_FAILED, 'OCR failed'];
		}

		return $this->fromOcrAnswer(answer: $answer);

	}//end readScan()

	/**
	 * OpenRegister's TextExtractionService, or null when it cannot be resolved.
	 *
	 * @return object|null The service.
	 */
	private function textExtractor(): ?object {
		try {
			$extractor = $this->container->get(self::TEXT_EXTRACTION_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($extractor) === false || method_exists($extractor, 'getExtractedText') === false) {
			return null;
		}

		return $extractor;

	}//end textExtractor()

	/**
	 * filinq's OCR fallback, or null when filinq is not enabled or has no such class.
	 *
	 * @return object|null The fallback.
	 */
	private function ocrFallback(): ?object {
		try {
			if ($this->appManager->isEnabledForUser(self::OCR_APP) === false) {
				return null;
			}

			if (class_exists(self::OCR_FALLBACK_CLASS) === false) {
				return null;
			}

			$fallback = $this->container->get(self::OCR_FALLBACK_CLASS);
		} catch (Throwable $e) {
			$this->logger->warning('[AttachmentReadingJob] the OCR fallback cannot be resolved', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($fallback) === false || method_exists($fallback, self::OCR_FALLBACK_METHOD) === false) {
			return null;
		}

		return $fallback;

	}//end ocrFallback()

	/**
	 * The file's MIME type, or null when the file cannot be found.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string|null The MIME type.
	 */
	private function mimeType(int $fileId): ?string {
		try {
			$node = $this->rootFolder->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		if ($node instanceof File === false) {
			return null;
		}

		return $node->getMimeType();

	}//end mimeType()
}//end class
