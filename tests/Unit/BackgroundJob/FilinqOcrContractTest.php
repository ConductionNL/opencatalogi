<?php

/**
 * The cross-app names the attachment reading job looks up.
 *
 * The job finds filinq's OCR fallback and OpenRegister's text extraction by
 * name, so a rename on either side compiles here and silently reads nothing.
 * The names below were read on the other apps' `development` branch at the
 * commit named beside them; when this test fails, re-read that side and update
 * the job and the pin together.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-without-filinq-the-state-says-so-and-nothing-breaks-req-socr-002
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the filinq and OpenRegister names the job uses.
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob
 */
class FilinqOcrContractTest extends TestCase {

	/**
	 * filinq, read at ConductionNL/filinq development 300f79d528ebcd4c68b48dd258bb214dda22f25d
	 * (appinfo/info.xml and lib/Service/Ocr/OcrExtractionFallback.php).
	 */
	private const FILINQ = [
		'app' => 'filinq',
		'class' => 'OCA\Filinq\Service\Ocr\OcrExtractionFallback',
		'method' => 'afterExtraction',
		'parameters' => ['fileId' => 'int', 'textExtractor' => 'object', 'force' => 'bool'],
		'returns' => 'array',
		'answerKeys' => ['ocr', 'ocrSkipped', 'ocrDetectionPending'],
	];

	/**
	 * OpenRegister, read at ConductionNL/openregister development 70e557045555e68834e6f55f195211db3bd3b07e
	 * (lib/Files/Service/TextExtractionService.php).
	 */
	private const OPENREGISTER = [
		'class' => 'OCA\OpenRegister\Files\Service\TextExtractionService',
		'read' => 'getExtractedText',
		'ingest' => 'extractFromProvidedText',
	];

	public function testTheJobLooksUpFilinqsPinnedNames(): void {
		$this->assertSame(self::FILINQ['app'], AttachmentReadingJob::OCR_APP);
		$this->assertSame(self::FILINQ['class'], AttachmentReadingJob::OCR_FALLBACK_CLASS);
		$this->assertSame(self::FILINQ['method'], AttachmentReadingJob::OCR_FALLBACK_METHOD);
	}//end testTheJobLooksUpFilinqsPinnedNames()

	public function testTheJobLooksUpOpenRegistersPinnedExtractor(): void {
		$this->assertSame(self::OPENREGISTER['class'], AttachmentReadingJob::TEXT_EXTRACTION_CLASS);
	}//end testTheJobLooksUpOpenRegistersPinnedExtractor()

	public function testTheJobReadsTheFallbackAnswerKeysFilinqReturns(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/BackgroundJob/AttachmentReadingJob.php');

		foreach (self::FILINQ['answerKeys'] as $key) {
			$this->assertStringContainsString("'" . $key . "'", $source, 'the job reads the ' . $key . ' key');
		}
	}//end testTheJobReadsTheFallbackAnswerKeysFilinqReturns()

	/**
	 * When filinq's real class is loadable (an instance with filinq beside this app), its signature must match the pin.
	 */
	public function testTheRealFallbackMatchesThePinWhenLoadable(): void {
		$class = self::FILINQ['class'];
		if (class_exists($class) === false || method_exists($class, self::FILINQ['method']) === false) {
			$this->markTestSkipped('filinq is not installed beside this app; the pin above is the contract.');
		}

		$method = new ReflectionMethod($class, self::FILINQ['method']);
		$parameters = [];
		foreach ($method->getParameters() as $parameter) {
			$parameters[$parameter->getName()] = (string)$parameter->getType();
		}

		$this->assertSame(self::FILINQ['parameters'], $parameters);
		$this->assertSame(self::FILINQ['returns'], (string)$method->getReturnType());
	}//end testTheRealFallbackMatchesThePinWhenLoadable()

	/**
	 * When OpenRegister's real extractor is loadable, it has both methods the OCR path needs.
	 */
	public function testTheRealExtractorHasTheReadAndIngestMethodsWhenLoadable(): void {
		if (class_exists(self::OPENREGISTER['class']) === false) {
			$this->markTestSkipped('OpenRegister is not installed beside this app; the pin above is the contract.');
		}

		$this->assertTrue(method_exists(self::OPENREGISTER['class'], self::OPENREGISTER['read']));
		$this->assertTrue(method_exists(self::OPENREGISTER['class'], self::OPENREGISTER['ingest']));
	}//end testTheRealExtractorHasTheReadAndIngestMethodsWhenLoadable()
}//end class
