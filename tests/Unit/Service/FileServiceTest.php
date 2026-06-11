<?php

/**
 * Unit tests for FileService.
 *
 * Covers: share link delegation to the OpenRegister leaf (ADR-022/FIL-005/006/007),
 * file upload/update/delete operations, folder creation, and ZIP creation.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-3
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-4
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenCatalogi\Service\FileService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Unit tests for the FileService class.
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-3
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-4
 */
class FileServiceTest extends \PHPUnit\Framework\TestCase
{

    // phpcs:disable CustomSniffs.Functions.NamedParameters

    /**
     * Service under test.
     *
     * @var FileService
     */
    private FileService $fileService;

    /**
     * User session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Root folder mock.
     *
     * @var IRootFolder&MockObject
     */
    private IRootFolder&MockObject $rootFolder;

    /**
     * App manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * DI container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Sets up mocks and instantiates FileService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->rootFolder  = $this->createMock(IRootFolder::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->container   = $this->createMock(ContainerInterface::class);

        $this->fileService = new FileService(
            $this->userSession,
            $this->logger,
            $this->rootFolder,
            $this->appManager,
            $this->container
        );

    }//end setUp()

    /**
     * Invokes a private method via reflection.
     *
     * @param string $method     The method name.
     * @param array  $parameters The parameters to pass.
     *
     * @return mixed The return value of the method.
     */
    private function invokePrivateMethod(string $method, array $parameters=[]): mixed
    {
        $reflection = new ReflectionClass($this->fileService);
        $method     = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($this->fileService, $parameters);

    }//end invokePrivateMethod()

    /**
     * Sets up a mock user and user folder.
     *
     * @param string $userId The user ID to return.
     *
     * @return Folder&MockObject The mocked user folder.
     */
    private function setupUserFolder(string $userId='admin'): Folder&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with($userId)->willReturn($userFolder);

