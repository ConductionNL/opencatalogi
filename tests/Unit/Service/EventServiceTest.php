<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the EventService class.
 *
 * Tests all public and private methods including auto-publish logic,
 * object/attachment publishing, and service resolution.
 */
class EventServiceTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configMock;

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestMock;

    /**
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $containerMock;

    /**
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManagerMock;

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $settingsServiceMock;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var EventService
     */
    private EventService $eventService;


    protected function setUp(): void
    {
        $this->configMock          = $this->createMock(IAppConfig::class);
        $this->requestMock         = $this->createMock(IRequest::class);
        $this->containerMock       = $this->createMock(ContainerInterface::class);
        $this->appManagerMock      = $this->createMock(IAppManager::class);
        $this->settingsServiceMock = $this->createMock(SettingsService::class);
        $this->loggerMock          = $this->createMock(LoggerInterface::class);

        $this->eventService = new EventService(
            $this->configMock,
            $this->requestMock,
            $this->containerMock,
            $this->appManagerMock,
            $this->settingsServiceMock,
            $this->loggerMock
        );

    }//end setUp()


    /**
     * Helper to invoke private methods via reflection.
     *
     * @param object $object     The object instance.
     * @param string $methodName The private method name.
     * @param array  $parameters The method parameters.
     *
     * @return mixed The method return value.
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);

    }//end invokePrivateMethod()


    // ===== getObjectService tests =====

    /**
     * Test getObjectService returns the service when OpenRegister is installed.
     */
    public function testGetObjectServiceAvailable(): void
    {
        $mockObjectService = $this->createMock(ObjectService::class);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister', 'opencatalogi']);

        $this->containerMock->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->eventService->getObjectService();
        $this->assertSame($mockObjectService, $result);

    }//end testGetObjectServiceAvailable()


    /**
     * Test getObjectService throws RuntimeException when OpenRegister is not installed.
     */
    public function testGetObjectServiceNotAvailable(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['opencatalogi']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister object service is not available.');

        $this->eventService->getObjectService();

    }//end testGetObjectServiceNotAvailable()


    // ===== getFileService tests =====

    /**
     * Test getFileService returns the service when OpenRegister is installed.
     */
    public function testGetFileServiceAvailable(): void
    {
        $mockFileService = $this->createMock(FileService::class);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->containerMock->method('get')
            ->with('OCA\OpenRegister\Service\FileService')
            ->willReturn($mockFileService);

        $result = $this->eventService->getFileService();
        $this->assertSame($mockFileService, $result);

    }//end testGetFileServiceAvailable()


    /**
     * Test getFileService throws RuntimeException when OpenRegister is not installed.
     */
    public function testGetFileServiceNotAvailable(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister file service is not available.');

        $this->eventService->getFileService();

    }//end testGetFileServiceNotAvailable()


    // ===== getFileMapper tests =====

    /**
     * Test getFileMapper returns the mapper when OpenRegister is installed.
     */
    public function testGetFileMapperAvailable(): void
    {
        $mockMapper = $this->createMock(FileMapper::class);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->containerMock->method('get')
            ->with('OCA\OpenRegister\Db\FileMapper')
            ->willReturn($mockMapper);

        $result = $this->eventService->getFileMapper();
        $this->assertSame($mockMapper, $result);

    }//end testGetFileMapperAvailable()


    /**
     * Test getFileMapper throws RuntimeException when OpenRegister is not installed.
     */
    public function testGetFileMapperNotAvailable(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister FileMapper is not available.');

        $this->eventService->getFileMapper();

    }//end testGetFileMapperNotAvailable()


    // ===== isObjectPublished tests =====

    /**
     * Test isObjectPublished returns true when published is set and depublished is null.
     */
    public function testIsObjectPublishedWithPublishedOnly(): void
    {
        $objectData = [
            '@self' => [
                'published'   => '2024-01-15T10:00:00+00:00',
                'depublished' => null,
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertTrue($result);

    }//end testIsObjectPublishedWithPublishedOnly()


    /**
     * Test isObjectPublished returns true when published is set and depublished is absent.
     */
    public function testIsObjectPublishedWithNoDepublishedKey(): void
    {
        $objectData = [
            '@self' => [
                'published' => '2024-01-15T10:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertTrue($result);

    }//end testIsObjectPublishedWithNoDepublishedKey()


    /**
     * Test isObjectPublished returns false when neither published nor depublished is set.
     */
    public function testIsObjectPublishedNeitherSet(): void
    {
        $objectData = [
            '@self' => [],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertFalse($result);

    }//end testIsObjectPublishedNeitherSet()


    /**
     * Test isObjectPublished returns false when only depublished is set.
     */
    public function testIsObjectPublishedOnlyDepublished(): void
    {
        $objectData = [
            '@self' => [
                'depublished' => '2024-01-15T10:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertFalse($result);

    }//end testIsObjectPublishedOnlyDepublished()


    /**
     * Test isObjectPublished returns true when published is after depublished.
     */
    public function testIsObjectPublishedRepublished(): void
    {
        $objectData = [
            '@self' => [
                'published'   => '2024-06-01T10:00:00+00:00',
                'depublished' => '2024-01-15T10:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertTrue($result);

    }//end testIsObjectPublishedRepublished()


    /**
     * Test isObjectPublished returns false when depublished is after published.
     */
    public function testIsObjectPublishedDepublishedAfterPublished(): void
    {
        $objectData = [
            '@self' => [
                'published'   => '2024-01-15T10:00:00+00:00',
                'depublished' => '2024-06-01T10:00:00+00:00',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'isObjectPublished', [$objectData]);
        $this->assertFalse($result);

    }//end testIsObjectPublishedDepublishedAfterPublished()


    // ===== shouldAutoPublishObject tests =====

    /**
     * Test shouldAutoPublishObject returns false when register is missing.
     */
    public function testShouldAutoPublishObjectMissingRegister(): void
    {
        $objectData = [
            '@self' => [
                'schema' => '1',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectMissingRegister()


    /**
     * Test shouldAutoPublishObject returns false when schema is missing.
     */
    public function testShouldAutoPublishObjectMissingSchema(): void
    {
        $objectData = [
            '@self' => [
                'register' => '1',
            ],
        ];

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectMissingSchema()


    /**
     * Test shouldAutoPublishObject returns false when @self is missing entirely.
     */
    public function testShouldAutoPublishObjectMissingSelf(): void
    {
        $objectData = ['title' => 'test'];

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectMissingSelf()


    /**
     * Test shouldAutoPublishObject returns false when catalog config is null.
     */
    public function testShouldAutoPublishObjectNoCatalogConfig(): void
    {
        $objectData = [
            '@self' => [
                'register' => '1',
                'schema'   => '2',
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjectService = $this->createMock(ObjectService::class);
        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->settingsServiceMock->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => null,
                    'catalog_schema'   => null,
                ],
            ]);

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectNoCatalogConfig()


    /**
     * Test shouldAutoPublishObject returns true when object matches a catalog.
     */
    public function testShouldAutoPublishObjectMatchesCatalog(): void
    {
        $objectData = [
            '@self' => [
                'register' => '10',
                'schema'   => '20',
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        // Create a catalog mock that has matching registers and schemas.
        $catalogMock = new class {
            public function jsonSerialize(): array
            {
                return [
                    'registers' => [10, 30],
                    'schemas'   => [20, 40],
                ];
            }
        };

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('searchObjects')
            ->willReturn([$catalogMock]);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->settingsServiceMock->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => '100',
                    'catalog_schema'   => '200',
                ],
            ]);

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertTrue($result);

    }//end testShouldAutoPublishObjectMatchesCatalog()


    /**
     * Test shouldAutoPublishObject returns false when object does not match any catalog.
     */
    public function testShouldAutoPublishObjectNoMatch(): void
    {
        $objectData = [
            '@self' => [
                'register' => '10',
                'schema'   => '20',
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $catalogMock = new class {
            public function jsonSerialize(): array
            {
                return [
                    'registers' => [99],
                    'schemas'   => [88],
                ];
            }
        };

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('searchObjects')
            ->willReturn([$catalogMock]);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->settingsServiceMock->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => '100',
                    'catalog_schema'   => '200',
                ],
            ]);

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectNoMatch()


    /**
     * Test shouldAutoPublishObject returns false and logs error on exception.
     */
    public function testShouldAutoPublishObjectException(): void
    {
        $objectData = [
            '@self' => [
                'register' => '1',
                'schema'   => '2',
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->containerMock->method('get')
            ->willThrowException(new \Exception('Container error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error checking auto-publish criteria'));

        $result = $this->invokePrivateMethod($this->eventService, 'shouldAutoPublishObject', [$objectData]);
        $this->assertFalse($result);

    }//end testShouldAutoPublishObjectException()


    // ===== publishObject tests =====

    /**
     * Test publishObject succeeds with uuid present.
     */
    public function testPublishObjectSuccess(): void
    {
        $objectData = [
            '@self' => [
                'uuid' => 'abc-123',
                'id'   => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        // Create mock that has both publish and getPublished methods.
        $publishedObjectMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getPublished'])
            ->getMock();
        $publishedDateTime = new \DateTime('2024-01-15T10:00:00+00:00');
        $publishedObjectMock->method('getPublished')
            ->willReturn($publishedDateTime);

        $mockObjectService = $this->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->addMethods(['publish'])
            ->getMock();
        $mockObjectService->method('publish')
            ->willReturn($publishedObjectMock);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $result = $this->invokePrivateMethod($this->eventService, 'publishObject', [$objectData]);

        $this->assertTrue($result['success']);
        $this->assertEquals('abc-123', $result['objectId']);
        $this->assertEquals('2024-01-15T10:00:00+00:00', $result['publishedAt']);

    }//end testPublishObjectSuccess()


    /**
     * Test publishObject uses id when uuid is absent.
     */
    public function testPublishObjectUsesIdFallback(): void
    {
        $objectData = [
            '@self' => [
                'id' => 42,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $publishedObjectMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getPublished'])
            ->getMock();
        $publishedObjectMock->method('getPublished')
            ->willReturn(null);

        $mockObjectService = $this->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->addMethods(['publish'])
            ->getMock();
        $mockObjectService->method('publish')
            ->willReturn($publishedObjectMock);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $result = $this->invokePrivateMethod($this->eventService, 'publishObject', [$objectData]);

        $this->assertTrue($result['success']);
        $this->assertEquals(42, $result['objectId']);
        $this->assertNull($result['publishedAt']);

    }//end testPublishObjectUsesIdFallback()


    /**
     * Test publishObject returns failure on exception.
     */
    public function testPublishObjectFailure(): void
    {
        $objectData = [
            '@self' => [
                'id' => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $result = $this->invokePrivateMethod($this->eventService, 'publishObject', [$objectData]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);

    }//end testPublishObjectFailure()


    // ===== publishObjectAttachments tests =====

    /**
     * Test publishObjectAttachments successfully publishes unpublished files.
     */
    public function testPublishObjectAttachmentsSuccess(): void
    {
        $objectData = [
            '@self' => [
                'uuid' => 'obj-uuid',
                'id'   => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectEntity = new ObjectEntity();

        $files = [
            ['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
            ['name' => 'image.png', 'path' => 'files/image.png', 'share_token' => 'existing-token'],
        ];

        $mockFileService = $this->createMock(FileService::class);
        $mockFileService->method('createShareLink')
            ->willReturn('https://example.com/share/abc');

        $mockFileMapper = $this->createMock(FileMapper::class);
        $mockFileMapper->method('getFilesForObject')
            ->willReturn($files);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn($objectEntity);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $result = $this->invokePrivateMethod($this->eventService, 'publishObjectAttachments', [$objectData]);

        $this->assertEquals(1, $result['published']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEmpty($result['errors']);

    }//end testPublishObjectAttachmentsSuccess()


    /**
     * Test publishObjectAttachments returns empty result when object entity is null.
     */
    public function testPublishObjectAttachmentsNullEntity(): void
    {
        $objectData = [
            '@self' => [
                'id' => 999,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockFileService = $this->createMock(FileService::class);
        $mockFileMapper  = $this->createMock(FileMapper::class);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn(null);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $result = $this->invokePrivateMethod($this->eventService, 'publishObjectAttachments', [$objectData]);

        $this->assertEquals(0, $result['published']);
        $this->assertEquals(0, $result['skipped']);

    }//end testPublishObjectAttachmentsNullEntity()


    /**
     * Test publishObjectAttachments handles file service unavailable.
     */
    public function testPublishObjectAttachmentsServiceUnavailable(): void
    {
        $objectData = [
            '@self' => [
                'id' => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $result = $this->invokePrivateMethod($this->eventService, 'publishObjectAttachments', [$objectData]);

        $this->assertEquals(0, $result['published']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Failed to access file service', $result['errors'][0]);

    }//end testPublishObjectAttachmentsServiceUnavailable()


    /**
     * Test publishObjectAttachments records error when share link creation fails.
     */
    public function testPublishObjectAttachmentsShareLinkFailure(): void
    {
        $objectData = [
            '@self' => [
                'uuid' => 'obj-uuid',
                'id'   => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectEntity = new ObjectEntity();

        $files = [
            ['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
        ];

        $mockFileService = $this->createMock(FileService::class);
        $mockFileService->method('createShareLink')
            ->willReturn('File not found');

        $mockFileMapper = $this->createMock(FileMapper::class);
        $mockFileMapper->method('getFilesForObject')
            ->willReturn($files);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn($objectEntity);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $result = $this->invokePrivateMethod($this->eventService, 'publishObjectAttachments', [$objectData]);

        $this->assertEquals(0, $result['published']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Failed to create share link', $result['errors'][0]);

    }//end testPublishObjectAttachmentsShareLinkFailure()


    /**
     * Test publishObjectAttachments handles share link exception.
     */
    public function testPublishObjectAttachmentsShareLinkException(): void
    {
        $objectData = [
            '@self' => [
                'uuid' => 'obj-uuid',
                'id'   => 1,
            ],
        ];

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectEntity = new ObjectEntity();

        $files = [
            ['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
        ];

        $mockFileService = $this->createMock(FileService::class);
        $mockFileService->method('createShareLink')
            ->willThrowException(new \Exception('Share creation failed'));

        $mockFileMapper = $this->createMock(FileMapper::class);
        $mockFileMapper->method('getFilesForObject')
            ->willReturn($files);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn($objectEntity);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $result = $this->invokePrivateMethod($this->eventService, 'publishObjectAttachments', [$objectData]);

        $this->assertEquals(0, $result['published']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Exception creating share link', $result['errors'][0]);

    }//end testPublishObjectAttachmentsShareLinkException()


    // ===== handleObjectCreateEvents tests =====

    /**
     * Test handleObjectCreateEvents with empty objects array.
     */
    public function testHandleObjectCreateEventsEmpty(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => false,
                'auto_publish_attachments' => false,
            ]);

        $result = $this->eventService->handleObjectCreateEvents([]);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['published']);
        $this->assertEquals(0, $result['attachmentsPublished']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['details']);

    }//end testHandleObjectCreateEventsEmpty()


    /**
     * Test handleObjectCreateEvents with auto-publish disabled.
     */
    public function testHandleObjectCreateEventsAutoPublishDisabled(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => false,
                'auto_publish_attachments' => false,
            ]);

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'published' => '2024-01-01T00:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['published']);
        $this->assertCount(1, $result['details']);

    }//end testHandleObjectCreateEventsAutoPublishDisabled()


    /**
     * Test handleObjectCreateEvents with auto-publish enabled and object matches catalog.
     */
    public function testHandleObjectCreateEventsAutoPublishSuccess(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => true,
                'auto_publish_attachments' => false,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $catalogMock = new class {
            public function jsonSerialize(): array
            {
                return [
                    'registers' => [1],
                    'schemas'   => [2],
                ];
            }
        };

        $publishedObjectMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getPublished'])
            ->getMock();
        $publishedObjectMock->method('getPublished')
            ->willReturn(new \DateTime('2024-01-15'));

        $mockObjectService = $this->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['searchObjects'])
            ->addMethods(['publish'])
            ->getMock();
        $mockObjectService->method('searchObjects')
            ->willReturn([$catalogMock]);
        $mockObjectService->method('publish')
            ->willReturn($publishedObjectMock);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->settingsServiceMock->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => '100',
                    'catalog_schema'   => '200',
                ],
            ]);

        $objects = [
            [
                '@self' => [
                    'id'       => 1,
                    'uuid'     => 'uuid-1',
                    'register' => '1',
                    'schema'   => '2',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['published']);
        $this->assertContains('object_published', $result['details'][0]['actions']);

    }//end testHandleObjectCreateEventsAutoPublishSuccess()


    /**
     * Test handleObjectCreateEvents with auto-publish enabled but publish fails.
     */
    public function testHandleObjectCreateEventsAutoPublishFails(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => true,
                'auto_publish_attachments' => false,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $catalogMock = new class {
            public function jsonSerialize(): array
            {
                return [
                    'registers' => [1],
                    'schemas'   => [2],
                ];
            }
        };

        $mockObjectService = $this->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['searchObjects'])
            ->addMethods(['publish'])
            ->getMock();
        $mockObjectService->method('searchObjects')
            ->willReturn([$catalogMock]);
        $mockObjectService->method('publish')
            ->willThrowException(new \Exception('Publish failed'));

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->settingsServiceMock->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => '100',
                    'catalog_schema'   => '200',
                ],
            ]);

        $objects = [
            [
                '@self' => [
                    'id'       => 1,
                    'register' => '1',
                    'schema'   => '2',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['published']);
        $this->assertNotEmpty($result['details'][0]['errors']);

    }//end testHandleObjectCreateEventsAutoPublishFails()


    /**
     * Test handleObjectCreateEvents with auto-publish attachments on published object.
     */
    public function testHandleObjectCreateEventsAutoPublishAttachments(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => false,
                'auto_publish_attachments' => true,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectEntity = new ObjectEntity();

        $files = [
            ['name' => 'doc.pdf', 'path' => 'files/doc.pdf', 'share_token' => ''],
        ];

        $mockFileService = $this->createMock(FileService::class);
        $mockFileService->method('createShareLink')
            ->willReturn('https://example.com/share/abc');

        $mockFileMapper = $this->createMock(FileMapper::class);
        $mockFileMapper->method('getFilesForObject')
            ->willReturn($files);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn($objectEntity);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'uuid'      => 'uuid-1',
                    'published' => '2024-01-15T10:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['attachmentsPublished']);
        $this->assertContains('attachments_processed', $result['details'][0]['actions']);

    }//end testHandleObjectCreateEventsAutoPublishAttachments()


    /**
     * Test handleObjectCreateEvents catches per-object exceptions.
     */
    public function testHandleObjectCreateEventsPerObjectException(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => false,
                'auto_publish_attachments' => true,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'published' => '2024-01-15T10:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        // Should still process but record the error in details.
        $this->assertNotEmpty($result['details'][0]['errors']);

    }//end testHandleObjectCreateEventsPerObjectException()


    /**
     * Test handleObjectCreateEvents throws RuntimeException on outer failure.
     */
    public function testHandleObjectCreateEventsOuterException(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willThrowException(new \Exception('Settings failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to handle object create events');

        $this->eventService->handleObjectCreateEvents([['@self' => ['id' => 1]]]);

    }//end testHandleObjectCreateEventsOuterException()


    /**
     * Test handleObjectCreateEvents with object id fallback to 'unknown'.
     */
    public function testHandleObjectCreateEventsUnknownObjectId(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_objects'     => false,
                'auto_publish_attachments' => false,
            ]);

        $objects = [
            ['@self' => []],
        ];

        $result = $this->eventService->handleObjectCreateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals('unknown', $result['details'][0]['objectId']);

    }//end testHandleObjectCreateEventsUnknownObjectId()


    // ===== handleObjectUpdateEvents tests =====

    /**
     * Test handleObjectUpdateEvents with empty objects array.
     */
    public function testHandleObjectUpdateEventsEmpty(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => false,
            ]);

        $result = $this->eventService->handleObjectUpdateEvents([]);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['published']);
        $this->assertEquals(0, $result['attachmentsPublished']);
        $this->assertEmpty($result['errors']);

    }//end testHandleObjectUpdateEventsEmpty()


    /**
     * Test handleObjectUpdateEvents with auto-publish attachments disabled.
     */
    public function testHandleObjectUpdateEventsAttachmentsDisabled(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => false,
            ]);

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'published' => '2024-01-15T10:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectUpdateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['attachmentsPublished']);

    }//end testHandleObjectUpdateEventsAttachmentsDisabled()


    /**
     * Test handleObjectUpdateEvents with auto-publish attachments on published object.
     */
    public function testHandleObjectUpdateEventsWithAttachments(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => true,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $objectEntity = new ObjectEntity();

        $files = [
            ['name' => 'report.pdf', 'path' => 'files/report.pdf', 'share_token' => ''],
        ];

        $mockFileService = $this->createMock(FileService::class);
        $mockFileService->method('createShareLink')
            ->willReturn('https://example.com/share/xyz');

        $mockFileMapper = $this->createMock(FileMapper::class);
        $mockFileMapper->method('getFilesForObject')
            ->willReturn($files);

        $mockObjectService = $this->createMock(ObjectService::class);
        $mockObjectService->method('find')
            ->willReturn($objectEntity);

        $this->containerMock->method('get')
            ->willReturnCallback(function (string $class) use ($mockObjectService, $mockFileService, $mockFileMapper) {
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $mockFileService;
                }

                if ($class === 'OCA\OpenRegister\Db\FileMapper') {
                    return $mockFileMapper;
                }

                return $mockObjectService;
            });

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'uuid'      => 'uuid-1',
                    'published' => '2024-01-15T10:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectUpdateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['attachmentsPublished']);

    }//end testHandleObjectUpdateEventsWithAttachments()


    /**
     * Test handleObjectUpdateEvents skips unpublished objects.
     */
    public function testHandleObjectUpdateEventsSkipsUnpublishedObjects(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => true,
            ]);

        $objects = [
            [
                '@self' => [
                    'id' => 1,
                ],
            ],
        ];

        $result = $this->eventService->handleObjectUpdateEvents($objects);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['attachmentsPublished']);

    }//end testHandleObjectUpdateEventsSkipsUnpublishedObjects()


    /**
     * Test handleObjectUpdateEvents catches per-object exceptions.
     */
    public function testHandleObjectUpdateEventsPerObjectException(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => true,
            ]);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $objects = [
            [
                '@self' => [
                    'id'        => 1,
                    'published' => '2024-01-15T10:00:00+00:00',
                ],
            ],
        ];

        $result = $this->eventService->handleObjectUpdateEvents($objects);

        // Should still process but record the error in details.
        $this->assertNotEmpty($result['details'][0]['errors']);

    }//end testHandleObjectUpdateEventsPerObjectException()


    /**
     * Test handleObjectUpdateEvents throws RuntimeException on outer failure.
     */
    public function testHandleObjectUpdateEventsOuterException(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willThrowException(new \Exception('Settings failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to handle object update events');

        $this->eventService->handleObjectUpdateEvents([['@self' => ['id' => 1]]]);

    }//end testHandleObjectUpdateEventsOuterException()


    /**
     * Test handleObjectUpdateEvents with multiple objects mixed published/unpublished.
     */
    public function testHandleObjectUpdateEventsMultipleObjects(): void
    {
        $this->settingsServiceMock->method('getPublishingOptions')
            ->willReturn([
                'auto_publish_attachments' => false,
            ]);

        $objects = [
            ['@self' => ['id' => 1, 'published' => '2024-01-15T10:00:00+00:00']],
            ['@self' => ['id' => 2]],
            ['@self' => ['id' => 3, 'published' => '2024-02-15T10:00:00+00:00']],
        ];

        $result = $this->eventService->handleObjectUpdateEvents($objects);

        $this->assertEquals(3, $result['processed']);
        $this->assertCount(3, $result['details']);

    }//end testHandleObjectUpdateEventsMultipleObjects()


}//end class
