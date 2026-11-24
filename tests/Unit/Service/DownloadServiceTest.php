<?php

/**
 * Unit tests for DownloadService.
 *
 * Covers publication metadata PDF rendering, option validation and temporary-file
 * cleanup. FileService and ObjectService are mocks in every test here; the render of
 * the real Twig template lives in DownloadTemplateRenderTest.
 *
 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#4.4
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use OCA\OpenCatalogi\Tests\Support\LegacyObjectArgs;

/**
 * Unit tests for the DownloadService class.
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 */
class DownloadServiceTest extends \PHPUnit\Framework\TestCase {

	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Service under test.
	 *
	 * @var DownloadService
	 */
	private DownloadService $downloadService;

	/**
	 * FileService mock.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * An IL10N that answers the English text unchanged.
	 *
	 * @return \OCP\IL10N The translator.
	 */
	private function passthroughL10n(): \OCP\IL10N {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}//end passthroughL10n()

	/**
	 * The error messages are fixed texts in the caller's language (download-service task 6).
	 *
	 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md
	 *
	 * @return void
	 */
	public function testTheErrorMessagesAreTranslated(): void {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnMap([
			['At least one output option must be enabled; "download" was false', [], 'Er moet minstens één uitvoeroptie aan staan; "download" stond uit'],
			['Publication not found', [], 'Publicatie niet gevonden'],
		]);
		$service = new DownloadService($this->fileService, $l10n);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);

		$refused = $service->createPublicationFile(objectService: $objectService, id: 'p1', options: ['download' => false]);
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('Er moet minstens één uitvoeroptie aan staan; "download" stond uit', $refused->getData()['error']);

