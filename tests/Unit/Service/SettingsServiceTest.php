<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * Unit tests for SettingsService.
 *
 * @category Tests
 * @package  Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenCatalogi.nl
 */
class SettingsServiceTest extends \PHPUnit\Framework\TestCase
{

    /** @var IAppConfig|MockObject */
    private IAppConfig|MockObject $config;

    /** @var IRequest|MockObject */
    private IRequest|MockObject $request;

    /** @var ContainerInterface|MockObject */
    private ContainerInterface|MockObject $container;

    /** @var IAppManager|MockObject */
    private IAppManager|MockObject $appManager;

    /** @var SettingsService */
    private SettingsService $service;


    protected function setUp(): void
    {
        $this->config     = $this->createMock(IAppConfig::class);
        $this->request    = $this->createMock(IRequest::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $this->service = new SettingsService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager
        );

    }


    /**
     * Helper: invoke a private method via reflection.
     *
     * @param object $object     The object instance.
     * @param string $methodName The private method name.
     * @param array  $parameters The parameters to pass.
     *
     * @return mixed The return value of the method.
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);

    }


    /**
     * Create a mock ConfigurationService.
     *
     * @param string|null $storedVersion The version to return from getConfiguredAppVersion.
     *
     * @return ConfigurationService|MockObject
     */
    private function createConfigServiceMock(?string $storedVersion = null): ConfigurationService|MockObject
    {
        $mock = $this->createMock(ConfigurationService::class);
        $mock->method('getConfiguredAppVersion')
            ->willReturn($storedVersion);

        return $mock;

    }


    /**
     * Create a mock RegisterMapper that returns specified registers.
     *
     * @param array $registers The registers to return from findAll.
     *
     * @return RegisterMapper|MockObject
     */
    private function createRegisterMapperMock(array $registers = []): RegisterMapper|MockObject
    {
        $mock = $this->createMock(RegisterMapper::class);
        $mock->method('findAll')
            ->willReturn($registers);

        return $mock;

    }


    /**
     * Create a mock SchemaMapper with a find callback.
     *
     * @param array $schemaMap Map of id => array data for jsonSerialize.
     *
     * @return SchemaMapper|MockObject
     */
    private function createSchemaMapperMock(array $schemaMap = []): SchemaMapper|MockObject
    {
        $mock = $this->createMock(SchemaMapper::class);

        if (empty($schemaMap) === false) {
            $mock->method('find')
                ->willReturnCallback(function (int $id) use ($schemaMap) {
                    if (isset($schemaMap[$id]) === false) {
                        throw new \Exception('Schema not found');
                    }
                    $schemaMock = $this->createMock(Schema::class);
                    $schemaMock->method('jsonSerialize')
                        ->willReturn($schemaMap[$id]);
                    return $schemaMock;
                });
        }

        return $mock;

    }


    // ---------------------------------------------------------------
    // isOpenRegisterInstalled
    // ---------------------------------------------------------------

    public function testIsOpenRegisterInstalledReturnsTrueWhenInstalledAndVersionMet(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->with('openregister')
            ->willReturn('1.0.0');

        $this->assertTrue($this->service->isOpenRegisterInstalled('0.1.7'));

    }


    public function testIsOpenRegisterInstalledReturnsFalseWhenNotInstalled(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(false);

        $this->assertFalse($this->service->isOpenRegisterInstalled());

    }


    public function testIsOpenRegisterInstalledReturnsTrueWithNullVersion(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->assertTrue($this->service->isOpenRegisterInstalled(null));

    }


    public function testIsOpenRegisterInstalledReturnsFalseWhenVersionTooLow(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->with('openregister')
            ->willReturn('0.1.0');

        $this->assertFalse($this->service->isOpenRegisterInstalled('0.2.0'));

    }


    public function testIsOpenRegisterInstalledReturnsTrueWhenVersionEqual(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->with('openregister')
            ->willReturn('0.1.7');

        $this->assertTrue($this->service->isOpenRegisterInstalled('0.1.7'));

    }


    public function testIsOpenRegisterInstalledUsesDefaultMinVersion(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->with('openregister')
            ->willReturn('0.1.7');

        $this->assertTrue($this->service->isOpenRegisterInstalled());

    }


    // ---------------------------------------------------------------
    // isOpenRegisterEnabled
    // ---------------------------------------------------------------

    public function testIsOpenRegisterEnabledReturnsTrue(): void
    {
        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(true);

        $this->assertTrue($this->service->isOpenRegisterEnabled());

    }


    public function testIsOpenRegisterEnabledReturnsFalse(): void
    {
        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(false);

        $this->assertFalse($this->service->isOpenRegisterEnabled());

    }


    // ---------------------------------------------------------------
    // getObjectService
    // ---------------------------------------------------------------

