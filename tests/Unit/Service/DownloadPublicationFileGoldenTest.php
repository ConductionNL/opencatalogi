<?php

/**
 * Golden test for DownloadService::createPublicationFile.
 *
 * Every case is recorded once and replayed from the fixture: the file or the
 * refusal, the template context the PDF was rendered from, and whether the
 * temporary render file was removed.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DownloadService::createPublicationFile.
 *
 * @covers \OCA\OpenCatalogi\Service\DownloadService
 */
class DownloadPublicationFileGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/download-publication-file-golden.json';

	/**
	 * What happened, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'publication given' => [['options' => ['download' => true, 'publication' => ['id' => '1', 'title' => 'Besluit']]]],
			'publication without title' => [['options' => ['download' => true, 'publication' => ['id' => '1']]]],
			'download off' => [['options' => ['download' => false, 'publication' => ['id' => '1']]]],
			'default options, found' => [['options' => null, 'found' => ['id' => '7', 'title' => 'Gevonden']]],
			'no download key, found' => [['options' => ['publication' => null], 'found' => ['id' => '8', 'title' => 'Acht']]],
			'not found' => [['options' => ['download' => true], 'found' => null]],
			'lookup fails' => [['options' => ['download' => true], 'found' => 'throw']],
			'empty pdf' => [['options' => ['download' => true, 'publication' => ['title' => 'Nul']], 'render' => 'empty']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testFileMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$service = new DownloadService($this->files(case: $case));
		$objects = $this->objects(case: $case);
		if ($case['options'] === null) {
			$result = $service->createPublicationFile($objects, 'id-1');
		} else {
			$result = $service->createPublicationFile($objects, 'id-1', $case['options']);
		}

		if ($result instanceof JSONResponse) {
			$result = ['status' => $result->getStatus(), 'data' => $result->getData()];
		}

		$actual = ['result' => $result, 'log' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testFileMatchesTheRecording()

	/**
	 * A FileService whose PDF writes, or fails to write, the render file.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return FileService The double.
	 */
	private function files(array $case): FileService {
		$mpdf = $this->createMock(\Mpdf\Mpdf::class);
		$mpdf->method('Output')->willReturnCallback(
			function (string $path, string $destination) use ($case): string {
				$this->log[] = ['output' => $destination, 'inTemp' => str_starts_with($path, sys_get_temp_dir() . '/mpdf/')];
				$render = ($case['render'] ?? 'pdf');
				if ($render === 'pdf') {
					file_put_contents($path, '%PDF-1.4 golden');
				}

				if ($render === 'empty') {
					file_put_contents($path, '');
				}

				return '';
			}
		);

		$files = $this->createMock(FileService::class);
		$files->method('createPdf')->willReturnCallback(
			function (string $template, array $context) use ($mpdf): \Mpdf\Mpdf {
				$this->log[] = ['template' => $template, 'context' => $context];
				return $mpdf;
			}
		);

		return $files;

	}//end files()

	/**
	 * An ObjectService that finds, misses or fails.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ObjectService The double.
	 */
	private function objects(array $case): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($case): ?ObjectEntity {
				$this->log[] = ['find' => $id];
				$found = ($case['found'] ?? null);
				if ($found === 'throw') {
					throw new DoesNotExistException('gone');
				}

				if ($found === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($found);
				$entity->setUuid((string)$found['id']);

				return $entity;
			}
		);

		return $objects;

	}//end objects()
}//end class
