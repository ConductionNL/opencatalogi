<?php

/**
 * Tests for the attachment reading job.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob;
use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Double of OpenRegister's TextExtractionService.
 */
class ReadingFakeExtractor {

	/** @var list<int> */
	public array $asked = [];

	public function __construct(public ?string $text = null) {
	}//end __construct()

	public function getExtractedText(int $fileId): ?string {
		$this->asked[] = $fileId;
		return $this->text;
	}//end getExtractedText()
}//end class

/**
 * Double of filinq's OcrExtractionFallback.
 */
class ReadingFakeFallback {

	/** @var list<array{0: int, 1: object, 2: bool}> */
	public array $calls = [];

	public ?\Throwable $throws = null;

	public function __construct(public array $answer = []) {
	}//end __construct()

	public function afterExtraction(int $fileId, object $textExtractor, bool $force): array {
		$this->calls[] = [$fileId, $textExtractor, $force];
		if ($this->throws !== null) {
			throw $this->throws;
		}

		return $this->answer;
	}//end afterExtraction()
}//end class

/**
 * Tests for AttachmentReadingJob.
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob
 */
class AttachmentReadingJobTest extends TestCase {

	private ReadingFakeExtractor $extractor;

	private ?ReadingFakeFallback $fallback = null;

	private bool $filinqEnabled = true;

	private string $mimeType = 'application/pdf';

	/** @var list<array<string, mixed>> */
	private array $recorded = [];

	protected function setUp(): void {
		$this->extractor = new ReadingFakeExtractor();

	}//end setUp()

	/**
	 * The job under test.
	 *
	 * @return AttachmentReadingJob
	 */
	private function job(): AttachmentReadingJob {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === AttachmentReadingJob::TEXT_EXTRACTION_CLASS) {
					return $this->extractor;
				}

				if ($id === AttachmentReadingJob::OCR_FALLBACK_CLASS && $this->fallback !== null) {
					return $this->fallback;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'filinq' && $this->filinqEnabled);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturnCallback(fn (): string => $this->mimeType);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturn($file);

		$store = $this->createMock(AttachmentReadingStore::class);
		$store->method('record')->willReturnCallback(
			function (int $fileId, string $publicationId, string $state, string $reason): void {
				$this->recorded[] = ['fileId' => $fileId, 'publication' => $publicationId, 'state' => $state, 'reason' => $reason];
			}
		);