        return $userFolder;

    }//end setupUserFolder()

    /**
     * Wire $userFolder->get($path)->getPath() to resolve to the given absolute path.
     *
     * createPublicShareLink() (and the upload/enrich paths that call it) fetch the node
     * via $userFolder->get($relativePath) and then read $node->getPath(). Tests that
     * exercise the share-link path must stub get() to return such a node; this helper
     * centralises that wiring. Call it INSTEAD of stubbing get() yourself.
     *
     * @param Folder&MockObject $userFolder   The user-folder mock from setupUserFolder().
     * @param string            $absolutePath The absolute path the node should report.
     *
     * @return void
     */
    private function wireShareNode(Folder $userFolder, string $absolutePath): void
    {
        $node = $this->createMock(\OCP\Files\Node::class);
        $node->method('getPath')->willReturn($absolutePath);
        $userFolder->method('get')->willReturn($node);

    }//end wireShareNode()

    /**
     * Sets up the OR FileService mock via the DI container.
     *
     * @param string $shareUrl The share URL the OR service should return.
     *
     * @return \OCA\OpenRegister\Service\FileService&MockObject
     */
    private function setupOrFileService(
        string $shareUrl='https://example.com/index.php/s/sharetoken'
    ): \OCA\OpenRegister\Service\FileService&MockObject {
        $orFileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\FileService')
            ->willReturn($orFileService);
        if ($shareUrl !== '') {
            $orFileService->method('createShareLink')->willReturn($shareUrl);
        }

        return $orFileService;

    }//end setupOrFileService()

    /**
     * Verifies folder name is formatted as "(id) title".
     *
     * @return void
     */
    public function testGetPublicationFolderNameFormat(): void
    {
        $result = $this->fileService->getPublicationFolderName('123', 'My Publication');
        $this->assertSame('(123) My Publication', $result);

    }//end testGetPublicationFolderNameFormat()

    /**
     * Verifies folder name format when title is empty.
     *
     * @return void
     */
    public function testGetPublicationFolderNameEmptyTitle(): void
    {
        $result = $this->fileService->getPublicationFolderName('42', '');
        $this->assertSame('(42) ', $result);

    }//end testGetPublicationFolderNameEmptyTitle()

    /**
     * Delegates to OR leaf and returns share URL.
     *
     * @return void
     */
    public function testCreatePublicShareLinkSuccess(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $this->wireShareNode($userFolder, '/admin/files');

        $this->setupOrFileService('https://example.com/index.php/s/abc123');

        $result = $this->fileService->createPublicShareLink('Publicaties/folder/file.pdf');
        $this->assertSame('https://example.com/index.php/s/abc123', $result);

    }//end testCreatePublicShareLinkSuccess()

    /**
     * Trims leading/trailing slashes before calling OR.
     *
     * @return void
     */
    public function testCreatePublicShareLinkTrimsLeadingSlash(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        // The trimmed relative path resolves to this absolute node path; the leaf is
        // then asked to share that absolute path.
        $this->wireShareNode($userFolder, '/admin/files/Publicaties/folder/file.pdf');

        $orFileService = $this->setupOrFileService();
        $orFileService->expects($this->once())
            ->method('createShareLink')
            ->with('/admin/files/Publicaties/folder/file.pdf')
            ->willReturn('https://example.com/index.php/s/token');

        $this->fileService->createPublicShareLink('/Publicaties/folder/file.pdf/');

    }//end testCreatePublicShareLinkTrimsLeadingSlash()

    /**
     * Returns empty string when OR is not installed.
     *
     * @return void
     */
    public function testCreatePublicShareLinkOrUnavailableReturnsEmpty(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $this->wireShareNode($userFolder, '/admin/files/file.pdf');

        $this->appManager->method('getInstalledApps')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Sharing integration required'));

        $result = $this->fileService->createPublicShareLink('file.pdf');
        $this->assertSame('', $result);

    }//end testCreatePublicShareLinkOrUnavailableReturnsEmpty()

    /**
     * Returns error string when getUserFolder throws NotPermittedException.
     *
     * @return void
     */
    public function testCreatePublicShareLinkNotPermittedReturnsErrorString(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->rootFolder->method('getUserFolder')
            ->willThrowException(new NotPermittedException());

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains("Can't create share link"));

        $result = $this->fileService->createPublicShareLink('file.pdf');
        $this->assertStringContainsString("couldn't be found", $result);

    }//end testCreatePublicShareLinkNotPermittedReturnsErrorString()

    /**
     * Uses Guest user ID when no authenticated user exists.
     *
     * @return void
     */
    public function testCreatePublicShareLinkGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);
        $this->wireShareNode($userFolder, '/Guest/files/file.pdf');

        $this->setupOrFileService('https://example.com/index.php/s/guest-token');

        $result = $this->fileService->createPublicShareLink('file.pdf');
        $this->assertSame('https://example.com/index.php/s/guest-token', $result);

    }//end testCreatePublicShareLinkGuestUser()

    /**
     * Successfully uploads a file and returns enriched data array.
     *
     * @return void
     */
    public function testHandleFileSuccessfulUpload(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $userFolder->method('getPath')->willReturn('/admin/files');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'file content');

        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')
            ->with('_file')
            ->willReturn(
                [
                    'name'     => 'document.pdf',
                    'tmp_name' => $tmpFile,
                    'type'     => 'application/pdf',
                    'size'     => 12345,
                    'error'    => UPLOAD_ERR_OK,
                ]
            );
        $request->method('getHeader')
            ->willReturnMap(
                [
                    ['Publication-Id', '42'],
                    ['Publication-Title', 'Test Publication'],
                ]
            );

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        // createPublicShareLink() reads $node->getPath() on the node returned by get().
        $file->method('getPath')->willReturn('/admin/files/Publicaties/Test Publication/document.pdf');

        $getCallIndex = 0;
        $userFolder->method('get')->willReturnCallback(
            function (string $path) use ($userFolder, $file, &$getCallIndex) {
                $getCallIndex++;
                if ($getCallIndex <= 3) {
                    return $userFolder;
                }

                if ($getCallIndex === 4) {
                    throw new NotFoundException();
                }

                return $file;
            }
        );

        $userFolder->method('newFile')->willReturn($file);

        $this->setupOrFileService('https://example.com/index.php/s/sharetoken');

        $result = $this->fileService->handleFile($request, []);

        $this->assertIsArray($result);
        $this->assertSame('application/pdf', $result['type']);
        $this->assertSame(12345, $result['size']);
        $this->assertSame('document', $result['title']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertStringContainsString('/index.php/s/sharetoken', $result['accessUrl']);

        @unlink($tmpFile);

    }//end testHandleFileSuccessfulUpload()

    /**
     * Returns 400 when the file already exists in NextCloud.
     *
     * @return void
     */
    public function testHandleFileUploadFails(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'content');

        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')
            ->with('_file')
            ->willReturn(
                [
                    'name'     => 'document.pdf',
                    'tmp_name' => $tmpFile,
                    'type'     => 'application/pdf',
                    'size'     => 100,
                    'error'    => UPLOAD_ERR_OK,
                ]
            );
        $request->method('getHeader')
            ->willReturnMap(
                [
                    ['Publication-Id', '1'],
                    ['Publication-Title', 'Pub'],
                ]
            );

        $userFolder->method('get')->willReturn($userFolder);
        $userFolder->method('newFile')->willReturn($this->createMock(File::class));

        $result = $this->fileService->handleFile($request, []);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

        @unlink($tmpFile);

    }//end testHandleFileUploadFails()

    /**
     * Returns 400 when no file is provided in the request.
     *
     * @return void
     */
    public function testHandleFileWithoutFile(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([]);

        $result = $this->fileService->handleFile($request, []);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testHandleFileWithoutFile()

    /**
     * Returns 400 when the upload had a PHP error code.
     *
     * @return void
     */
    public function testHandleFileUploadError(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')
            ->with('_file')
            ->willReturn(
                [
                    'name'     => 'bad.pdf',
                    'tmp_name' => '/tmp/phpXXX',
                    'type'     => 'application/pdf',
                    'size'     => 100,
                    'error'    => UPLOAD_ERR_INI_SIZE,
                ]
            );

        $result = $this->fileService->handleFile($request, []);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testHandleFileUploadError()

    /**
     * Returns error response when no file is uploaded.
     *
     * @return void
     */
    public function testCheckUploadedFileNoFile(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([]);

        $result = $this->invokePrivateMethod('checkUploadedFile', [$request]);
        $this->assertInstanceOf(JSONResponse::class, $result);

    }//end testCheckUploadedFileNoFile()

    /**
     * Returns error response when upload has a PHP error code.
     *
     * @return void
     */
    public function testCheckUploadedFileError(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')
            ->willReturn(
                [
                    'name'     => 'file.pdf',
                    'tmp_name' => '/tmp/phpXYZ',
                    'type'     => 'application/pdf',
                    'size'     => 100,
                    'error'    => UPLOAD_ERR_PARTIAL,
                ]
            );

        $result = $this->invokePrivateMethod('checkUploadedFile', [$request]);
        $this->assertInstanceOf(JSONResponse::class, $result);

    }//end testCheckUploadedFileError()

    /**
     * Returns the uploaded file array when the file is valid.
     *
     * @return void
     */
    public function testCheckUploadedFileSuccess(): void
    {
        $request      = $this->createMock(IRequest::class);
        $uploadedFile = [
            'name'     => 'file.pdf',
            'tmp_name' => '/tmp/phpOK',
            'type'     => 'application/pdf',
            'size'     => 500,
            'error'    => UPLOAD_ERR_OK,
        ];
        $request->method('getUploadedFile')->willReturn($uploadedFile);

        $result = $this->invokePrivateMethod('checkUploadedFile', [$request]);
        $this->assertIsArray($result);
        $this->assertSame('file.pdf', $result['name']);

    }//end testCheckUploadedFileSuccess()

    /**
     * Creates a new folder and returns true.
     *
     * @return void
     */
    public function testCreateFolderNewFolder(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->with('NewFolder')
            ->willThrowException(new NotFoundException());

        $userFolder->expects($this->once())
            ->method('newFolder')
            ->with('NewFolder');

        $result = $this->fileService->createFolder('NewFolder');
        $this->assertTrue($result);

    }//end testCreateFolderNewFolder()

    /**
     * Returns false and logs info when folder already exists.
     *
     * @return void
     */
    public function testCreateFolderExistingFolder(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $folder = $this->createMock(Folder::class);
        $userFolder->method('get')->with('ExistingFolder')->willReturn($folder);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already exits'));

        $result = $this->fileService->createFolder('ExistingFolder');
        $this->assertFalse($result);

    }//end testCreateFolderExistingFolder()

    /**
     * Throws Exception when folder creation is not permitted.
     *
     * @return void
     */
    public function testCreateFolderNotPermitted(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());
        $userFolder->method('newFolder')
            ->willThrowException(new NotPermittedException());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t create folder/");

        $this->fileService->createFolder('Restricted');

    }//end testCreateFolderNotPermitted()

    /**
     * Trims leading/trailing slashes from folder path.
     *
     * @return void
     */
    public function testCreateFolderTrimsSlashes(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->expects($this->once())
            ->method('get')
            ->with('trimmed/folder')
            ->willThrowException(new NotFoundException());

        $userFolder->expects($this->once())
            ->method('newFolder')
            ->with('trimmed/folder');

        $this->fileService->createFolder('/trimmed/folder/');

    }//end testCreateFolderTrimsSlashes()

    /**
     * Enriches data with file metadata and share URL.
     *
     * @return void
     */
    public function testAddFileInfoToDataEnrichment(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $this->wireShareNode($userFolder, '/admin/files/Publicaties/folder/report.summary.pdf');

        $this->setupOrFileService('https://example.com/index.php/s/mytoken');

        $uploadedFile = [
            'name' => 'report.summary.pdf',
            'type' => 'application/pdf',
            'size' => 2048,
        ];

        $data   = [];
        $result = $this->fileService->addFileInfoToData(
            data: $data,
            uploadedFile: $uploadedFile,
            filePath: 'Publicaties/folder/report.summary.pdf'
        );

        $this->assertSame('admin/Publicaties/folder/report.summary.pdf', $result['reference']);
        $this->assertSame('application/pdf', $result['type']);
        $this->assertSame(2048, $result['size']);
        $this->assertSame('report', $result['title']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertStringContainsString('/index.php/s/mytoken', $result['accessUrl']);
        $this->assertStringContainsString('/download', $result['downloadUrl']);

    }//end testAddFileInfoToDataEnrichment()

    /**
     * Preserves pre-existing accessUrl and downloadUrl values.
     *
     * @return void
     */
    public function testAddFileInfoToDataPreservesExistingUrls(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $this->wireShareNode($userFolder, '/admin/files/path/file.txt');

        $this->setupOrFileService('https://example.com/index.php/s/t');

        $data = [
            'accessUrl'   => 'https://existing.com/access',
            'downloadUrl' => 'https://existing.com/download',
        ];

        $uploadedFile = ['name' => 'file.txt', 'type' => 'text/plain', 'size' => 10];
        $result       = $this->fileService->addFileInfoToData(
            data: $data,
            uploadedFile: $uploadedFile,
            filePath: 'path/file.txt'
        );

        $this->assertSame('https://existing.com/access', $result['accessUrl']);
        $this->assertSame('https://existing.com/download', $result['downloadUrl']);

    }//end testAddFileInfoToDataPreservesExistingUrls()

    /**
     * Creates a new file and returns true.
     *
     * @return void
     */
    public function testUploadFileNewFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('file content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(
            function () use ($file, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new NotFoundException();
                }

                return $file;
            }
        );

        $userFolder->expects($this->once())->method('newFile')->with('path/file.txt');

        $result = $this->fileService->uploadFile(content: 'file content', filePath: '/path/file.txt/');
        $this->assertTrue($result);

    }//end testUploadFileNewFile()

    /**
     * Returns false and logs warning when file already exists.
     *
     * @return void
     */
    public function testUploadFileExistingFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $userFolder->method('get')->willReturn($file);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('already exists'));

        $result = $this->fileService->uploadFile(content: 'content', filePath: 'path/existing.txt');
        $this->assertFalse($result);

    }//end testUploadFileExistingFile()

    /**
     * Throws Exception when upload is not permitted.
     *
     * @return void
     */
    public function testUploadFilePermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());
        $userFolder->method('newFile')
            ->willThrowException(new NotPermittedException());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t write to file/");

        $this->fileService->uploadFile(content: 'content', filePath: 'restricted/file.txt');

    }//end testUploadFilePermissionError()

    /**
     * Throws Exception on GenericFileException during putContent.
     *
     * @return void
     */
    public function testUploadFileGenericFileException(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(
            function () use ($file, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new NotFoundException();
                }

                return $file;
            }
        );

        $userFolder->method('newFile')->willReturn(null);
        $file->method('putContent')->willThrowException(new GenericFileException());

        $this->expectException(Exception::class);

        $this->fileService->uploadFile(content: 'content', filePath: 'path/file.txt');

    }//end testUploadFileGenericFileException()

    /**
     * Overwrites an existing file and returns true.
     *
     * @return void
     */
    public function testUpdateFileExistingFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('new content');
        $userFolder->method('get')->with('path/file.txt')->willReturn($file);

        $result = $this->fileService->updateFile(content: 'new content', filePath: '/path/file.txt/');
        $this->assertTrue($result);

    }//end testUpdateFileExistingFile()

    /**
     * Creates a new file when createNew is true and file is missing.
     *
     * @return void
     */
    public function testUpdateFileNewFileWithCreateNew(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(
            function () use ($file, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new NotFoundException();
                }

                return $file;
            }
        );

        $userFolder->expects($this->once())->method('newFile');

        $result = $this->fileService->updateFile(content: 'content', filePath: 'path/file.txt', createNew: true);
        $this->assertTrue($result);

    }//end testUpdateFileNewFileWithCreateNew()

    /**
     * Returns false when file does not exist and createNew is false.
     *
     * @return void
     */
    public function testUpdateFileNotFoundWithoutCreateNew(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('already exists'));

        $result = $this->fileService->updateFile(content: 'content', filePath: 'missing/file.txt', createNew: false);
        $this->assertFalse($result);

    }//end testUpdateFileNotFoundWithoutCreateNew()

    /**
     * Throws Exception when writing is not permitted.
     *
     * @return void
     */
    public function testUpdateFilePermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('putContent')
            ->willThrowException(new NotPermittedException());
        $userFolder->method('get')->willReturn($file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t write to file/");

        $this->fileService->updateFile(content: 'content', filePath: 'locked/file.txt');

    }//end testUpdateFilePermissionError()

    /**
     * Removes an existing file and returns true.
     *
     * @return void
     */
    public function testDeleteFileExists(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('delete');
        $userFolder->method('get')->with('path/file.txt')->willReturn($file);

        $result = $this->fileService->deleteFile('/path/file.txt/');
        $this->assertTrue($result);

    }//end testDeleteFileExists()

    /**
     * Returns false and logs warning when file does not exist.
     *
     * @return void
     */
    public function testDeleteFileNotFound(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('does not exist'));

        $result = $this->fileService->deleteFile('missing/file.txt');
        $this->assertFalse($result);

    }//end testDeleteFileNotFound()

    /**
     * Throws Exception when deletion is not permitted.
     *
     * @return void
     */
    public function testDeleteFilePermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('delete')
            ->willThrowException(new NotPermittedException());
        $userFolder->method('get')->willReturn($file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t delete file/");

        $this->fileService->deleteFile('restricted/file.txt');

    }//end testDeleteFilePermissionError()

    /**
     * Uses Guest user folder when no user is authenticated.
     *
     * @return void
     */
    public function testCreatePublicShareLinkGuestUserUsesGuestFolder(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);
        $this->wireShareNode($userFolder, '/Guest/files/file.pdf');

        $this->setupOrFileService('https://example.com/index.php/s/guest-token');

        $result = $this->fileService->createPublicShareLink('file.pdf');
        $this->assertSame('https://example.com/index.php/s/guest-token', $result);

    }//end testCreatePublicShareLinkGuestUserUsesGuestFolder()

    /**
     * Uses Guest user ID for folder when no user is authenticated.
     *
     * @return void
     */
    public function testCreateFolderGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $userFolder->method('get')->willThrowException(new NotFoundException());
        $userFolder->expects($this->once())->method('newFolder')->with('TestFolder');

        $result = $this->fileService->createFolder('TestFolder');
        $this->assertTrue($result);

    }//end testCreateFolderGuestUser()

    /**
     * Uses Guest user reference when no user is authenticated.
     *
     * @return void
     */
    public function testAddFileInfoToDataGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);
        $this->wireShareNode($userFolder, '/Guest/files/path/file.txt');

        $this->setupOrFileService('https://example.com/index.php/s/gt');

        $uploadedFile = ['name' => 'file.txt', 'type' => 'text/plain', 'size' => 10];
        $result       = $this->fileService->addFileInfoToData(
            data: [],
            uploadedFile: $uploadedFile,
            filePath: 'path/file.txt'
        );

        $this->assertSame('Guest/path/file.txt', $result['reference']);

    }//end testAddFileInfoToDataGuestUser()

    /**
     * Uses Guest user ID for upload when no user is authenticated.
     *
     * @return void
     */
    public function testUploadFileGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(
            function () use ($file, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new NotFoundException();
                }

                return $file;
            }
        );

        $userFolder->expects($this->once())->method('newFile');

        $result = $this->fileService->uploadFile(content: 'content', filePath: 'path/file.txt');
        $this->assertTrue($result);

    }//end testUploadFileGuestUser()

    /**
     * Uses Guest user ID for update when no user is authenticated.
     *
     * @return void
     */
    public function testUpdateFileGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('updated');
        $userFolder->method('get')->willReturn($file);

        $result = $this->fileService->updateFile(content: 'updated', filePath: 'path/file.txt');
        $this->assertTrue($result);

    }//end testUpdateFileGuestUser()

    /**
     * Uses Guest user ID for delete when no user is authenticated.
     *
     * @return void
     */
    public function testDeleteFileGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('delete');
        $userFolder->method('get')->willReturn($file);

        $result = $this->fileService->deleteFile('path/file.txt');
        $this->assertTrue($result);

    }//end testDeleteFileGuestUser()

    /**
     * Creates a ZIP archive containing all files in the input folder.
     *
     * @return void
     */
    public function testCreateZipSuccess(): void
    {
        $inputFolder = sys_get_temp_dir().'/test_zip_input_'.uniqid();
        mkdir($inputFolder, 0777, true);
        file_put_contents("$inputFolder/file1.txt", 'Hello');
        file_put_contents("$inputFolder/file2.txt", 'World');

        $tempZip = sys_get_temp_dir().'/test_output_'.uniqid().'.zip';

        $result = $this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);
        $this->assertFileExists($tempZip);

        $zip = new \ZipArchive();
        $zip->open($tempZip);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        unlink("$inputFolder/file1.txt");
        unlink("$inputFolder/file2.txt");
        rmdir($inputFolder);
        unlink($tempZip);

    }//end testCreateZipSuccess()

    /**
     * Handles an empty input folder without errors.
     *
     * @return void
     */
    public function testCreateZipEmptyFolder(): void
    {
        $inputFolder = sys_get_temp_dir().'/test_zip_empty_'.uniqid();
        mkdir($inputFolder, 0777, true);

        $tempZip = sys_get_temp_dir().'/test_empty_'.uniqid().'.zip';

        $result = @$this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);

        rmdir($inputFolder);
        if (file_exists($tempZip) === true) {
            unlink($tempZip);
        }

    }//end testCreateZipEmptyFolder()

    /**
     * Includes files from sub-directories.
     *
     * @return void
     */
    public function testCreateZipWithSubdirectory(): void
    {
        $inputFolder = sys_get_temp_dir().'/test_zip_subdir_'.uniqid();
        mkdir("$inputFolder/subdir", 0777, true);
        file_put_contents("$inputFolder/root.txt", 'root');
        file_put_contents("$inputFolder/subdir/nested.txt", 'nested');

        $tempZip = sys_get_temp_dir().'/test_subdir_'.uniqid().'.zip';

        $result = $this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);

        $zip = new \ZipArchive();
        $zip->open($tempZip);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        unlink("$inputFolder/root.txt");
        unlink("$inputFolder/subdir/nested.txt");
        rmdir("$inputFolder/subdir");
        rmdir($inputFolder);
        unlink($tempZip);

    }//end testCreateZipWithSubdirectory()

    // phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
