<?php

/**
 * Unit tests for DownloadService.
 *
 * Covers publication PDF generation, saving to NextCloud via the OR shares leaf,
 * ZIP creation, and attachment enumeration.
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
class DownloadServiceTest extends \PHPUnit\Framework\TestCase
{

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
    protected function setUp(): void
    {
        $this->fileService = $this->createMock(FileService::class);

        $this->downloadService = new DownloadService(
            $this->fileService
        );

    }//end setUp()

    /**
     * Invokes a private method via reflection.
     *
     * @param string $method     The method name.
     * @param array  $parameters Parameters to pass.
     *
     * @return mixed
     */
    private function invokePrivateMethod(string $method, array $parameters=[]): mixed
    {
        $reflection = new ReflectionClass($this->downloadService);
        $method     = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($this->downloadService, $parameters);

    }//end invokePrivateMethod()

    /**
     * Creates a mock ObjectService.
     *
     * @return ObjectService&MockObject
     */
    private function createObjectServiceMock(): ObjectService&MockObject
    {
        return $this->createMock(ObjectService::class);

    }//end createObjectServiceMock()

    /**
     * Creates an ObjectEntity populated from an array.
     *
     * @param array $data The data to populate the entity with.
     *
     * @return ObjectEntity
     */
    private function createObjectEntityFromData(array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        if (isset($data['id']) === true) {
            $entity->setUuid((string) $data['id']);
        }

        $entity->setObject($data);
        return $entity;

    }//end createObjectEntityFromData()

    /**
     * Returns deserialized data when the entity is found.
     *
     * @return void
     */
    public function testGetPublicationDataSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubData       = ['id' => '42', 'title' => 'Test Publication'];
        $entity        = $this->createObjectEntityFromData($pubData);

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
    public function testGetPublicationDataNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->with('999')
            ->willThrowException(new DoesNotExistException('Publication not found'));

        $result = $this->invokePrivateMethod('getPublicationData', ['999', $objectService]);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testGetPublicationDataNotFound()

    /**
     * Returns 500 when both download and saveToNextCloud options are false.
     *
     * @return void
     */
    public function testCreatePublicationFileBothOptionsFalse(): void
    {
        $objectService = $this->createObjectServiceMock();

        $result = $this->downloadService->createPublicationFile(
            $objectService,
            '1',
            ['download' => false, 'saveToNextCloud' => false, 'publication' => null]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testCreatePublicationFileBothOptionsFalse()

    /**
     * Saves to NextCloud and returns download URL when publication is provided.
     *
     * @return void
     */
    public function testCreatePublicationFileWithPublicationProvided(): void
    {
        $objectService = $this->createObjectServiceMock();

        $publication = ['id' => '1', 'title' => 'MyPub', 'description' => 'A description'];

        $mpdf = $this->createMock(\Mpdf\Mpdf::class);
        $this->fileService->expects($this->once())
            ->method('createPdf')
            ->with('publication.html.twig', ['publication' => $publication])
            ->willReturn($mpdf);

        $this->fileService->method('createFolder')->willReturn(true);
        $this->fileService->method('getPublicationFolderName')
            ->with('1', 'MyPub')
            ->willReturn('(1) MyPub');
        $this->fileService->method('updateFile')->willReturn(true);

        $this->fileService->method('createPublicShareLink')
            ->willReturn('https://example.com/index.php/s/token123');

        $result = $this->downloadService->createPublicationFile(
            $objectService,
            '1',
            ['download' => false, 'saveToNextCloud' => true, 'publication' => $publication]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('/download', $data['downloadUrl']);
        $this->assertSame('MyPub.pdf', $data['filename']);

    }//end testCreatePublicationFileWithPublicationProvided()

    /**
     * Returns 500 when the publication cannot be fetched.
     *
     * @return void
     */
    public function testCreatePublicationFileFetchFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->with('99')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->downloadService->createPublicationFile(
            $objectService,
            '99',
            ['download' => true, 'saveToNextCloud' => true, 'publication' => null]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testCreatePublicationFileFetchFails()

    /**
     * Sends file to browser download when saveToNextCloud is false.
     *
     * @return void
     */
    public function testCreatePublicationFileDownloadOnlySaveToNextCloudFalse(): void
    {
        $objectService = $this->createObjectServiceMock();
        $publication   = ['id' => '5', 'title' => 'DownloadOnly'];

        $mpdf = $this->createMock(\Mpdf\Mpdf::class);
        $mpdf->expects($this->once())
            ->method('Output')
            ->with('DownloadOnly.pdf', \Mpdf\Output\Destination::DOWNLOAD);

        $this->fileService->method('createPdf')->willReturn($mpdf);

        $result = $this->downloadService->createPublicationFile(
            $objectService,
            '5',
            ['download' => true, 'saveToNextCloud' => false, 'publication' => $publication]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertEmpty($result->getData());

    }//end testCreatePublicationFileDownloadOnlySaveToNextCloudFalse()

    /**
     * Stores a file and returns a share link URL.
     *
     * @return void
     */
    public function testSaveFileToNextCloudSuccess(): void
    {
        $publication = ['id' => '10', 'title' => 'SaveTest'];

        $this->fileService->expects($this->exactly(2))
            ->method('createFolder');

        $this->fileService->method('getPublicationFolderName')
            ->with('10', 'SaveTest')
            ->willReturn('(10) SaveTest');

        $this->fileService->method('updateFile')->willReturn(true);
        $this->fileService->method('createPublicShareLink')
            ->willReturn('https://example.com/index.php/s/sharetoken');

        $result = $this->downloadService->saveFileToNextCloud('test.pdf', $publication);

        $this->assertIsString($result);
        $this->assertStringContainsString('sharetoken', $result);

    }//end testSaveFileToNextCloudSuccess()

    /**
     * Obtains share URL via the OR shares leaf (ADR-022 / FIL-005).
     *
     * @return void
     */
    public function testSaveFileToNextCloudShareViaLeaf(): void
    {
        $publication = ['id' => '10', 'title' => 'SaveTest'];

        $this->fileService->method('createFolder')->willReturn(true);
        $this->fileService->method('getPublicationFolderName')
            ->willReturn('(10) SaveTest');
        $this->fileService->method('updateFile')->willReturn(true);

        $this->fileService->method('createPublicShareLink')
            ->willReturn('https://example.com/index.php/s/leaftoken');

        $result = $this->downloadService->saveFileToNextCloud('test.pdf', $publication);

        $this->assertIsString($result);
        $this->assertStringContainsString('leaftoken', $result);

    }//end testSaveFileToNextCloudShareViaLeaf()

    /**
     * Returns 500 when file creation in NextCloud fails.
     *
     * @return void
     */
    public function testSaveFileToNextCloudFileCreationFails(): void
    {
        $publication = ['id' => '10', 'title' => 'FailTest'];

        $this->fileService->method('createFolder')->willReturn(true);
        $this->fileService->method('getPublicationFolderName')
            ->willReturn('(10) FailTest');
        $this->fileService->method('updateFile')->willReturn(false);

        $result = $this->downloadService->saveFileToNextCloud('test.pdf', $publication);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testSaveFileToNextCloudFileCreationFails()

    /**
     * Returns 500 when the publication is not found.
     *
     * @return void
     */
    public function testCreatePublicationZipPublicationNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->downloadService->createPublicationZip($objectService, '999');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testCreatePublicationZipPublicationNotFound()

    /**
     * Propagates MpdfException when PDF creation fails.
     *
     * @return void
     */
    public function testCreatePublicationZipPdfCreationFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity        = $this->createObjectEntityFromData(
            ['id' => '1', 'title' => 'ZipTest', 'attachments' => []]
        );
        $objectService->method('find')
            ->willReturn($entity);

        $this->fileService->method('createPdf')
            ->willThrowException(new \Mpdf\MpdfException('PDF generation failed'));

        $this->expectException(\Mpdf\MpdfException::class);

        $this->downloadService->createPublicationZip($objectService, '1');

    }//end testCreatePublicationZipPdfCreationFails()

    /**
     * Returns 200 on successful ZIP creation.
     *
     * @return void
     */
    public function testCreatePublicationZipSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity        = $this->createObjectEntityFromData(
            ['id' => '1', 'title' => 'ZipPub', 'attachments' => []]
        );
        $objectService->method('find')
            ->willReturn($entity);

        $downloadService = $this->getMockBuilder(DownloadService::class)
            ->setConstructorArgs([$this->fileService])
            ->onlyMethods(['createPublicationFile'])
            ->getMock();

        $pdfResponse = new JSONResponse(
            ['downloadUrl' => 'https://example.com/dl', 'filename' => 'ZipPub.pdf'],
            200
        );

        $downloadService->method('createPublicationFile')
            ->willReturn($pdfResponse);

        $this->fileService->method('createZip')->willReturn(null);
        $this->fileService->method('downloadZip');

        $result = $downloadService->createPublicationZip($objectService, '1');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());

    }//end testCreatePublicationZipSuccess()

    /**
     * Returns 500 when ZIP archive creation fails.
     *
     * @return void
     */
    public function testCreatePublicationZipCreateZipFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity        = $this->createObjectEntityFromData(
            ['id' => '2', 'title' => 'FailZip', 'attachments' => []]
        );
        $objectService->method('find')
            ->willReturn($entity);

        $downloadService = $this->getMockBuilder(DownloadService::class)
            ->setConstructorArgs([$this->fileService])
            ->onlyMethods(['createPublicationFile'])
            ->getMock();

        $pdfResponse = new JSONResponse(
            ['downloadUrl' => 'https://example.com/dl', 'filename' => 'FailZip.pdf'],
            200
        );
        $downloadService->method('createPublicationFile')
            ->willReturn($pdfResponse);

        $this->fileService->method('createZip')
            ->willReturn('failed to create ZIP archive');

        $result = $downloadService->createPublicationZip($objectService, '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testCreatePublicationZipCreateZipFails()

    /**
     * Returns all resolved attachment entities.
     *
     * @return void
     */
    public function testPublicationAttachmentsSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity     = $this->createObjectEntityFromData(['id' => '1', 'attachments' => ['a1', 'a2']]);
        $att1Entity    = $this->createObjectEntityFromData(['id' => 'a1', 'title' => 'Att1']);
        $att2Entity    = $this->createObjectEntityFromData(['id' => 'a2', 'title' => 'Att2']);

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
    public function testPublicationAttachmentsException(): void
    {
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
    public function testPublicationAttachmentsIntegerId(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity     = $this->createObjectEntityFromData(['id' => '42', 'attachments' => []]);

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
    public function testPrepareZipRequiresFilesystem(): void
    {
        $this->markTestSkipped(
            'prepareZip() relies on filesystem I/O (mkdir, file_get_contents, file_put_contents).'
        );

    }//end testPrepareZipRequiresFilesystem()

    /**
     * Returns 500 error response when entity lookup throws exception.
     *
     * @return void
     */
    public function testGetPublicationDataReturnsErrorOnException(): void
    {
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
    public function testPublicationAttachmentsReturnsErrorWhenEntityIsNull(): void
    {
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
    public function testPublicationAttachmentsSkipsNullAttachments(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity     = $this->createObjectEntityFromData(['id' => '1', 'attachments' => ['a1', 'a2']]);

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
    public function testPublicationAttachmentsNoAttachmentsKey(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity     = $this->createObjectEntityFromData(['id' => '1']);

        $objectService->method('find')
            ->willReturn($pubEntity);

        $result = $this->downloadService->publicationAttachments('1', $objectService);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);

    }//end testPublicationAttachmentsNoAttachmentsKey()

    /**
     * Returns 500 when saving publication file to NextCloud fails.
     *
     * @return void
     */
    public function testCreatePublicationFileSaveToNextCloudFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $publication   = ['id' => '1', 'title' => 'FailSave'];

        $mpdf = $this->createMock(\Mpdf\Mpdf::class);
        $this->fileService->method('createPdf')->willReturn($mpdf);
        $this->fileService->method('createFolder')->willReturn(true);
        $this->fileService->method('getPublicationFolderName')
            ->willReturn('(1) FailSave');
        $this->fileService->method('updateFile')->willReturn(false);

        $result = $this->downloadService->createPublicationFile(
            $objectService,
            '1',
            ['download' => false, 'saveToNextCloud' => true, 'publication' => $publication]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());

    }//end testCreatePublicationFileSaveToNextCloudFails()

    // phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
