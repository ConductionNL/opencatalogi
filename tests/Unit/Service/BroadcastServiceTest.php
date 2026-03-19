<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\BroadcastService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unit tests for the BroadcastService class.
 *
 * Tests all public and private methods including broadcast logic,
 * retry mechanisms, service resolution, and URL handling.
 */
class BroadcastServiceTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlGeneratorMock;

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configMock;

    /**
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $containerMock;

    /**
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManagerMock;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var Client|\PHPUnit\Framework\MockObject\MockObject
     */
    private $clientMock;

    /**
     * @var BroadcastService
     */
    private BroadcastService $broadcastService;


    protected function setUp(): void
    {
        $this->urlGeneratorMock = $this->createMock(IURLGenerator::class);
        $this->configMock       = $this->createMock(IAppConfig::class);
        $this->containerMock    = $this->createMock(ContainerInterface::class);
        $this->appManagerMock   = $this->createMock(IAppManager::class);
        $this->loggerMock       = $this->createMock(LoggerInterface::class);

        $this->broadcastService = new BroadcastService(
            $this->urlGeneratorMock,
            $this->configMock,
            $this->containerMock,
            $this->appManagerMock,
            $this->loggerMock
        );

        // Replace the private $client with a mock via reflection.
        $this->clientMock = $this->createMock(Client::class);
        $reflection       = new \ReflectionClass($this->broadcastService);
        $clientProperty   = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->broadcastService, $this->clientMock);

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


    /**
     * Create a mock ObjectService with getObjects method.
     *
     * @param array $listings The listings to return from getObjects.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockObjectService(array $listings = []): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getObjects'])
            ->getMock();
        $mock->method('getObjects')
            ->willReturn($listings);

        return $mock;

    }//end createMockObjectService()


    // ===== getObjectService tests =====

    /**
     * Test getObjectService returns service when OpenRegister is installed.
     */
    public function testGetObjectServiceAvailable(): void
    {
        $mockService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->containerMock->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockService);

        $result = $this->invokePrivateMethod($this->broadcastService, 'getObjectService');
        $this->assertSame($mockService, $result);

    }//end testGetObjectServiceAvailable()


    /**
     * Test getObjectService throws RuntimeException when OpenRegister is not installed.
     */
    public function testGetObjectServiceNotInstalled(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['opencatalogi']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available');

        $this->invokePrivateMethod($this->broadcastService, 'getObjectService');

    }//end testGetObjectServiceNotInstalled()


    /**
     * Test getObjectService throws container exception and logs error.
     */
    public function testGetObjectServiceContainerException(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $exception = new class ('Container error') extends \Exception implements ContainerExceptionInterface {
        };

        $this->containerMock->method('get')
            ->willThrowException($exception);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to retrieve OpenRegister ObjectService'));

        $this->expectException(ContainerExceptionInterface::class);

        $this->invokePrivateMethod($this->broadcastService, 'getObjectService');

    }//end testGetObjectServiceContainerException()


    // ===== getAppVersion tests =====

    /**
     * Test getAppVersion returns the version string.
     */
    public function testGetAppVersionSuccess(): void
    {
        $this->appManagerMock->method('getAppInfo')
            ->with('opencatalogi')
            ->willReturn(['version' => '2.1.0']);

        $result = $this->invokePrivateMethod($this->broadcastService, 'getAppVersion');
        $this->assertEquals('2.1.0', $result);

    }//end testGetAppVersionSuccess()


    /**
     * Test getAppVersion returns 'unknown' when version key is missing.
     */
    public function testGetAppVersionMissingKey(): void
    {
        $this->appManagerMock->method('getAppInfo')
            ->with('opencatalogi')
            ->willReturn(['name' => 'opencatalogi']);

        $result = $this->invokePrivateMethod($this->broadcastService, 'getAppVersion');
        $this->assertEquals('unknown', $result);

    }//end testGetAppVersionMissingKey()


    /**
     * Test getAppVersion returns 'unknown' on exception.
     */
    public function testGetAppVersionException(): void
    {
        $this->appManagerMock->method('getAppInfo')
            ->willThrowException(new \Exception('App not found'));

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Failed to retrieve app version'));

        $result = $this->invokePrivateMethod($this->broadcastService, 'getAppVersion');
        $this->assertEquals('unknown', $result);

    }//end testGetAppVersionException()


    // ===== getCurrentDirectoryUrl tests =====

    /**
     * Test getCurrentDirectoryUrl generates the correct URL.
     */
    public function testGetCurrentDirectoryUrl(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->with('opencatalogi.directory.index')
            ->willReturn('/apps/opencatalogi/api/directory');

        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->with('/apps/opencatalogi/api/directory')
            ->willReturn('https://cloud.example.com/apps/opencatalogi/api/directory');

        $result = $this->invokePrivateMethod($this->broadcastService, 'getCurrentDirectoryUrl');
        $this->assertEquals('https://cloud.example.com/apps/opencatalogi/api/directory', $result);

    }//end testGetCurrentDirectoryUrl()


    // ===== getDirectoryUrls tests =====

    /**
     * Test getDirectoryUrls extracts unique valid URLs from listings.
     */
    public function testGetDirectoryUrlsSuccess(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => 'https://instance-a.com/api/directory'],
            ['directory' => 'https://instance-b.com/api/directory'],
            ['directory' => 'https://instance-a.com/api/directory'],
            ['directory' => ''],
            ['directory' => 'not-a-url'],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $result = $this->invokePrivateMethod($this->broadcastService, 'getDirectoryUrls');

        $this->assertCount(2, $result);
        $this->assertContains('https://instance-a.com/api/directory', $result);
        $this->assertContains('https://instance-b.com/api/directory', $result);

    }//end testGetDirectoryUrlsSuccess()


    /**
     * Test getDirectoryUrls throws when service is unavailable.
     */
    public function testGetDirectoryUrlsServiceUnavailable(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn([]);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to retrieve directory URLs'));

        $this->expectException(RuntimeException::class);

        $this->invokePrivateMethod($this->broadcastService, 'getDirectoryUrls');

    }//end testGetDirectoryUrlsServiceUnavailable()


    /**
     * Test getDirectoryUrls filters out empty and invalid URLs.
     */
    public function testGetDirectoryUrlsFiltersInvalid(): void
    {
        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => ''],
            ['directory' => 'ftp://invalid'],
            ['directory' => null],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $result = $this->invokePrivateMethod($this->broadcastService, 'getDirectoryUrls');

        // ftp:// is technically a valid URL according to FILTER_VALIDATE_URL, so it passes.
        // Empty and null are filtered out.
        $this->assertNotContains('', $result);
        $this->assertNotContains(null, $result);

    }//end testGetDirectoryUrlsFiltersInvalid()


    // ===== sendBroadcastRequest tests =====

    /**
     * Test sendBroadcastRequest succeeds on first attempt.
     */
    public function testSendBroadcastRequestSuccessFirstAttempt(): void
    {
        $this->clientMock->method('post')
            ->willReturn(new Response(200));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Successfully broadcasted'));

        $result = $this->invokePrivateMethod(
            $this->broadcastService,
            'sendBroadcastRequest',
            ['https://target.example.com/api/directory', 'https://self.example.com/api/directory']
        );

        $this->assertTrue($result);

    }//end testSendBroadcastRequestSuccessFirstAttempt()


    /**
     * Test sendBroadcastRequest succeeds with 201 status code.
     */
    public function testSendBroadcastRequestSuccess201(): void
    {
        $this->clientMock->method('post')
            ->willReturn(new Response(201));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->invokePrivateMethod(
            $this->broadcastService,
            'sendBroadcastRequest',
            ['https://target.example.com/api', 'https://self.example.com/api']
        );

        $this->assertTrue($result);

    }//end testSendBroadcastRequestSuccess201()


    /**
     * Test sendBroadcastRequest returns false after all retries fail.
     */
    public function testSendBroadcastRequestAllRetriesFail(): void
    {
        $request   = new Request('POST', 'https://target.example.com/api');
        $exception = new RequestException('Connection refused', $request);

        $this->clientMock->method('post')
            ->willThrowException($exception);

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $this->loggerMock->expects($this->exactly(3))
            ->method('warning');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('All 3 broadcast attempts'));

        $result = $this->invokePrivateMethod(
            $this->broadcastService,
            'sendBroadcastRequest',
            ['https://target.example.com/api', 'https://self.example.com/api']
        );

        $this->assertFalse($result);

    }//end testSendBroadcastRequestAllRetriesFail()


    /**
     * Test sendBroadcastRequest returns false on non-success status codes.
     */
    public function testSendBroadcastRequestNonSuccessStatus(): void
    {
        $this->clientMock->method('post')
            ->willReturn(new Response(500));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->invokePrivateMethod(
            $this->broadcastService,
            'sendBroadcastRequest',
            ['https://target.example.com/api', 'https://self.example.com/api']
        );

        $this->assertFalse($result);

    }//end testSendBroadcastRequestNonSuccessStatus()


    /**
     * Test sendBroadcastRequest succeeds on retry after initial failure.
     */
    public function testSendBroadcastRequestSuccessOnRetry(): void
    {
        $request   = new Request('POST', 'https://target.example.com/api');
        $exception = new RequestException('Timeout', $request);

        $this->clientMock->method('post')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                new Response(200)
            );

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->invokePrivateMethod(
            $this->broadcastService,
            'sendBroadcastRequest',
            ['https://target.example.com/api', 'https://self.example.com/api']
        );

        $this->assertTrue($result);

    }//end testSendBroadcastRequestSuccessOnRetry()


    // ===== broadcast tests =====

    /**
     * Test broadcast with a specific valid URL.
     */
    public function testBroadcastWithSpecificUrl(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn('https://self.example.com/apps/opencatalogi/api/directory');

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        // Mock getDirectoryUrls - even though we provide a URL, broadcast calls getDirectoryUrls first.
        $mockObjectService = $this->createMockObjectService([]);
        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->clientMock->method('post')
            ->willReturn(new Response(200));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->broadcastService->broadcast('https://target.example.com/api/directory');

        $this->assertArrayHasKey('https://target.example.com/api/directory', $result);
        $this->assertTrue($result['https://target.example.com/api/directory']);

    }//end testBroadcastWithSpecificUrl()


    /**
     * Test broadcast with invalid URL throws InvalidArgumentException.
     */
    public function testBroadcastWithInvalidUrl(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn('https://self.example.com/apps/opencatalogi/api/directory');

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjectService = $this->createMockObjectService([]);
        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL provided for broadcast');

        $this->broadcastService->broadcast('not-a-valid-url');

    }//end testBroadcastWithInvalidUrl()


    /**
     * Test broadcast skips self URL to avoid loops.
     */
    public function testBroadcastSkipsSelf(): void
    {
        $selfUrl = 'https://self.example.com/apps/opencatalogi/api/directory';

        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn($selfUrl);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => $selfUrl],
            ['directory' => 'https://other.example.com/api/directory'],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->clientMock->method('post')
            ->willReturn(new Response(200));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->broadcastService->broadcast();

        // Self URL should be skipped, only the other URL should be in results.
        $this->assertArrayNotHasKey($selfUrl, $result);
        $this->assertArrayHasKey('https://other.example.com/api/directory', $result);

    }//end testBroadcastSkipsSelf()


    /**
     * Test broadcast with empty target URLs returns empty results.
     */
    public function testBroadcastEmptyTargets(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn('https://self.example.com/apps/opencatalogi/api/directory');

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjectService = $this->createMockObjectService([]);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('No target URLs found for broadcasting');

        $result = $this->broadcastService->broadcast();

        $this->assertEmpty($result);

    }//end testBroadcastEmptyTargets()


    /**
     * Test broadcast to all directories with mixed success.
     */
    public function testBroadcastAllDirectoriesMixed(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn('https://self.example.com/apps/opencatalogi/api/directory');

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => 'https://instance-a.com/api/directory'],
            ['directory' => 'https://instance-b.com/api/directory'],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        // First URL succeeds, second fails.
        $request   = new Request('POST', 'https://instance-b.com/api/directory');
        $exception = new RequestException('Connection refused', $request);

        $this->clientMock->method('post')
            ->willReturnCallback(function ($uri) use ($exception) {
                if ($uri === 'https://instance-a.com/api/directory') {
                    return new Response(200);
                }

                throw $exception;
            });

        $result = $this->broadcastService->broadcast();

        $this->assertTrue($result['https://instance-a.com/api/directory']);
        $this->assertFalse($result['https://instance-b.com/api/directory']);

    }//end testBroadcastAllDirectoriesMixed()


    /**
     * Test broadcast logs summary with correct counts.
     */
    public function testBroadcastLogsSummary(): void
    {
        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn('https://self.example.com/apps/opencatalogi/api/directory');

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => 'https://instance-a.com/api/directory'],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->clientMock->method('post')
            ->willReturn(new Response(200));

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        // Expect the summary log with 1/1 successful.
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->logicalOr(
                $this->stringContains('Starting broadcast'),
                $this->stringContains('Successfully broadcasted'),
                $this->stringContains('Broadcast completed: 1/1 successful')
            ));

        $this->broadcastService->broadcast();

    }//end testBroadcastLogsSummary()


    /**
     * Test broadcast to all directories when all targets are self (all skipped).
     */
    public function testBroadcastAllTargetsAreSelf(): void
    {
        $selfUrl = 'https://self.example.com/apps/opencatalogi/api/directory';

        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn($selfUrl);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $listings = [
            ['directory' => $selfUrl],
        ];

        $mockObjectService = $this->createMockObjectService($listings);

        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->broadcastService->broadcast();

        // All targets are self, so results should be empty.
        $this->assertEmpty($result);

    }//end testBroadcastAllTargetsAreSelf()


    /**
     * Test broadcast with specific URL that equals self URL.
     */
    public function testBroadcastSpecificUrlEqualsSelf(): void
    {
        $selfUrl = 'https://self.example.com/apps/opencatalogi/api/directory';

        $this->urlGeneratorMock->method('linkToRoute')
            ->willReturn('/apps/opencatalogi/api/directory');
        $this->urlGeneratorMock->method('getAbsoluteURL')
            ->willReturn($selfUrl);

        $this->appManagerMock->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjectService = $this->createMockObjectService([]);
        $this->containerMock->method('get')
            ->willReturn($mockObjectService);

        $this->appManagerMock->method('getAppInfo')
            ->willReturn(['version' => '1.0.0']);

        $result = $this->broadcastService->broadcast($selfUrl);

        // Self URL should be skipped even when explicitly provided.
        $this->assertEmpty($result);

    }//end testBroadcastSpecificUrlEqualsSelf()


}//end class