		$missing = $service->createPublicationFile(objectService: $objectService, id: 'p1');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame('Publicatie niet gevonden', $missing->getData()['error']);
	}//end testTheErrorMessagesAreTranslated()

	/**
	 * Sets up mocks and instantiates DownloadService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fileService = $this->createMock(FileService::class);

		$this->downloadService = new DownloadService(
			$this->fileService,
			$this->passthroughL10n()
		);

	}//end setUp()

	/**
	 * Invokes a private method via reflection.
	 *
	 * @param string $method The method name.
	 * @param array $parameters Parameters to pass.
	 *
	 * @return mixed
	 */
	private function invokePrivateMethod(string $method, array $parameters = []): mixed {
		$reflection = new ReflectionClass($this->downloadService);
		$method = $reflection->getMethod($method);
		$method->setAccessible(true);

		return $method->invokeArgs($this->downloadService, $parameters);
	}//end invokePrivateMethod()

	/**
	 * Creates a mock ObjectService.
	 *
	 * @return ObjectService&MockObject
	 */
	private function createObjectServiceMock(): ObjectService&MockObject {
		return $this->createMock(ObjectService::class);
	}//end createObjectServiceMock()

	/**
	 * Creates an ObjectEntity populated from an array.
	 *
	 * @param array $data The data to populate the entity with.
	 *
	 * @return ObjectEntity
	 */
	private function createObjectEntityFromData(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		if (isset($data['id']) === true) {
			$entity->setUuid((string)$data['id']);
		}

		$entity->setObject($data);
		return $entity;
	}//end createObjectEntityFromData()

	/**
	 * Returns deserialized data when the entity is found.
	 *
	 * @return void
	 */
	public function testGetPublicationDataSuccess(): void {
		$objectService = $this->createObjectServiceMock();
		$pubData = ['id' => '42', 'title' => 'Test Publication'];
		$entity = $this->createObjectEntityFromData($pubData);

		$objectService->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => '42',
			]))
			->willReturn($entity);

		$result = $this->invokePrivateMethod('getPublicationData', ['42', $objectService]);
		$this->assertIsArray($result);
		$this->assertSame('42', $result['id']);

	}//end testGetPublicationDataSuccess()

	/**
	 * Returns a 404 JSON response when the store throws DoesNotExistException (DWN-OR-005).
	 *
	 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#7.2
	 *
	 * @return void
	 */
	public function testGetPublicationDataNotFound(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => '999',
			]))
			->willThrowException(new DoesNotExistException('Publication not found'));

		$result = $this->invokePrivateMethod('getPublicationData', ['999', $objectService]);
		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());

	}//end testGetPublicationDataNotFound()

	/**
	 * Returns 400 without rendering anything when no output option is enabled (DWN-OR-004).
	 *
	 * @return void
	 */
	public function testCreatePublicationFileNoOutputOptionEnabled(): void {
		$objectService = $this->createObjectServiceMock();

		// No file content may be generated when the guard trips.
		$this->fileService->expects($this->never())->method('createPdf');

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'1',
			['download' => false, 'publication' => null]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());

	}//end testCreatePublicationFileNoOutputOptionEnabled()

	/**
	 * Returns the rendered PDF as an in-memory artefact when a publication is provided.
	 *
	 * @return void
	 */
	public function testCreatePublicationFileWithPublicationProvided(): void {
		$objectService = $this->createObjectServiceMock();

		$publication = ['id' => '1', 'title' => 'MyPub', 'description' => 'A description'];

		$mpdf = $this->createMock(\Mpdf\Mpdf::class);
		$mpdf->expects($this->once())
			->method('Output')
			->willReturnCallback(
				function (string $path, string $destination) {
					$this->assertSame(\Mpdf\Output\Destination::FILE, $destination);
					file_put_contents($path, '%PDF-1.4 rendered');
					return '';
				}
			);

		$this->fileService->expects($this->once())
			->method('createPdf')
			->with('publication.html.twig', ['publication' => $publication])
			->willReturn($mpdf);

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'1',
			['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);
		$this->assertSame('MyPub.pdf', $result['filename']);
		$this->assertSame('%PDF-1.4 rendered', $result['content']);

	}//end testCreatePublicationFileWithPublicationProvided()

	/**
	 * Deletes the temporary render file before returning (DWN-OR-003).
	 *
	 * @return void
	 */
	public function testCreatePublicationFileRemovesTemporaryFile(): void {
		$objectService = $this->createObjectServiceMock();
		$publication = ['id' => '2', 'title' => 'TempCleanup'];

		$capturedPath = null;

		$mpdf = $this->createMock(\Mpdf\Mpdf::class);
		$mpdf->method('Output')
			->willReturnCallback(
				function (string $path, string $destination) use (&$capturedPath) {
					$capturedPath = $path;
					file_put_contents($path, '%PDF-1.4 tempfile');
					return '';
				}
			);

		$this->fileService->method('createPdf')->willReturn($mpdf);

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'2',
			['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);
		$this->assertNotNull($capturedPath);
		$this->assertFileDoesNotExist($capturedPath);

	}//end testCreatePublicationFileRemovesTemporaryFile()

	/**
	 * Never writes the rendered PDF to Nextcloud user storage (DWN-OR-003).
	 *
	 * @return void
	 */
	public function testCreatePublicationFileNeverWritesToNextcloudStorage(): void {
		$objectService = $this->createObjectServiceMock();
		$publication = ['id' => '3', 'title' => 'NoStorage'];

		$mpdf = $this->createMock(\Mpdf\Mpdf::class);
		$mpdf->method('Output')
			->willReturnCallback(
				function (string $path, string $destination) {
					file_put_contents($path, '%PDF-1.4 nostorage');
					return '';
				}
			);

		$this->fileService->method('createPdf')->willReturn($mpdf);

		// `updateFile` was listed here too until it was removed from FileService as
		// orphaned write capability (gate-57). Its absence is a STRONGER guarantee
		// than this assertion was: a method that does not exist cannot be called,
		// whereas `expects($this->never())` only proves this one path avoids it.
		// The requirement is still enforced by the two below, which are the
		// remaining ways FileService can reach Nextcloud user storage.
		//
		// ⚠️ Keeping the line would not have failed loudly for the right reason —
		// PHPUnit raises MethodCannotBeConfiguredException, which reads like a
		// mock-setup mistake rather than "the method is gone".
		$this->fileService->expects($this->never())->method('createFolder');
		$this->fileService->expects($this->never())->method('createPublicShareLink');

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'3',
			['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);

	}//end testCreatePublicationFileNeverWritesToNextcloudStorage()

	/**
	 * Returns 404 when the store throws DoesNotExistException for the publication (DWN-OR-005).
	 *
	 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#7.2
	 *
	 * @return void
	 */
	public function testCreatePublicationFileFetchFails(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => '99',
			]))
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'99',
			['download' => true, 'publication' => null]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());

	}//end testCreatePublicationFileFetchFails()

	/**
	 * Returns 404 when the publication cannot be resolved (DWN-OR-005).
	 *
	 * @return void
	 */
	public function testCreatePublicationFilePublicationNotFound(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')->willReturn(null);

		$this->fileService->expects($this->never())->method('createPdf');

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'404',
			['download' => true, 'publication' => null]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());

	}//end testCreatePublicationFilePublicationNotFound()

	/**
	 * Asserts that saveFileToNextCloud no longer exists.
	 *
	 * DWN-OR-003 forbids the download service from saving the generated PDF to
	 * Nextcloud user storage, and DWN-002 / DWN-003 are recorded as REMOVED
	 * requirements. This test documents the removal so a regression is caught.
	 *
	 * @return void
	 */
	public function testSaveFileToNextCloudMethodDoesNotExist(): void {
		$this->assertFalse(
			method_exists($this->downloadService, 'saveFileToNextCloud'),
			'saveFileToNextCloud writes the metadata PDF to Nextcloud user storage, '
			. 'which DWN-OR-003 forbids; it must not be re-introduced.'
		);

	}//end testSaveFileToNextCloudMethodDoesNotExist()

	/**
	 * Asserts that createPublicationZip no longer exists (removed in wave-3 fix C5).
	 *
	 * The ZIP-creation path was eliminated; callers should use createPublicationFile
	 * directly. These tests document the removal so regressions are caught.
	 *
	 * @return void
	 */
	public function testCreatePublicationZipMethodDoesNotExist(): void {
		$this->assertFalse(
			method_exists($this->downloadService, 'createPublicationZip'),
			'createPublicationZip was removed in wave-3 (C5) and must not be re-introduced.'
		);

	}//end testCreatePublicationZipMethodDoesNotExist()

	/**
	 * The publication-attachments lookup is gone: nothing called it, and the
	 * download takes its files from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#2.1
	 */
	public function testPublicationAttachmentsMethodDoesNotExist(): void {
		$this->assertFalse(
			method_exists($this->downloadService, 'publicationAttachments'),
			'publicationAttachments had no caller and was removed; attachments come from OpenRegister.'
		);

	}//end testPublicationAttachmentsMethodDoesNotExist()

	/**
	 * Returns a 500 carrying the message when the lookup itself fails (more than one match).
	 *
	 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#7.2
	 *
	 * @return void
	 */
	public function testGetPublicationDataReturnsErrorOnException(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => 'null-id',
			]))
			->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('Two found'));

		$result = $this->invokePrivateMethod('getPublicationData', ['null-id', $objectService]);
		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame([500, ['error' => 'Two found']], [$result->getStatus(), $result->getData()]);

	}//end testGetPublicationDataReturnsErrorOnException()

	/**
	 * Falls back to a usable entry name when the publication carries no title.
	 *
	 * @return void
	 */
	public function testCreatePublicationFileWithoutTitle(): void {
		$objectService = $this->createObjectServiceMock();
		$publication = ['id' => '7'];

		$mpdf = $this->createMock(\Mpdf\Mpdf::class);
		$mpdf->method('Output')
			->willReturnCallback(
				function (string $path, string $destination) {
					file_put_contents($path, '%PDF-1.4 untitled');
					return '';
				}
			);

		$this->fileService->method('createPdf')->willReturn($mpdf);

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'7',
			['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);
		$this->assertSame('publication.pdf', $result['filename']);

	}//end testCreatePublicationFileWithoutTitle()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