    public function testGetObjectServiceReturnsServiceWhenAvailable(): void
    {
        $mockObjectService = $this->createMock(ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister', 'opencatalogi']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->getObjectService();
        $this->assertSame($mockObjectService, $result);

    }


    public function testGetObjectServiceThrowsWhenNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['opencatalogi']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getObjectService();

    }


    // ---------------------------------------------------------------
    // getRegisterMapper
    // ---------------------------------------------------------------

    public function testGetRegisterMapperReturnsMapperWhenAvailable(): void
    {
        $mockMapper = $this->createMock(RegisterMapper::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Db\RegisterMapper')
            ->willReturn($mockMapper);

        $result = $this->service->getRegisterMapper();
        $this->assertSame($mockMapper, $result);

    }


    public function testGetRegisterMapperThrowsWhenNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RegisterMapper is not available.');

        $this->service->getRegisterMapper();

    }


    // ---------------------------------------------------------------
    // getSchemaMapper
    // ---------------------------------------------------------------

    public function testGetSchemaMapperReturnsMapperWhenAvailable(): void
    {
        $mockMapper = $this->createMock(SchemaMapper::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Db\SchemaMapper')
            ->willReturn($mockMapper);

        $result = $this->service->getSchemaMapper();
        $this->assertSame($mockMapper, $result);

    }


    public function testGetSchemaMapperThrowsWhenNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SchemaMapper is not available.');

        $this->service->getSchemaMapper();

    }


    // ---------------------------------------------------------------
    // getConfigurationService
    // ---------------------------------------------------------------

    public function testGetConfigurationServiceReturnsServiceWhenAvailable(): void
    {
        $mockService = $this->createMock(ConfigurationService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($mockService);

        $result = $this->service->getConfigurationService();
        $this->assertSame($mockService, $result);

    }


    public function testGetConfigurationServiceThrowsWhenNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration service is not available.');

        $this->service->getConfigurationService();

    }


    // ---------------------------------------------------------------
    // getSettings
    // ---------------------------------------------------------------

    public function testGetSettingsWithOpenRegisterAvailable(): void
    {
        $mockRegister = $this->createMock(Register::class);
        $mockRegister->method('jsonSerialize')
            ->willReturn([
                'id'      => 1,
                'slug'    => 'publication',
                'schemas' => [1, 2],
            ]);

        $mockRegisterMapper = $this->createRegisterMapperMock([$mockRegister]);
        $mockSchemaMapper   = $this->createSchemaMapperMock([
            1 => ['id' => 1, 'title' => 'Schema 1', 'slug' => 'schema-1'],
            2 => ['id' => 2, 'title' => 'Schema 2', 'slug' => 'schema-2'],
        ]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockRegisterMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\RegisterMapper') {
                    return $mockRegisterMapper;
                }
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return null;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->getSettings();

        $this->assertTrue($result['openRegisters']);
        $this->assertNotEmpty($result['availableRegisters']);
        $this->assertArrayHasKey('objectTypes', $result);
        $this->assertArrayHasKey('configuration', $result);
        $this->assertContains('catalog', $result['objectTypes']);
        $this->assertContains('listing', $result['objectTypes']);

    }


    public function testGetSettingsWithoutOpenRegister(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->getSettings();

        $this->assertFalse($result['openRegisters']);
        $this->assertEmpty($result['availableRegisters']);
        $this->assertArrayHasKey('objectTypes', $result);
        $this->assertArrayHasKey('configuration', $result);

    }


    public function testGetSettingsObjectTypesContainAllExpectedTypes(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->getSettings();

        $expectedTypes = ['catalog', 'listing', 'organization', 'theme', 'page', 'menu', 'glossary'];
        $this->assertSame($expectedTypes, $result['objectTypes']);

    }


    public function testGetSettingsConfigurationContainsAllKeys(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->getSettings();

        $types = ['catalog', 'listing', 'organization', 'theme', 'page', 'menu', 'glossary'];
        foreach ($types as $type) {
            $this->assertArrayHasKey("{$type}_source", $result['configuration']);
            $this->assertArrayHasKey("{$type}_schema", $result['configuration']);
            $this->assertArrayHasKey("{$type}_register", $result['configuration']);
        }

        $this->assertArrayHasKey('auto_publish_attachments', $result['configuration']);
        $this->assertArrayHasKey('auto_publish_objects', $result['configuration']);
        $this->assertArrayHasKey('use_old_style_publishing_view', $result['configuration']);

    }


    public function testGetSettingsDefaultSourceIsOpenregister(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->getSettings();

        $types = ['catalog', 'listing', 'organization', 'theme', 'page', 'menu', 'glossary'];
        foreach ($types as $type) {
            $this->assertSame('openregister', $result['configuration']["{$type}_source"]);
        }

    }


