<?php

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for the DownloadService class.
 */
class DownloadServiceTest extends \PHPUnit\Framework\TestCase
{
    private DownloadService $downloadService;
    private FileService&MockObject $fileService;

    protected function setUp(): void
    {
        $this->fileService = $this->createMock(FileService::class);

        $this->downloadService = new DownloadService(
            $this->fileService
        );
    }

    /**
     * Helper to invoke a private method via reflection.
     */
    private function invokePrivateMethod(string $method, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($this->downloadService);
        $method     = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($this->downloadService, $parameters);
    }

    /**
     * Create a mock ObjectService with find returning appropriate entities.
     */
    private function createObjectServiceMock(): ObjectService|MockObject
    {
        return $this->createMock(ObjectService::class);
    }

    /**
     * Create an ObjectEntity from array data.
     */
    private function createObjectEntityFromData(array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        if (isset($data['id'])) {
            $entity->setUuid((string) $data['id']);
        }
        $entity->setObject($data);
        return $entity;
    }

    // -------------------------------------------------------------------------
    // getPublicationData (private)
    // -------------------------------------------------------------------------

    public function testGetPublicationDataSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubData = ['id' => '42', 'title' => 'Test Publication'];
        $entity = $this->createObjectEntityFromData($pubData);

        $objectService->method('find')
            ->with('42')
            ->willReturn($entity);

        $result = $this->invokePrivateMethod('getPublicationData', ['42', $objectService]);
        $this->assertIsArray($result);
        $this->assertSame('42', $result['id']);
    }

    public function testGetPublicationDataNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->with('999')
            ->willThrowException(new DoesNotExistException('Publication not found'));

        $result = $this->invokePrivateMethod('getPublicationData', ['999', $objectService]);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
    }

    // -------------------------------------------------------------------------
    // createPublicationFile
    // -------------------------------------------------------------------------

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
    }

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

        $this->fileService->method('findShare')->willReturn(null);
        $this->fileService->method('createShareLink')
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
    }

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
    }

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
    }

    // -------------------------------------------------------------------------
    // saveFileToNextCloud
    // -------------------------------------------------------------------------

    public function testSaveFileToNextCloudSuccess(): void
    {
        $publication = ['id' => '10', 'title' => 'SaveTest'];

        $this->fileService->expects($this->exactly(2))
            ->method('createFolder');

        $this->fileService->method('getPublicationFolderName')
            ->with('10', 'SaveTest')
            ->willReturn('(10) SaveTest');

        $this->fileService->method('updateFile')->willReturn(true);
        $this->fileService->method('findShare')->willReturn(null);
        $this->fileService->method('createShareLink')
            ->willReturn('https://example.com/index.php/s/sharetoken');

        $result = $this->downloadService->saveFileToNextCloud('test.pdf', $publication);

        $this->assertIsString($result);
        $this->assertStringContainsString('sharetoken', $result);
    }

    public function testSaveFileToNextCloudExistingShare(): void
    {
        $publication = ['id' => '10', 'title' => 'SaveTest'];

        $this->fileService->method('createFolder')->willReturn(true);
        $this->fileService->method('getPublicationFolderName')
            ->willReturn('(10) SaveTest');
        $this->fileService->method('updateFile')->willReturn(true);

        $share = $this->createMock(IShare::class);
        $this->fileService->method('findShare')->willReturn($share);
        $this->fileService->method('getShareLink')
            ->with($share)
            ->willReturn('https://example.com/index.php/s/existingtoken');

        $result = $this->downloadService->saveFileToNextCloud('test.pdf', $publication);

        $this->assertIsString($result);
        $this->assertStringContainsString('existingtoken', $result);
    }

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
    }

    // -------------------------------------------------------------------------
    // createPublicationZip
    // -------------------------------------------------------------------------

    public function testCreatePublicationZipPublicationNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->downloadService->createPublicationZip($objectService, '999');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
    }

    public function testCreatePublicationZipPdfCreationFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity = $this->createObjectEntityFromData(['id' => '1', 'title' => 'ZipTest', 'attachments' => []]);
        $objectService->method('find')
            ->willReturn($entity);

        $this->fileService->method('createPdf')
            ->willThrowException(new \Mpdf\MpdfException('PDF generation failed'));

        $this->expectException(\Mpdf\MpdfException::class);

        $this->downloadService->createPublicationZip($objectService, '1');
    }

    public function testCreatePublicationZipSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity = $this->createObjectEntityFromData(['id' => '1', 'title' => 'ZipPub', 'attachments' => []]);
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
    }

    public function testCreatePublicationZipCreateZipFails(): void
    {
        $objectService = $this->createObjectServiceMock();
        $entity = $this->createObjectEntityFromData(['id' => '2', 'title' => 'FailZip', 'attachments' => []]);
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
        $downloadService->method('createPublicationFile')->willReturn($pdfResponse);

        $this->fileService->method('createZip')
            ->willReturn('failed to create ZIP archive');

        $result = $downloadService->createPublicationZip($objectService, '2');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
    }

    // -------------------------------------------------------------------------
    // publicationAttachments
    // -------------------------------------------------------------------------

    public function testPublicationAttachmentsSuccess(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity = $this->createObjectEntityFromData(['id' => '1', 'attachments' => ['a1', 'a2']]);
        $att1Entity = $this->createObjectEntityFromData(['id' => 'a1', 'title' => 'Att1']);
        $att2Entity = $this->createObjectEntityFromData(['id' => 'a2', 'title' => 'Att2']);

        $objectService->method('find')
            ->willReturnCallback(function ($id) use ($pubEntity, $att1Entity, $att2Entity) {
                if ($id === '1') return $pubEntity;
                if ($id === 'a1') return $att1Entity;
                if ($id === 'a2') return $att2Entity;
                return null;
            });

        $result = $this->downloadService->publicationAttachments('1', $objectService);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testPublicationAttachmentsException(): void
    {
        $objectService = $this->createObjectServiceMock();
        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Publication not found'));

        $result = $this->downloadService->publicationAttachments('1', $objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
    }

    public function testPublicationAttachmentsIntegerId(): void
    {
        $objectService = $this->createObjectServiceMock();
        $pubEntity = $this->createObjectEntityFromData(['id' => '42', 'attachments' => []]);

        $objectService->method('find')
            ->willReturn($pubEntity);

        $result = $this->downloadService->publicationAttachments(42, $objectService);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // prepareZip (private)
    // -------------------------------------------------------------------------

    public function testPrepareZipRequiresFilesystem(): void
    {
        $this->markTestSkipped('prepareZip() relies on filesystem I/O (mkdir, file_get_contents, file_put_contents).');
    }
}
