<?php

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenCatalogi\Service\FileService;
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
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for the FileService class.
 */
class FileServiceTest extends \PHPUnit\Framework\TestCase
{
    private FileService $fileService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private IRootFolder&MockObject $rootFolder;
    private IManager&MockObject $shareManager;

    protected function setUp(): void
    {
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->rootFolder   = $this->createMock(IRootFolder::class);
        $this->shareManager = $this->createMock(IManager::class);

        $this->fileService = new FileService(
            $this->userSession,
            $this->logger,
            $this->rootFolder,
            $this->shareManager
        );
    }

    /**
     * Helper to invoke a private method via reflection.
     *
     * @param string $method     The method name.
     * @param array  $parameters The parameters to pass.
     *
     * @return mixed The return value of the method.
     */
    private function invokePrivateMethod(string $method, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($this->fileService);
        $method     = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($this->fileService, $parameters);
    }

    /**
     * Helper to set up a mock user and user folder.
     *
     * @param string $userId The user ID to return.
     *
     * @return Folder&MockObject The mocked user folder.
     */
    private function setupUserFolder(string $userId = 'admin'): Folder&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with($userId)->willReturn($userFolder);

        return $userFolder;
    }

    // -------------------------------------------------------------------------
    // getPublicationFolderName
    // -------------------------------------------------------------------------

    public function testGetPublicationFolderNameFormat(): void
    {
        $result = $this->fileService->getPublicationFolderName('123', 'My Publication');
        $this->assertSame('(123) My Publication', $result);
    }

    public function testGetPublicationFolderNameEmptyTitle(): void
    {
        $result = $this->fileService->getPublicationFolderName('42', '');
        $this->assertSame('(42) ', $result);
    }

    // -------------------------------------------------------------------------
    // getShareLink
    // -------------------------------------------------------------------------

    public function testGetShareLinkReturnsCorrectUrl(): void
    {
        // Set up $_SERVER for getCurrentDomain.
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('abc123token');

        $result = $this->fileService->getShareLink($share);
        $this->assertSame('https://example.com/index.php/s/abc123token', $result);
    }

    public function testGetShareLinkHttpProtocol(): void
    {
        $_SERVER['HTTPS']    = '';
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('token456');

        $result = $this->fileService->getShareLink($share);
        $this->assertSame('http://localhost:8080/index.php/s/token456', $result);
    }

    // -------------------------------------------------------------------------
    // getCurrentDomain (private)
    // -------------------------------------------------------------------------

    public function testGetCurrentDomainHttps(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'secure.example.com';

        $result = $this->invokePrivateMethod('getCurrentDomain');
        $this->assertSame('https://secure.example.com', $result);
    }

    public function testGetCurrentDomainHttp(): void
    {
        $_SERVER['HTTPS']    = 'off';
        $_SERVER['HTTP_HOST'] = 'local.dev';

        $result = $this->invokePrivateMethod('getCurrentDomain');
        $this->assertSame('http://local.dev', $result);
    }

    public function testGetCurrentDomainHttpsNotSet(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'local.dev';

        $result = $this->invokePrivateMethod('getCurrentDomain');
        $this->assertSame('http://local.dev', $result);
    }

    // -------------------------------------------------------------------------
    // findShare
    // -------------------------------------------------------------------------

    public function testFindShareFound(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $userFolder->method('get')->with('some/path')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $this->shareManager->method('getSharesBy')
            ->with('admin', 3, $file)
            ->willReturn([$share]);

        $result = $this->fileService->findShare('some/path', 3);
        $this->assertSame($share, $result);
    }

    public function testFindShareNotFound(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $userFolder->method('get')->with('some/path')->willReturn($file);

        $this->shareManager->method('getSharesBy')
            ->with('admin', 3, $file)
            ->willReturn([]);

        $result = $this->fileService->findShare('some/path', 3);
        $this->assertNull($result);
    }

    public function testFindShareUserFolderNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->rootFolder->method('getUserFolder')
            ->with('admin')
            ->willThrowException(new NotPermittedException());

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains("Can't find share"));

        $result = $this->fileService->findShare('some/path');
        $this->assertNull($result);
    }

    public function testFindShareFileNotFound(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $userFolder->method('get')
            ->with('some/path')
            ->willThrowException(new NotFoundException());

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains("file doesn't exist"));

        $result = $this->fileService->findShare('some/path');
        $this->assertNull($result);
    }

    public function testFindShareReturnsNullWhenNodeIsNotFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        // Return a folder instead of a file.
        $folder = $this->createMock(Folder::class);
        $userFolder->method('get')->with('some/folder')->willReturn($folder);

        $result = $this->fileService->findShare('some/folder');
        $this->assertNull($result);
    }

    public function testFindShareGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());

        $result = $this->fileService->findShare('path');
        $this->assertNull($result);
    }

    public function testFindShareTrimsSlashes(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $userFolder->expects($this->once())
            ->method('get')
            ->with('trimmed/path')
            ->willReturn($file);

        $this->shareManager->method('getSharesBy')->willReturn([]);

        $this->fileService->findShare('/trimmed/path/', 3);
    }

    // -------------------------------------------------------------------------
    // createShare (private)
    // -------------------------------------------------------------------------

    public function testCreateShareSuccess(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);

        $share = $this->createMock(IShare::class);
        $share->expects($this->once())->method('setTarget')->with('/test/path');
        $share->expects($this->once())->method('setNodeId')->with(42);
        $share->expects($this->once())->method('setNodeType')->with('file');
        $share->expects($this->once())->method('setShareType')->with(3);
        $share->expects($this->once())->method('setPermissions')->with(1);
        $share->expects($this->once())->method('setSharedBy')->with('admin');
        $share->expects($this->once())->method('setShareOwner')->with('admin');

        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->expects($this->once())
            ->method('createShare')
            ->with($share)
            ->willReturn($share);

        $shareData = [
            'path'        => 'test/path',
            'file'        => $file,
            'shareType'   => 3,
            'permissions' => 1,
            'userId'      => 'admin',
        ];

        $result = $this->invokePrivateMethod('createShare', [$shareData]);
        $this->assertSame($share, $result);
    }

    public function testCreateShareNullPermissions(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(10);

        $share = $this->createMock(IShare::class);
        $share->expects($this->never())->method('setPermissions');

        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $shareData = [
            'path'        => 'test/path',
            'file'        => $file,
            'shareType'   => 3,
            'permissions' => null,
            'userId'      => 'admin',
        ];

        $this->invokePrivateMethod('createShare', [$shareData]);
    }

    // -------------------------------------------------------------------------
    // createShareLink
    // -------------------------------------------------------------------------

    public function testCreateShareLinkSuccess(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(99);
        $userFolder->method('get')->with('test/file.pdf')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('sharetoken');

        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $result = $this->fileService->createShareLink('test/file.pdf');
        $this->assertSame('https://example.com/index.php/s/sharetoken', $result);
    }

    public function testCreateShareLinkFileNotFound(): void
    {
        $userFolder = $this->setupUserFolder('admin');
        $userFolder->method('get')
            ->willThrowException(new NotFoundException());

        $result = $this->fileService->createShareLink('missing/file.pdf');
        $this->assertStringContainsString('File not found', $result);
    }

    public function testCreateShareLinkUserNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->rootFolder->method('getUserFolder')
            ->willThrowException(new NotPermittedException());

        $result = $this->fileService->createShareLink('some/path');
        $this->assertStringContainsString("couldn't be found", $result);
    }

    public function testCreateShareLinkException(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(99);
        $userFolder->method('get')->willReturn($file);

        $this->shareManager->method('newShare')
            ->willThrowException(new Exception('Share creation failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Can't create share link");

        $this->fileService->createShareLink('test/file.pdf');
    }

    public function testCreateShareLinkDefaultPermissionsPublicLink(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');
        $file       = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('t');

        // For shareType=3 (public link), permissions should default to 1.
        $share->expects($this->once())->method('setPermissions')->with(1);

        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $this->fileService->createShareLink('file.pdf', 3, null);
    }

    public function testCreateShareLinkDefaultPermissionsNonPublic(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');
        $file       = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('t');

        // For shareType=0 (user), permissions should default to 31.
        $share->expects($this->once())->method('setPermissions')->with(31);

        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $this->fileService->createShareLink('file.pdf', 0, null);
    }

    // -------------------------------------------------------------------------
    // handleFile
    // -------------------------------------------------------------------------

    public function testHandleFileSuccessfulUpload(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');

        // Create a real temporary file for file_get_contents.
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'file content');

        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([
            'name'     => 'document.pdf',
            'tmp_name' => $tmpFile,
            'type'     => 'application/pdf',
            'size'     => 12345,
            'error'    => UPLOAD_ERR_OK,
        ]);
        $request->method('getHeader')
            ->willReturnMap([
                ['Publication-Id', '42'],
                ['Publication-Title', 'Test Publication'],
            ]);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);

        // Track get() calls to differentiate folder checks from file operations.
        $getCallIndex = 0;
        $userFolder->method('get')->willReturnCallback(function (string $path) use ($userFolder, $file, &$getCallIndex) {
            $getCallIndex++;
            // Calls 1-3: folder existence checks (Publicaties, Publicaties/(42) Test Publication,
            // Publicaties/(42) Test Publication/Bijlagen) - return folder (already exists).
            if ($getCallIndex <= 3) {
                return $userFolder;
            }

            // Call 4: uploadFile checks if file exists - throw NotFoundException.
            if ($getCallIndex === 4) {
                throw new NotFoundException();
            }

            // Call 5+: after newFile, return the file mock for putContent and share link creation.
            return $file;
        });

        $userFolder->method('newFile')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('sharetoken');
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $result = $this->fileService->handleFile($request, []);

        $this->assertIsArray($result);
        $this->assertSame('application/pdf', $result['type']);
        $this->assertSame(12345, $result['size']);
        $this->assertSame('document', $result['title']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertStringContainsString('/index.php/s/sharetoken', $result['accessUrl']);

        @unlink($tmpFile);
    }

    public function testHandleFileUploadFails(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'content');

        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([
            'name'     => 'document.pdf',
            'tmp_name' => $tmpFile,
            'type'     => 'application/pdf',
            'size'     => 100,
            'error'    => UPLOAD_ERR_OK,
        ]);
        $request->method('getHeader')
            ->willReturnMap([
                ['Publication-Id', '1'],
                ['Publication-Title', 'Pub'],
            ]);

        // All folder checks succeed, and the file already exists (uploadFile returns false).
        $userFolder->method('get')->willReturn($userFolder);
        $userFolder->method('newFile')->willReturn($this->createMock(File::class));

        $result = $this->fileService->handleFile($request, []);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

        @unlink($tmpFile);
    }

    public function testHandleFileWithoutFile(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([]);

        $result = $this->fileService->handleFile($request, []);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
    }

    public function testHandleFileUploadError(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([
            'name'     => 'bad.pdf',
            'tmp_name' => '/tmp/phpXXX',
            'type'     => 'application/pdf',
            'size'     => 100,
            'error'    => UPLOAD_ERR_INI_SIZE,
        ]);

        $result = $this->fileService->handleFile($request, []);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
    }

    // -------------------------------------------------------------------------
    // checkUploadedFile (private)
    // -------------------------------------------------------------------------

    public function testCheckUploadedFileNoFile(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('_file')->willReturn([]);

        $result = $this->invokePrivateMethod('checkUploadedFile', [$request]);
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
    }

    public function testCheckUploadedFileError(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->willReturn([
            'name'     => 'file.pdf',
            'tmp_name' => '/tmp/phpXYZ',
            'type'     => 'application/pdf',
            'size'     => 100,
            'error'    => UPLOAD_ERR_PARTIAL,
        ]);

        $result = $this->invokePrivateMethod('checkUploadedFile', [$request]);
        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testCheckUploadedFileSuccess(): void
    {
        $request = $this->createMock(IRequest::class);
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
    }

    // -------------------------------------------------------------------------
    // createFolder
    // -------------------------------------------------------------------------

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
    }

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
    }

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
    }

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
    }

    // -------------------------------------------------------------------------
    // addFileInfoToData
    // -------------------------------------------------------------------------

    public function testAddFileInfoToDataEnrichment(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');
        $file       = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);

        // For createShareLink -> get file.
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('mytoken');
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $uploadedFile = [
            'name' => 'report.summary.pdf',
            'type' => 'application/pdf',
            'size' => 2048,
        ];

        $data   = [];
        $result = $this->fileService->addFileInfoToData($data, $uploadedFile, 'Publicaties/folder/report.summary.pdf');

        $this->assertSame('admin/Publicaties/folder/report.summary.pdf', $result['reference']);
        $this->assertSame('application/pdf', $result['type']);
        $this->assertSame(2048, $result['size']);
        $this->assertSame('report', $result['title']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertStringContainsString('/index.php/s/mytoken', $result['accessUrl']);
        $this->assertStringContainsString('/download', $result['downloadUrl']);
    }

    public function testAddFileInfoToDataPreservesExistingUrls(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');
        $file       = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('t');
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $data = [
            'accessUrl'   => 'https://existing.com/access',
            'downloadUrl' => 'https://existing.com/download',
        ];

        $uploadedFile = ['name' => 'file.txt', 'type' => 'text/plain', 'size' => 10];
        $result       = $this->fileService->addFileInfoToData($data, $uploadedFile, 'path/file.txt');

        $this->assertSame('https://existing.com/access', $result['accessUrl']);
        $this->assertSame('https://existing.com/download', $result['downloadUrl']);
    }

    // -------------------------------------------------------------------------
    // uploadFile
    // -------------------------------------------------------------------------

    public function testUploadFileNewFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('file content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(function () use ($file, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new NotFoundException();
            }

            return $file;
        });

        $userFolder->expects($this->once())->method('newFile')->with('path/file.txt');

        $result = $this->fileService->uploadFile('file content', '/path/file.txt/');
        $this->assertTrue($result);
    }

    public function testUploadFileExistingFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $userFolder->method('get')->willReturn($file);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('already exists'));

        $result = $this->fileService->uploadFile('content', 'path/existing.txt');
        $this->assertFalse($result);
    }

    public function testUploadFilePermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());
        $userFolder->method('newFile')
            ->willThrowException(new NotPermittedException());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t write to file/");

        $this->fileService->uploadFile('content', 'restricted/file.txt');
    }

    public function testUploadFileGenericFileException(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(function () use ($file, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new NotFoundException();
            }

            return $file;
        });

        $userFolder->method('newFile')->willReturn(null);
        $file->method('putContent')->willThrowException(new GenericFileException());

        $this->expectException(Exception::class);

        $this->fileService->uploadFile('content', 'path/file.txt');
    }

    // -------------------------------------------------------------------------
    // updateFile
    // -------------------------------------------------------------------------

    public function testUpdateFileExistingFile(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('new content');
        $userFolder->method('get')->with('path/file.txt')->willReturn($file);

        $result = $this->fileService->updateFile('new content', '/path/file.txt/');
        $this->assertTrue($result);
    }

    public function testUpdateFileNewFileWithCreateNew(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(function () use ($file, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new NotFoundException();
            }

            return $file;
        });

        $userFolder->expects($this->once())->method('newFile');

        $result = $this->fileService->updateFile('content', 'path/file.txt', true);
        $this->assertTrue($result);
    }

    public function testUpdateFileNotFoundWithoutCreateNew(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('already exists'));

        $result = $this->fileService->updateFile('content', 'missing/file.txt', false);
        $this->assertFalse($result);
    }

    public function testUpdateFilePermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('putContent')
            ->willThrowException(new NotPermittedException());
        $userFolder->method('get')->willReturn($file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t write to file/");

        $this->fileService->updateFile('content', 'locked/file.txt');
    }

    // -------------------------------------------------------------------------
    // deleteFile
    // -------------------------------------------------------------------------

    public function testDeleteFileExists(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('delete');
        $userFolder->method('get')->with('path/file.txt')->willReturn($file);

        $result = $this->fileService->deleteFile('/path/file.txt/');
        $this->assertTrue($result);
    }

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
    }

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
    }

    // -------------------------------------------------------------------------
    // Guest user fallback paths
    // -------------------------------------------------------------------------

    public function testCreateShareLinkGuestUser(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('guest-token');
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $result = $this->fileService->createShareLink('file.pdf');
        $this->assertStringContainsString('/index.php/s/guest-token', $result);
    }

    public function testCreateFolderGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $userFolder->method('get')->willThrowException(new NotFoundException());
        $userFolder->expects($this->once())->method('newFolder')->with('TestFolder');

        $result = $this->fileService->createFolder('TestFolder');
        $this->assertTrue($result);
    }

    public function testAddFileInfoToDataGuestUser(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('gt');
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $uploadedFile = ['name' => 'file.txt', 'type' => 'text/plain', 'size' => 10];
        $result = $this->fileService->addFileInfoToData([], $uploadedFile, 'path/file.txt');

        $this->assertSame('Guest/path/file.txt', $result['reference']);
    }

    public function testUploadFileGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('content');

        $callCount = 0;
        $userFolder->method('get')->willReturnCallback(function () use ($file, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new NotFoundException();
            }
            return $file;
        });

        $userFolder->expects($this->once())->method('newFile');

        $result = $this->fileService->uploadFile('content', 'path/file.txt');
        $this->assertTrue($result);
    }

    public function testUpdateFileGuestUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->with('Guest')->willReturn($userFolder);

        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('putContent')->with('updated');
        $userFolder->method('get')->willReturn($file);

        $result = $this->fileService->updateFile('updated', 'path/file.txt');
        $this->assertTrue($result);
    }

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
    }

    // -------------------------------------------------------------------------
    // createShareLink — additional permission branches
    // -------------------------------------------------------------------------

    public function testCreateShareLinkWithExplicitPermissions(): void
    {
        $_SERVER['HTTPS']    = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $userFolder = $this->setupUserFolder('admin');
        $file       = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $userFolder->method('get')->willReturn($file);

        $share = $this->createMock(IShare::class);
        $share->method('getToken')->willReturn('t');
        // Explicit permissions=16 should be used directly, not defaulted.
        $share->expects($this->once())->method('setPermissions')->with(16);
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        $this->fileService->createShareLink('file.pdf', 3, 16);
    }

    // -------------------------------------------------------------------------
    // createZip — uses real temp files
    // -------------------------------------------------------------------------

    public function testCreateZipSuccess(): void
    {
        // Create temporary input folder with files.
        $inputFolder = sys_get_temp_dir() . '/test_zip_input_' . uniqid();
        mkdir($inputFolder, 0777, true);
        file_put_contents("$inputFolder/file1.txt", 'Hello');
        file_put_contents("$inputFolder/file2.txt", 'World');

        $tempZip = sys_get_temp_dir() . '/test_output_' . uniqid() . '.zip';

        $result = $this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);
        $this->assertFileExists($tempZip);

        // Verify the ZIP contents.
        $zip = new \ZipArchive();
        $zip->open($tempZip);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        // Cleanup.
        unlink("$inputFolder/file1.txt");
        unlink("$inputFolder/file2.txt");
        rmdir($inputFolder);
        unlink($tempZip);
    }

    public function testCreateZipEmptyFolder(): void
    {
        $inputFolder = sys_get_temp_dir() . '/test_zip_empty_' . uniqid();
        mkdir($inputFolder, 0777, true);

        $tempZip = sys_get_temp_dir() . '/test_empty_' . uniqid() . '.zip';

        // Suppress PHP warning from ZipArchive::close() on empty archives.
        $result = @$this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);

        // Cleanup.
        rmdir($inputFolder);
        if (file_exists($tempZip)) {
            unlink($tempZip);
        }
    }

    public function testCreateZipWithSubdirectory(): void
    {
        $inputFolder = sys_get_temp_dir() . '/test_zip_subdir_' . uniqid();
        mkdir("$inputFolder/subdir", 0777, true);
        file_put_contents("$inputFolder/root.txt", 'root');
        file_put_contents("$inputFolder/subdir/nested.txt", 'nested');

        $tempZip = sys_get_temp_dir() . '/test_subdir_' . uniqid() . '.zip';

        $result = $this->fileService->createZip($inputFolder, $tempZip);

        $this->assertNull($result);

        $zip = new \ZipArchive();
        $zip->open($tempZip);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        // Cleanup.
        unlink("$inputFolder/root.txt");
        unlink("$inputFolder/subdir/nested.txt");
        rmdir("$inputFolder/subdir");
        rmdir($inputFolder);
        unlink($tempZip);
    }

    public function testCreateZipInvalidPath(): void
    {
        $inputFolder = sys_get_temp_dir() . '/test_zip_input_' . uniqid();
        mkdir($inputFolder, 0777, true);
        file_put_contents("$inputFolder/file.txt", 'data');

        // Use a path inside a non-writable directory.
        $readOnlyDir = sys_get_temp_dir() . '/test_zip_readonly_' . uniqid();
        mkdir($readOnlyDir, 0444, true);
        $tempZip = "$readOnlyDir/subdir/test.zip";

        $result = $this->fileService->createZip($inputFolder, $tempZip);

        // On most systems this will fail. If it doesn't, just skip.
        if ($result === null) {
            // Some systems allow this; clean up and skip.
            @unlink($tempZip);
            @rmdir("$readOnlyDir/subdir");
            chmod($readOnlyDir, 0777);
            rmdir($readOnlyDir);
            unlink("$inputFolder/file.txt");
            rmdir($inputFolder);
            $this->markTestSkipped('System allows writing to read-only directories');
        }

        $this->assertSame('failed to create ZIP archive', $result);

        // Cleanup.
        chmod($readOnlyDir, 0777);
        rmdir($readOnlyDir);
        unlink("$inputFolder/file.txt");
        rmdir($inputFolder);
    }

    // -------------------------------------------------------------------------
    // downloadZip — skipped due to header() calls
    // -------------------------------------------------------------------------

    public function testDownloadZipSkipped(): void
    {
        // downloadZip() calls header() which cannot be sent in CLI, and
        // @runInSeparateProcess fails with Nextcloud bootstrap output.
        $this->markTestSkipped('downloadZip() calls header() and readfile(); incompatible with Nextcloud bootstrap in separate process.');
    }

    // -------------------------------------------------------------------------
    // createPdf — test error handling
    // -------------------------------------------------------------------------

    public function testCreatePdfThrowsOnMissingTemplate(): void
    {
        // createPdf() directly instantiates Twig and Mpdf.
        // Without a valid template, Twig will throw a LoaderError or RuntimeError.
        $this->expectException(\Exception::class);

        $this->fileService->createPdf('nonexistent-template.html.twig', []);
    }

    public function testCreatePdfSuccess(): void
    {
        // Ensure the test template exists.
        $templateDir = '/var/www/html/custom_apps/opencatalogi/lib/Templates';
        if (is_dir($templateDir) === false) {
            mkdir($templateDir, 0777, true);
        }

        $templateFile = "$templateDir/test.html.twig";
        if (file_exists($templateFile) === false) {
            file_put_contents($templateFile, '<html><body>{{ title }}</body></html>');
        }

        $result = $this->fileService->createPdf('test.html.twig', ['title' => 'Test PDF']);

        $this->assertInstanceOf(\Mpdf\Mpdf::class, $result);

        // Cleanup mpdf temp dir.
        if (is_dir('/tmp/mpdf')) {
            $files = glob('/tmp/mpdf/*');
            if ($files !== false) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // updateFile — additional branch: createNew with NotPermittedException
    // -------------------------------------------------------------------------

    public function testUpdateFileCreateNewPermissionError(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $userFolder->method('get')
            ->willThrowException(new NotFoundException());
        $userFolder->method('newFile')
            ->willThrowException(new NotPermittedException());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t write to file/");

        $this->fileService->updateFile('content', 'restricted/file.txt', true);
    }

    // -------------------------------------------------------------------------
    // deleteFile — InvalidPathException branch
    // -------------------------------------------------------------------------

    public function testDeleteFileInvalidPathException(): void
    {
        $userFolder = $this->setupUserFolder('admin');

        $file = $this->createMock(File::class);
        $file->method('delete')
            ->willThrowException(new \OCP\Files\InvalidPathException());
        $userFolder->method('get')->willReturn($file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/Can.*t delete file/");

        $this->fileService->deleteFile('invalid/file.txt');
    }
}