    public function testGetSettingsThrowsOnConfigError(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willThrowException(new \Exception('Config read error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve settings/');

        $this->service->getSettings();

    }


    // ---------------------------------------------------------------
    // updateSettings
    // ---------------------------------------------------------------

    public function testUpdateSettingsSuccess(): void
    {
        $inputData = [
            'catalog_source'   => 'openregister',
            'catalog_schema'   => '5',
            'catalog_register' => '1',
        ];

        $this->config->expects($this->exactly(3))
            ->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) {
                $this->assertSame('opencatalogi', $app);
                return true;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key) use ($inputData) {
                return $inputData[$key] ?? '';
            });

        $result = $this->service->updateSettings($inputData);

        $this->assertSame('openregister', $result['catalog_source']);
        $this->assertSame('5', $result['catalog_schema']);
        $this->assertSame('1', $result['catalog_register']);

    }


    public function testUpdateSettingsThrowsOnFailure(): void
    {
        $this->config->method('setValueString')
            ->willThrowException(new \Exception('Write error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to update settings/');

        $this->service->updateSettings(['key' => 'value']);

    }


    public function testUpdateSettingsEmptyArray(): void
    {
        $result = $this->service->updateSettings([]);
        $this->assertSame([], $result);

    }


    // ---------------------------------------------------------------
    // getPublishingOptions
    // ---------------------------------------------------------------

    public function testGetPublishingOptionsAllFalse(): void
    {
        $this->config->method('getValueString')
            ->willReturn('false');

        $result = $this->service->getPublishingOptions();

        $this->assertFalse($result['auto_publish_attachments']);
        $this->assertFalse($result['auto_publish_objects']);
        $this->assertFalse($result['use_old_style_publishing_view']);

    }


    public function testGetPublishingOptionsAllTrue(): void
    {
        $this->config->method('getValueString')
            ->willReturn('true');

        $result = $this->service->getPublishingOptions();

        $this->assertTrue($result['auto_publish_attachments']);
        $this->assertTrue($result['auto_publish_objects']);
        $this->assertTrue($result['use_old_style_publishing_view']);

    }


    public function testGetPublishingOptionsMixed(): void
    {
        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = 'false') {
                if ($key === 'auto_publish_attachments') {
                    return 'true';
                }
                return 'false';
            });

        $result = $this->service->getPublishingOptions();

        $this->assertTrue($result['auto_publish_attachments']);
        $this->assertFalse($result['auto_publish_objects']);
        $this->assertFalse($result['use_old_style_publishing_view']);

    }


    public function testGetPublishingOptionsThrowsOnError(): void
    {
        $this->config->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve publishing options/');

        $this->service->getPublishingOptions();

    }


    // ---------------------------------------------------------------
    // updatePublishingOptions
    // ---------------------------------------------------------------

    public function testUpdatePublishingOptionsWithBooleanTrue(): void
    {
        $this->config->method('setValueString')->willReturn(true);
        $this->config->method('getValueString')
            ->willReturn('true');

        $result = $this->service->updatePublishingOptions([
            'auto_publish_attachments' => true,
        ]);

        $this->assertTrue($result['auto_publish_attachments']);

    }


    public function testUpdatePublishingOptionsWithStringTrue(): void
    {
        $this->config->method('setValueString')->willReturn(true);
        $this->config->method('getValueString')
            ->willReturn('true');

        $result = $this->service->updatePublishingOptions([
            'auto_publish_objects' => 'true',
        ]);

        $this->assertTrue($result['auto_publish_objects']);

    }


    public function testUpdatePublishingOptionsWithFalseValue(): void
    {
        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('opencatalogi', 'auto_publish_attachments', 'false')
            ->willReturn(true);

        $this->config->method('getValueString')
            ->willReturn('false');

        $result = $this->service->updatePublishingOptions([
            'auto_publish_attachments' => false,
        ]);

        $this->assertFalse($result['auto_publish_attachments']);

    }


    public function testUpdatePublishingOptionsIgnoresInvalidKeys(): void
    {
        $this->config->expects($this->never())
            ->method('setValueString')->willReturn(true);

        $result = $this->service->updatePublishingOptions([
            'invalid_option' => 'true',
        ]);

        $this->assertEmpty($result);

    }


    public function testUpdatePublishingOptionsMultipleOptions(): void
    {
        $this->config->expects($this->exactly(3))
            ->method('setValueString')->willReturn(true);

        $this->config->method('getValueString')
            ->willReturn('true');

        $result = $this->service->updatePublishingOptions([
            'auto_publish_attachments'      => true,
            'auto_publish_objects'          => true,
            'use_old_style_publishing_view' => true,
        ]);

        $this->assertCount(3, $result);
        $this->assertTrue($result['auto_publish_attachments']);
        $this->assertTrue($result['auto_publish_objects']);
        $this->assertTrue($result['use_old_style_publishing_view']);

    }


    public function testUpdatePublishingOptionsThrowsOnError(): void
    {
        $this->config->method('setValueString')
            ->willThrowException(new \Exception('Write error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to update publishing options/');

        $this->service->updatePublishingOptions([
            'auto_publish_attachments' => true,
        ]);

    }


    public function testUpdatePublishingOptionsBooleanConversionForZero(): void
    {
        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('opencatalogi', 'auto_publish_attachments', 'false')
            ->willReturn(true);

        $this->config->method('getValueString')
            ->willReturn('false');

        $result = $this->service->updatePublishingOptions([
            'auto_publish_attachments' => 0,
        ]);

        $this->assertFalse($result['auto_publish_attachments']);

    }


    public function testUpdatePublishingOptionsEmptyInput(): void
    {
        $this->config->expects($this->never())
            ->method('setValueString')->willReturn(true);

        $result = $this->service->updatePublishingOptions([]);

        $this->assertEmpty($result);

    }


    // ---------------------------------------------------------------
    // getVersionInfo
    // ---------------------------------------------------------------

    public function testGetVersionInfoVersionsMatch(): void
    {
        $mockConfigService = $this->createConfigServiceMock('2.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($mockConfigService);

        $result = $this->service->getVersionInfo();

        $this->assertSame('OpenCatalogi', $result['appName']);
        $this->assertSame('2.0.0', $result['appVersion']);
        $this->assertSame('2.0.0', $result['configuredVersion']);
        $this->assertTrue($result['versionsMatch']);
        $this->assertFalse($result['needsUpdate']);

    }


    public function testGetVersionInfoVersionsDontMatch(): void
    {
        $mockConfigService = $this->createConfigServiceMock('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($mockConfigService);

        $result = $this->service->getVersionInfo();

        $this->assertFalse($result['versionsMatch']);
        $this->assertTrue($result['needsUpdate']);

    }


    public function testGetVersionInfoNoStoredVersion(): void
    {
        $mockConfigService = $this->createConfigServiceMock(null);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('1.0.0');

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($mockConfigService);

        $result = $this->service->getVersionInfo();

        $this->assertFalse($result['versionsMatch']);
        $this->assertTrue($result['needsUpdate']);
        $this->assertNull($result['configuredVersion']);

    }


    public function testGetVersionInfoThrowsOnError(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->appManager->method('getAppVersion')
            ->willThrowException(new \Exception('Version error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to get version information/');

        $this->service->getVersionInfo();

    }


    public function testGetVersionInfoStoredVersionNewer(): void
    {
        $mockConfigService = $this->createConfigServiceMock('3.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->service->getVersionInfo();

        $this->assertFalse($result['versionsMatch']);
        $this->assertFalse($result['needsUpdate']);

    }


    // ---------------------------------------------------------------
    // manualImport
    // ---------------------------------------------------------------

    public function testManualImportVersionsMatchNoForce(): void
    {
        $mockConfigService = $this->createConfigServiceMock('2.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->service->manualImport(false);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already up to date', $result['message']);
        $this->assertArrayHasKey('versionInfo', $result);

    }


    public function testManualImportForced(): void
    {
        $mockConfigService = $this->createConfigServiceMock('2.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->willReturn('2.0.0');

        // getAppPath will fail since we don't have the actual app path.
        $this->appManager->method('getAppPath')
            ->willThrowException(new \Exception('App path not found'));

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->service->manualImport(true);

        // loadSettings will throw, caught by manualImport.
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

    }


    public function testManualImportNeedsUpdate(): void
    {
        $mockConfigService = $this->createConfigServiceMock('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->willReturn('2.0.0');

        // loadSettings will fail due to no app path.
        $this->appManager->method('getAppPath')
            ->willThrowException(new \Exception('App path not found'));

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->service->manualImport(false);

        // Versions don't match, so import is attempted, but loadSettings fails.
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

    }


    public function testManualImportSuccessWithValidFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/opencatalogi_test_' . uniqid();
        mkdir($tmpDir . '/lib/Settings', 0777, true);
        $jsonData = json_encode([
            'x-openregister' => ['sourceUrl' => 'test', 'sourceType' => 'local'],
        ]);
        file_put_contents($tmpDir . '/lib/Settings/publication_register.json', $jsonData);

        $mockConfigService = $this->createMock(ConfigurationService::class);
        $mockConfigService->method('getConfiguredAppVersion')
            ->willReturn('1.0.0');
        $mockConfigService->method('importFromApp')
            ->willReturn(['registers' => [], 'schemas' => []]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->willReturn('2.0.0');

        $this->appManager->method('getAppPath')
            ->willReturn($tmpDir);

        $this->container->method('get')
            ->willReturn($mockConfigService);

        try {
            $result = $this->service->manualImport(false);
            $this->assertTrue($result['success']);
            $this->assertStringContainsString('successfully', $result['message']);
        } finally {
            unlink($tmpDir . '/lib/Settings/publication_register.json');
            rmdir($tmpDir . '/lib/Settings');
            rmdir($tmpDir . '/lib');
            rmdir($tmpDir);
        }

    }


    // ---------------------------------------------------------------
    // loadSettings
    // ---------------------------------------------------------------

    public function testLoadSettingsFileNotFound(): void
    {
        $this->appManager->method('getAppPath')
            ->with('opencatalogi')
            ->willReturn('/tmp/nonexistent-app-path');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to load settings/');

        $this->service->loadSettings();

    }


    public function testLoadSettingsInvalidJson(): void
    {
        $tmpDir = sys_get_temp_dir() . '/opencatalogi_test_' . uniqid();
        mkdir($tmpDir . '/lib/Settings', 0777, true);
        file_put_contents($tmpDir . '/lib/Settings/publication_register.json', '{invalid json}');

        $this->appManager->method('getAppPath')
            ->with('opencatalogi')
            ->willReturn($tmpDir);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to load settings/');
            $this->service->loadSettings();
        } finally {
            unlink($tmpDir . '/lib/Settings/publication_register.json');
            rmdir($tmpDir . '/lib/Settings');
            rmdir($tmpDir . '/lib');
            rmdir($tmpDir);
        }

    }


    public function testLoadSettingsValidJsonCallsImport(): void
    {
        $tmpDir = sys_get_temp_dir() . '/opencatalogi_test_' . uniqid();
        mkdir($tmpDir . '/lib/Settings', 0777, true);
        $jsonData = json_encode([
            'x-openregister' => [
                'sourceUrl'  => 'test/path',
                'sourceType' => 'local',
            ],
            'registers' => [],
            'schemas'   => [],
        ]);
        file_put_contents($tmpDir . '/lib/Settings/publication_register.json', $jsonData);

        $mockConfigService = $this->createMock(ConfigurationService::class);
        $mockConfigService->method('importFromApp')
            ->willReturn(['registers' => [], 'schemas' => []]);

        $this->appManager->method('getAppPath')
            ->with('opencatalogi')
            ->willReturn($tmpDir);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($mockConfigService);

        try {
            $result = $this->service->loadSettings();
            $this->assertIsArray($result);
            $this->assertArrayHasKey('registers', $result);
            $this->assertArrayHasKey('schemas', $result);
        } finally {
            unlink($tmpDir . '/lib/Settings/publication_register.json');
            rmdir($tmpDir . '/lib/Settings');
            rmdir($tmpDir . '/lib');
            rmdir($tmpDir);
        }

    }


    public function testLoadSettingsForceFlag(): void
    {
        $tmpDir = sys_get_temp_dir() . '/opencatalogi_test_' . uniqid();
        mkdir($tmpDir . '/lib/Settings', 0777, true);
        $jsonData = json_encode(['data' => 'test']);
        file_put_contents($tmpDir . '/lib/Settings/publication_register.json', $jsonData);

        $mockConfigService = $this->createMock(ConfigurationService::class);
        $mockConfigService->expects($this->once())
            ->method('importFromApp')
            ->with(
                $this->equalTo('opencatalogi'),
                $this->isType('array'),
                $this->equalTo('1.0.0'),
                $this->isTrue()
            )
            ->willReturn(['registers' => [], 'schemas' => []]);

        $this->appManager->method('getAppPath')
            ->willReturn($tmpDir);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockConfigService);

        try {
            $this->service->loadSettings(true);
        } finally {
            unlink($tmpDir . '/lib/Settings/publication_register.json');
            rmdir($tmpDir . '/lib/Settings');
            rmdir($tmpDir . '/lib');
            rmdir($tmpDir);
        }

    }


    public function testLoadSettingsAddsOpenregisterMetadata(): void
    {
        $tmpDir = sys_get_temp_dir() . '/opencatalogi_test_' . uniqid();
        mkdir($tmpDir . '/lib/Settings', 0777, true);
        // JSON without x-openregister metadata.
        $jsonData = json_encode(['someKey' => 'someValue']);
        file_put_contents($tmpDir . '/lib/Settings/publication_register.json', $jsonData);

        $capturedData = null;
        $mockConfigService = $this->createMock(ConfigurationService::class);
        $mockConfigService->method('importFromApp')
            ->willReturnCallback(function (string $appId, array $data, string $version, bool $force) use (&$capturedData) {
                $capturedData = $data;
                return ['registers' => [], 'schemas' => []];
            });

        $this->appManager->method('getAppPath')
            ->willReturn($tmpDir);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockConfigService);

        try {
            $this->service->loadSettings();
            $this->assertArrayHasKey('x-openregister', $capturedData);
            $this->assertArrayHasKey('sourceUrl', $capturedData['x-openregister']);
            $this->assertSame('local', $capturedData['x-openregister']['sourceType']);
        } finally {
            unlink($tmpDir . '/lib/Settings/publication_register.json');
            rmdir($tmpDir . '/lib/Settings');
            rmdir($tmpDir . '/lib');
            rmdir($tmpDir);
        }

    }


    // ---------------------------------------------------------------
    // autoConfigure
    // ---------------------------------------------------------------

    public function testAutoConfigureEmptyRegisters(): void
    {
        $mockMapper = $this->createRegisterMapperMock([]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockMapper);

        $result = $this->service->autoConfigure();

        $this->assertSame([], $result);

    }


    public function testAutoConfigureWithMatchingRegister(): void
    {
        $mockMapper = $this->createRegisterMapperMock([
            [
                'id'      => 1,
                'slug'    => 'publication',
                'schemas' => [
                    ['id' => 10, 'title' => 'catalog'],
                    ['id' => 11, 'title' => 'listing'],
                ],
            ],
        ]);

        $mockSchemaMapper = $this->createSchemaMapperMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return $mockMapper;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->autoConfigure();

        $this->assertArrayHasKey('catalog_register', $result);
        $this->assertSame(1, $result['catalog_register']);
        $this->assertArrayHasKey('catalog_schema', $result);
        $this->assertSame(10, $result['catalog_schema']);
        $this->assertArrayHasKey('listing_register', $result);
        $this->assertArrayHasKey('listing_schema', $result);

    }


    public function testAutoConfigureWithRegisterObjectEntities(): void
    {
        $mockRegister = $this->createMock(Register::class);
        $mockRegister->method('jsonSerialize')
            ->willReturn([
                'id'      => 5,
                'slug'    => 'my-publication-register',
                'schemas' => [
                    ['id' => 20, 'title' => 'organization'],
                ],
            ]);

        $mockMapper = $this->createRegisterMapperMock([$mockRegister]);
        $mockSchemaMapper = $this->createSchemaMapperMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return $mockMapper;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->autoConfigure();

        $this->assertArrayHasKey('organization_register', $result);
        $this->assertSame(5, $result['organization_register']);

    }


    public function testAutoConfigureNoMatchingRegister(): void
    {
        $mockMapper = $this->createRegisterMapperMock([
            [
                'id'      => 1,
                'slug'    => 'completely-different',
                'schemas' => [],
            ],
        ]);

        $mockSchemaMapper = $this->createSchemaMapperMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return $mockMapper;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->autoConfigure();

        $this->assertEmpty($result);

    }


    public function testAutoConfigureThrowsOnError(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to auto-configure/');

        $this->service->autoConfigure();

    }


    public function testAutoConfigureWithEmptySchemas(): void
    {
        $mockMapper = $this->createRegisterMapperMock([
            [
                'id'      => 1,
                'slug'    => 'publication',
                'schemas' => [],
            ],
        ]);

        $mockSchemaMapper = $this->createSchemaMapperMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return $mockMapper;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->autoConfigure();

        // Register matches but no schemas to match.
        $this->assertArrayHasKey('catalog_register', $result);
        $this->assertArrayNotHasKey('catalog_schema', $result);

    }


    public function testAutoConfigureSkipsNonArraySchemas(): void
    {
        $mockMapper = $this->createRegisterMapperMock([
            [
                'id'      => 1,
                'slug'    => 'publication',
                'schemas' => ['not-an-array', 42],
            ],
        ]);

        $mockSchemaMapper = $this->createSchemaMapperMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockMapper, $mockSchemaMapper) {
                if ($class === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mockSchemaMapper;
                }
                return $mockMapper;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->autoConfigure();

        // Register matches, but non-array schemas are skipped.
        $this->assertArrayHasKey('catalog_register', $result);
        $this->assertArrayNotHasKey('catalog_schema', $result);

    }


    // ---------------------------------------------------------------
    // initialize
    // ---------------------------------------------------------------

    public function testInitializeSuccess(): void
    {
        $this->appManager->method('isInstalled')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->willReturnCallback(function (string $appId) {
                if ($appId === 'openregister') {
                    return '1.0.0';
                }
                return '2.0.0';
            });

        $mockRegisterMapper = $this->createRegisterMapperMock([]);
        $mockConfigService  = $this->createConfigServiceMock('2.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockRegisterMapper, $mockConfigService) {
                if ($class === 'OCA\OpenRegister\Db\RegisterMapper') {
                    return $mockRegisterMapper;
                }
                if ($class === 'OCA\OpenRegister\Service\ConfigurationService') {
                    return $mockConfigService;
                }
                return null;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $result = $this->service->initialize();

        $this->assertTrue($result['openRegister']);
        $this->assertTrue($result['settingsLoaded']);
        $this->assertEmpty($result['errors']);

    }


    public function testInitializeWithPartialFailure(): void
    {
        $this->markTestSkipped('OC_App::installApp() is not available in unit test context');

    }


    public function testInitializeAutoConfiguresWhenNotEmpty(): void
    {
        $this->appManager->method('isInstalled')
            ->willReturn(true);

        $this->appManager->method('getAppVersion')
            ->willReturnCallback(function (string $appId) {
                return '1.0.0';
            });

        $mockRegisterMapper = $this->createRegisterMapperMock([
            [
                'id'      => 1,
                'slug'    => 'publication',
                'schemas' => [
                    ['id' => 10, 'title' => 'catalog'],
                ],
            ],
        ]);

        $mockConfigService = $this->createConfigServiceMock('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($mockRegisterMapper, $mockConfigService) {
                if ($class === 'OCA\OpenRegister\Db\RegisterMapper') {
                    return $mockRegisterMapper;
                }
                if ($class === 'OCA\OpenRegister\Service\ConfigurationService') {
                    return $mockConfigService;
                }
                return null;
            });

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return $default;
            });

        $this->config->method('setValueString')->willReturn(true);

        $result = $this->service->initialize();

        $this->assertTrue($result['openRegister']);
        $this->assertTrue($result['autoConfigured']);

    }


    // ---------------------------------------------------------------
    // Private: enrichRegistersWithSchemas
    // ---------------------------------------------------------------

    public function testEnrichRegistersWithSchemasEmptyArray(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockSchemaMapper = $this->createSchemaMapperMock();
        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $result = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [[]]);
        $this->assertSame([], $result);

    }


    public function testEnrichRegistersWithSchemasNoSchemaMapper(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $registers = [['id' => 1, 'slug' => 'test', 'schemas' => [1, 2]]];
        $result    = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        // Falls back to returning registers as-is.
        $this->assertSame($registers, $result);

    }


    public function testEnrichRegistersWithSchemasReplacesIds(): void
    {
        $mockSchemaMapper = $this->createSchemaMapperMock([
            10 => ['id' => 10, 'title' => 'Schema 10'],
            20 => ['id' => 20, 'title' => 'Schema 20'],
        ]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Db\SchemaMapper')
            ->willReturn($mockSchemaMapper);

        $registers = [['id' => 1, 'slug' => 'test', 'schemas' => [10, 20]]];
        $result    = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]['schemas']);
        $this->assertSame(10, $result[0]['schemas'][0]['id']);
        $this->assertSame(20, $result[0]['schemas'][1]['id']);

    }


    public function testEnrichRegistersWithSchemasHandlesObjects(): void
    {
        $mockSchemaMapper = $this->createSchemaMapperMock([
            5 => ['id' => 5, 'title' => 'Schema 5'],
        ]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $registerObj = $this->createMock(Register::class);
        $registerObj->method('jsonSerialize')
            ->willReturn(['id' => 1, 'slug' => 'test', 'schemas' => [5]]);

        $result = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [[$registerObj]]);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['schemas'][0]['id']);

    }


    public function testEnrichRegistersWithSchemasEmptySchemasArray(): void
    {
        $mockSchemaMapper = $this->createSchemaMapperMock([]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $registers = [['id' => 1, 'slug' => 'test', 'schemas' => []]];
        $result    = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        $this->assertCount(1, $result);
        $this->assertEmpty($result[0]['schemas']);

    }


    public function testEnrichRegistersWithSchemasSkipsNonNumericIds(): void
    {
        $mockSchemaMapper = $this->createSchemaMapperMock([]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $existingSchemaData = ['id' => 99, 'title' => 'Already resolved'];
        $registers          = [['id' => 1, 'slug' => 'test', 'schemas' => [$existingSchemaData]]];
        $result             = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        $this->assertCount(1, $result);
        $this->assertSame($existingSchemaData, $result[0]['schemas'][0]);

    }


    public function testEnrichRegistersWithSchemasHandlesSchemaNotFound(): void
    {
        $mockSchemaMapper = $this->createMock(SchemaMapper::class);
        $mockSchemaMapper->method('find')
            ->willThrowException(new \Exception('Schema not found'));

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $registers = [['id' => 1, 'slug' => 'test', 'schemas' => [999]]];
        $result    = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        $this->assertCount(1, $result);
        $this->assertEmpty($result[0]['schemas']);

    }


    public function testEnrichRegistersWithSchemasNoSchemasKey(): void
    {
        $mockSchemaMapper = $this->createSchemaMapperMock([]);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($mockSchemaMapper);

        $registers = [['id' => 1, 'slug' => 'test']];
        $result    = $this->invokePrivateMethod($this->service, 'enrichRegistersWithSchemas', [$registers]);

        $this->assertCount(1, $result);

    }


    // ---------------------------------------------------------------
    // Private: updateObjectTypeConfiguration
    // ---------------------------------------------------------------

    public function testUpdateObjectTypeConfigurationWithSchemaArrays(): void
    {
        $importResult = [
            'schemas'   => [
                ['slug' => 'catalog', 'id' => 10],
                ['slug' => 'listing', 'id' => 11],
            ],
            'registers' => [
                ['slug' => 'publication', 'id' => 1],
            ],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $this->assertSame('openregister', $storedValues['catalog_source']);
        $this->assertSame('10', $storedValues['catalog_schema']);
        $this->assertSame('1', $storedValues['catalog_register']);
        $this->assertSame('11', $storedValues['listing_schema']);
        $this->assertSame('1', $storedValues['listing_register']);

    }


    public function testUpdateObjectTypeConfigurationWithSchemaObjects(): void
    {
        $schemaMock = $this->createMock(Schema::class);
        $schemaMock->method('jsonSerialize')
            ->willReturn(['slug' => 'theme', 'id' => 30]);

        $registerMock = $this->createMock(Register::class);
        $registerMock->method('jsonSerialize')
            ->willReturn(['slug' => 'publication', 'id' => 2]);

        $importResult = [
            'schemas'   => [$schemaMock],
            'registers' => [$registerMock],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $this->assertSame('30', $storedValues['theme_schema']);
        $this->assertSame('2', $storedValues['theme_register']);

    }


    public function testUpdateObjectTypeConfigurationNoMatchingRegister(): void
    {
        $importResult = [
            'schemas'   => [
                ['slug' => 'catalog', 'id' => 10],
            ],
            'registers' => [
                ['slug' => 'something-else', 'id' => 1],
            ],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $this->assertSame('openregister', $storedValues['catalog_source']);
        $this->assertSame('10', $storedValues['catalog_schema']);
        $this->assertArrayNotHasKey('catalog_register', $storedValues);

    }


    public function testUpdateObjectTypeConfigurationEmptyResult(): void
    {
        $importResult = [
            'schemas'   => [],
            'registers' => [],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $types = ['catalog', 'listing', 'organization', 'theme', 'page', 'menu', 'glossary'];
        foreach ($types as $type) {
            $this->assertSame('openregister', $storedValues["{$type}_source"]);
            $this->assertArrayNotHasKey("{$type}_schema", $storedValues);
            $this->assertArrayNotHasKey("{$type}_register", $storedValues);
        }

    }


    public function testUpdateObjectTypeConfigurationWithUuidFallback(): void
    {
        $importResult = [
            'schemas'   => [
                ['slug' => 'page', 'uuid' => 'abc-123'],
            ],
            'registers' => [
                ['slug' => 'publication', 'uuid' => 'reg-456'],
            ],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $this->assertSame('abc-123', $storedValues['page_schema']);
        $this->assertSame('reg-456', $storedValues['page_register']);

    }


    public function testUpdateObjectTypeConfigurationSetsAllObjectTypes(): void
    {
        $importResult = [
            'schemas'   => [],
            'registers' => [],
        ];

        $storedValues = [];
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value) use (&$storedValues) {
                $storedValues[$key] = $value;
                return true;
            });

        $this->invokePrivateMethod($this->service, 'updateObjectTypeConfiguration', [$importResult]);

        $expectedTypes = ['catalog', 'listing', 'organization', 'theme', 'page', 'menu', 'glossary'];
        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey("{$type}_source", $storedValues);
        }

    }


    // ---------------------------------------------------------------
    // Private: shouldLoadSettings
    // ---------------------------------------------------------------

    public function testShouldLoadSettingsReturnsTrueNoStoredVersion(): void
    {
        $mockConfigService = $this->createConfigServiceMock(null);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->willReturn('1.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->invokePrivateMethod($this->service, 'shouldLoadSettings', []);

        $this->assertTrue($result);

    }


    public function testShouldLoadSettingsReturnsTrueWhenNewer(): void
    {
        $mockConfigService = $this->createConfigServiceMock('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->invokePrivateMethod($this->service, 'shouldLoadSettings', []);

        $this->assertTrue($result);

    }


    public function testShouldLoadSettingsReturnsFalseWhenSameVersion(): void
    {
        $mockConfigService = $this->createConfigServiceMock('1.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('1.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->invokePrivateMethod($this->service, 'shouldLoadSettings', []);

        $this->assertFalse($result);

    }


    public function testShouldLoadSettingsReturnsTrueOnException(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->appManager->method('getAppVersion')
            ->willThrowException(new \Exception('Error'));

        $result = $this->invokePrivateMethod($this->service, 'shouldLoadSettings', []);

        $this->assertTrue($result);

    }


    public function testShouldLoadSettingsReturnsFalseWhenOlderVersion(): void
    {
        $mockConfigService = $this->createConfigServiceMock('3.0.0');

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->appManager->method('getAppVersion')
            ->with('opencatalogi')
            ->willReturn('2.0.0');

        $this->container->method('get')
            ->willReturn($mockConfigService);

        $result = $this->invokePrivateMethod($this->service, 'shouldLoadSettings', []);

        $this->assertFalse($result);

    }


}
