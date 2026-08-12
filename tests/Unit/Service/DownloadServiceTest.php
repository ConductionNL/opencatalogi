<?php

/**
 * Unit tests for DownloadService.
 *
 * Covers publication metadata PDF rendering (including the real Twig template),
 * option validation, temporary-file cleanup, and attachment enumeration.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
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
	 * Sets up mocks and instantiates DownloadService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fileService = $this->createMock(FileService::class);

		$this->downloadService = new DownloadService(
			$this->fileService
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
			->with('42')
			->willReturn($entity);

		$result = $this->invokePrivateMethod('getPublicationData', ['42', $objectService]);
		$this->assertIsArray($result);
		$this->assertSame('42', $result['id']);

	}//end testGetPublicationDataSuccess()

	/**
	 * Returns 500 JSON response when entity is not found.
	 *
	 * @return void
	 */
	public function testGetPublicationDataNotFound(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with('999')
			->willThrowException(new DoesNotExistException('Publication not found'));

		$result = $this->invokePrivateMethod('getPublicationData', ['999', $objectService]);
		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());

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

		$this->fileService->expects($this->never())->method('updateFile');
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
	 * Returns 500 when the publication cannot be fetched.
	 *
	 * @return void
	 */
	public function testCreatePublicationFileFetchFails(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with('99')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->downloadService->createPublicationFile(
			$objectService,
			'99',
			['download' => true, 'publication' => null]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());

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
	 * Returns all resolved attachment entities.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsSuccess(): void {
		$objectService = $this->createObjectServiceMock();
		$pubEntity = $this->createObjectEntityFromData(['id' => '1', 'attachments' => ['a1', 'a2']]);
		$att1Entity = $this->createObjectEntityFromData(['id' => 'a1', 'title' => 'Att1']);
		$att2Entity = $this->createObjectEntityFromData(['id' => 'a2', 'title' => 'Att2']);

		$objectService->method('find')
			->willReturnCallback(
				function ($id) use ($pubEntity, $att1Entity, $att2Entity) {
					if ($id === '1') {
						return $pubEntity;
					}

					if ($id === 'a1') {
						return $att1Entity;
					}

					if ($id === 'a2') {
						return $att2Entity;
					}

					return null;
				}
			);

		$result = $this->downloadService->publicationAttachments('1', $objectService);

		$this->assertIsArray($result);
		$this->assertCount(2, $result);

	}//end testPublicationAttachmentsSuccess()

	/**
	 * Returns 500 when the publication lookup throws an exception.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsException(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->willThrowException(new DoesNotExistException('Publication not found'));

		$result = $this->downloadService->publicationAttachments('1', $objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());

	}//end testPublicationAttachmentsException()

	/**
	 * Accepts integer IDs as publication identifier.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsIntegerId(): void {
		$objectService = $this->createObjectServiceMock();
		$pubEntity = $this->createObjectEntityFromData(['id' => '42', 'attachments' => []]);

		$objectService->method('find')
			->willReturn($pubEntity);

		$result = $this->downloadService->publicationAttachments(42, $objectService);

		$this->assertIsArray($result);
		$this->assertCount(0, $result);

	}//end testPublicationAttachmentsIntegerId()

	/**
	 * Skips test that requires real filesystem I/O.
	 *
	 * @return void
	 */
	public function testPrepareZipRequiresFilesystem(): void {
		$this->markTestSkipped(
			'prepareZip() relies on filesystem I/O (mkdir, file_get_contents, file_put_contents).'
		);

	}//end testPrepareZipRequiresFilesystem()

	/**
	 * Returns 500 error response when entity lookup throws exception.
	 *
	 * @return void
	 */
	public function testGetPublicationDataReturnsErrorOnException(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->with('null-id')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$result = $this->invokePrivateMethod('getPublicationData', ['null-id', $objectService]);
		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());

	}//end testGetPublicationDataReturnsErrorOnException()

	/**
	 * Returns 500 when the publication entity is null.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsReturnsErrorWhenEntityIsNull(): void {
		$objectService = $this->createObjectServiceMock();
		$objectService->method('find')
			->willReturn(null);

		$result = $this->downloadService->publicationAttachments('null-pub', $objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());

	}//end testPublicationAttachmentsReturnsErrorWhenEntityIsNull()

	/**
	 * Skips attachments whose entities cannot be resolved.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsSkipsNullAttachments(): void {
		$objectService = $this->createObjectServiceMock();
		$pubEntity = $this->createObjectEntityFromData(['id' => '1', 'attachments' => ['a1', 'a2']]);

		$objectService->method('find')
			->willReturnCallback(
				function ($id) use ($pubEntity) {
					if ($id === '1') {
						return $pubEntity;
					}

					return null;
				}
			);

		$result = $this->downloadService->publicationAttachments('1', $objectService);

		$this->assertIsArray($result);
		$this->assertCount(0, $result);

	}//end testPublicationAttachmentsSkipsNullAttachments()

	/**
	 * Returns empty array when publication has no attachments key.
	 *
	 * @return void
	 */
	public function testPublicationAttachmentsNoAttachmentsKey(): void {
		$objectService = $this->createObjectServiceMock();
		$pubEntity = $this->createObjectEntityFromData(['id' => '1']);

		$objectService->method('find')
			->willReturn($pubEntity);

		$result = $this->downloadService->publicationAttachments('1', $objectService);

		$this->assertIsArray($result);
		$this->assertCount(0, $result);

	}//end testPublicationAttachmentsNoAttachmentsKey()

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

	/**
	 * Renders the real publication.html.twig template through the real FileService.
	 *
	 * Every other test in this file mocks `createPdf()`, which is exactly why the
	 * missing template went unnoticed for so long: a Twig LoaderError could never
	 * surface. This test resolves the template for real so its absence fails here.
	 *
	 * @return void
	 */
	public function testRealPublicationTemplateRendersThroughFileService(): void {
		$fileService = new FileService(
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\Files\IRootFolder::class),
			$this->createMock(\OCP\App\IAppManager::class),
			$this->createMock(\Psr\Container\ContainerInterface::class)
		);

		$downloadService = new DownloadService($fileService);
		$objectService = $this->createObjectServiceMock();

		$publication = [
			'id' => 'abc-123',
			'title' => 'Real Template Publication',
			'summary' => 'A short summary.',
			'description' => 'A longer description of the publication.',
			'status' => 'published',
			'attachments' => [['title' => 'Attachment One'], 'plain-attachment-id'],
		];

		$result = $downloadService->createPublicationFile(
			$objectService,
			'abc-123',
			['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);
		$this->assertSame('Real Template Publication.pdf', $result['filename']);
		$this->assertStringStartsWith('%PDF', $result['content']);

	}//end testRealPublicationTemplateRendersThroughFileService()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