		return new AttachmentReadingJob(
			$this->createMock(ITimeFactory::class),
			$container,
			$apps,
			$root,
			$store,
			new NullLogger()
		);
	}//end job()

	/**
	 * Run the protected run() as the job list would.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$run = new ReflectionMethod(AttachmentReadingJob::class, 'run');
		$run->invoke($this->job(), $argument);
	}//end runJob()

	/**
	 * Whether filinq's class exists in this test run; the job looks it up by name.
	 *
	 * @return void
	 */
	private function defineFilinqClass(): void {
		if (class_exists(AttachmentReadingJob::OCR_FALLBACK_CLASS) === false) {
			eval('namespace OCA\Filinq\Service\Ocr; class OcrExtractionFallback {}');
		}
	}//end defineFilinqClass()

	public function testATypedPdfIsRecordedAsTypedWithoutOcr(): void {
		$this->defineFilinqClass();
		$this->extractor->text = 'Besluit op het verzoek om informatie';
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => true, 'ingested' => true]]);

		$this->runJob(['fileId' => 42, 'publication' => 'pub-1']);

		$this->assertSame([['fileId' => 42, 'publication' => 'pub-1', 'state' => 'typed', 'reason' => '']], $this->recorded);
		$this->assertSame([42], $this->extractor->asked);
		$this->assertSame([], $this->fallback->calls, 'a typed PDF is never sent to OCR');
	}//end testATypedPdfIsRecordedAsTypedWithoutOcr()

	public function testAScanWithoutTextIsSentToTheOcrFallback(): void {
		$this->defineFilinqClass();
		$this->fallback = new ReadingFakeFallback(
			['ocr' => ['ran' => true, 'ingested' => true, 'textLength' => 312], 'ocrDetectionPending' => false]
		);

		$this->runJob(['fileId' => 7, 'publication' => 'pub-2']);

		$this->assertCount(1, $this->fallback->calls);
		$this->assertSame(7, $this->fallback->calls[0][0]);
		$this->assertSame($this->extractor, $this->fallback->calls[0][1], 'the fallback gets OpenRegister\'s extractor to ingest into');
		$this->assertFalse($this->fallback->calls[0][2]);
		$this->assertSame([['fileId' => 7, 'publication' => 'pub-2', 'state' => 'ocr', 'reason' => '']], $this->recorded);
	}//end testAScanWithoutTextIsSentToTheOcrFallback()

	public function testReadAgainPassesForceToTheFallback(): void {
		$this->defineFilinqClass();
		$this->mimeType = 'image/tiff';
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => true, 'ingested' => true]]);

		$this->runJob(['fileId' => 8, 'publication' => 'pub-2', 'force' => true]);

		$this->assertTrue($this->fallback->calls[0][2]);
		$this->assertSame('ocr', $this->recorded[0]['state']);
	}//end testReadAgainPassesForceToTheFallback()

	public function testWithoutFilinqTheStateIsUnavailable(): void {
		$this->defineFilinqClass();
		$this->filinqEnabled = false;
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => true, 'ingested' => true]]);

		$this->runJob(['fileId' => 9, 'publication' => 'pub-3']);

		$this->assertSame([['fileId' => 9, 'publication' => 'pub-3', 'state' => 'unavailable', 'reason' => 'OCR is not installed']], $this->recorded);
		$this->assertSame([], $this->fallback->calls);
	}//end testWithoutFilinqTheStateIsUnavailable()

	public function testAFilinqWithoutTheFallbackServiceIsUnavailable(): void {
		$this->defineFilinqClass();
		$this->fallback = null;

		$this->runJob(['fileId' => 9, 'publication' => 'pub-3']);

		$this->assertSame('unavailable', $this->recorded[0]['state']);
		$this->assertSame('OCR is not installed', $this->recorded[0]['reason']);
	}//end testAFilinqWithoutTheFallbackServiceIsUnavailable()

	public function testAnOcrSkippedAnswerIsRecordedAsFailed(): void {
		$this->defineFilinqClass();
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => false, 'reason' => 'Tesseract is not installed'], 'ocrSkipped' => 'Tesseract is not installed']);

		$this->runJob(['fileId' => 11, 'publication' => 'pub-4']);

		$this->assertSame([['fileId' => 11, 'publication' => 'pub-4', 'state' => 'failed', 'reason' => 'Tesseract is not installed']], $this->recorded);
	}//end testAnOcrSkippedAnswerIsRecordedAsFailed()

	public function testAPendingDetectionIsWaiting(): void {
		$this->defineFilinqClass();
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => true, 'ingested' => false], 'ocrDetectionPending' => true]);

		$this->runJob(['fileId' => 12, 'publication' => 'pub-4']);

		$this->assertSame('waiting', $this->recorded[0]['state']);
	}//end testAPendingDetectionIsWaiting()

	public function testAnEmptyAnswerOrAnEmptyReadIsNone(): void {
		$job = $this->job();

		$this->assertSame(['none', 'No text found'], $job->fromOcrAnswer(answer: []));
		$this->assertSame(['none', 'No text found'], $job->fromOcrAnswer(answer: ['ocr' => ['ran' => true, 'ingested' => true, 'textLength' => 0]]));
		$this->assertSame(['failed', 'OCR did not complete'], $job->fromOcrAnswer(answer: ['ocr' => ['ran' => true, 'ingested' => false]]));
		$this->assertSame(['failed', 'OCR was skipped'], $job->fromOcrAnswer(answer: ['ocrSkipped' => '']));
	}//end testAnEmptyAnswerOrAnEmptyReadIsNone()

	public function testAFallbackThatThrowsIsFailedAndTheJobDoesNotThrow(): void {
		$this->defineFilinqClass();
		$this->fallback = new ReadingFakeFallback();
		$this->fallback->throws = new RuntimeException('tesseract crashed');

		$this->runJob(['fileId' => 13, 'publication' => 'pub-5']);

		$this->assertSame([['fileId' => 13, 'publication' => 'pub-5', 'state' => 'failed', 'reason' => 'OCR failed']], $this->recorded);
	}//end testAFallbackThatThrowsIsFailedAndTheJobDoesNotThrow()

	public function testAFileThatIsNeitherPdfNorImageIsNotSentToOcr(): void {
		$this->defineFilinqClass();
		$this->mimeType = 'application/zip';
		$this->fallback = new ReadingFakeFallback(['ocr' => ['ran' => true, 'ingested' => true]]);

		$this->runJob(['fileId' => 14, 'publication' => 'pub-6']);

		$this->assertSame([], $this->fallback->calls);
		$this->assertSame('none', $this->recorded[0]['state']);
	}//end testAFileThatIsNeitherPdfNorImageIsNotSentToOcr()

	public function testAnArgumentWithoutFileOrPublicationRecordsNothing(): void {
		$this->runJob(['fileId' => 0, 'publication' => 'pub-7']);
		$this->runJob(['fileId' => 5, 'publication' => '']);
		$this->runJob('not an array');

		$this->assertSame([], $this->recorded);
		$this->assertSame([], $this->extractor->asked);
	}//end testAnArgumentWithoutFileOrPublicationRecordsNothing()
}//end class
