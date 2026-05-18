<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\ViewService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ViewService.
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-5
 */
class ViewServiceTest extends TestCase
{

    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;
    private ViewService $service;


    protected function setUp(): void
    {
        $this->config     = $this->createMock(IAppConfig::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->service = new ViewService(
            $this->config,
            $this->container,
            $this->appManager,
            $this->logger
        );

    }//end setUp()

    /**
     * Helper: configure the mock container to return a mocked ObjectService.
     *
     * @return ObjectService|MockObject
     */
    private function mockObjectService(): ObjectService|MockObject
    {
        $mockObjService = $this->createMock(ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjService);

        return $mockObjService;

    }//end mockObjectService()

    // -------------------------------------------------------------------------
    // Flag-guard scenarios
    // -------------------------------------------------------------------------

    public function testBothFlagsDisabledReturnsEmpty(): void
    {
        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: false,
            includeDeelnames: false
        );

        $this->assertEmpty($result['owned']);
        $this->assertEmpty($result['deelnames']);
        $this->assertEmpty($result['warnings']);

    }//end testBothFlagsDisabledReturnsEmpty()

    public function testOnlyGebruikFlagReturnsOwnedOnly(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 'obj-1']]]);

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: false
        );

        $this->assertCount(1, $result['owned']);
        $this->assertEmpty($result['deelnames']);

    }//end testOnlyGebruikFlagReturnsOwnedOnly()

    public function testOnlyDeelnamesFlagReturnsDeelnames(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 'obj-2', 'organisatie' => 'org-b']]]);

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: false,
            includeDeelnames: true
        );

        $this->assertEmpty($result['owned']);
        $this->assertCount(1, $result['deelnames']);
        $this->assertEquals('deelnames', $result['deelnames'][0]['_type']);

    }//end testOnlyDeelnamesFlagReturnsDeelnames()

    // -------------------------------------------------------------------------
    // Two-phase retrieval scenarios
    // -------------------------------------------------------------------------

    public function testBothPhasesReturnSeparateResults(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $callCount = 0;
        $mockObjService->method('searchObjectsPaginated')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return ['results' => [['id' => 'owned-1'], ['id' => 'owned-2']]];
                }

                return ['results' => [['id' => 'deelnames-1']]];
            });

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: true
        );

        $this->assertCount(2, $result['owned']);
        $this->assertCount(1, $result['deelnames']);

    }//end testBothPhasesReturnSeparateResults()

    public function testDeelsnamesQueryUsesFalseRbac(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $capturedArgs = [];
        $mockObjService->method('searchObjectsPaginated')
            ->willReturnCallback(
                function ($query, bool $_rbac = true, bool $_multitenancy = true) use (&$capturedArgs) {
                    $capturedArgs[] = ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
                    return ['results' => []];
                }
            );

        $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: true
        );

        // First call = owned (RBAC default), second = deelnames (RBAC false).
        $this->assertCount(2, $capturedArgs);
        $this->assertFalse($capturedArgs[1]['_rbac']);
        $this->assertFalse($capturedArgs[1]['_multitenancy']);

    }//end testDeelsnamesQueryUsesFalseRbac()

    // -------------------------------------------------------------------------
    // Annotation scenarios
    // -------------------------------------------------------------------------

    public function testDeelsnamesNodesCarryTypeMarker(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 'obj-x']]]);

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: false,
            includeDeelnames: true
        );

        $this->assertEquals('deelnames', $result['deelnames'][0]['_type']);

    }//end testDeelsnamesNodesCarryTypeMarker()

    public function testDeelsnamesNodesCarrySourceOrganizationFromArray(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [[
                    'id'          => 'obj-x',
                    'organisatie' => ['id' => 'org-b-uuid', 'name' => 'Org B'],
                ]],
            ]);

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: false,
            includeDeelnames: true
        );

        $node = $result['deelnames'][0];
        $this->assertEquals('Org B', $node['_sourceOrganization']);
        $this->assertEquals('org-b-uuid', $node['_sourceOrganizationId']);

    }//end testDeelsnamesNodesCarrySourceOrganizationFromArray()

    // -------------------------------------------------------------------------
    // Deduplication scenarios
    // -------------------------------------------------------------------------

    public function testDeduplicationRemovesDuplicateFromDeelnames(): void
    {
        $owned     = [['id' => 'shared-uuid', 'name' => 'Topdesk']];
        $deelnames = [
            ['id' => 'shared-uuid', '_type' => 'deelnames'],
            ['id' => 'unique-uuid', '_type' => 'deelnames'],
        ];

        $result = $this->service->deduplicateDeelnames(
            owned: $owned,
            deelnames: $deelnames
        );

        $this->assertCount(1, $result);
        $this->assertEquals('unique-uuid', $result[0]['id']);

    }//end testDeduplicationRemovesDuplicateFromDeelnames()

    public function testDeduplicationWithEmptyOwnedReturnsAllDeelnames(): void
    {
        $deelnames = [
            ['id' => 'a', '_type' => 'deelnames'],
            ['id' => 'b', '_type' => 'deelnames'],
        ];

        $result = $this->service->deduplicateDeelnames(owned: [], deelnames: $deelnames);

        $this->assertCount(2, $result);

    }//end testDeduplicationWithEmptyOwnedReturnsAllDeelnames()

    public function testDeduplicationWithEmptyDeelnames(): void
    {
        $owned = [['id' => 'owned-1']];

        $result = $this->service->deduplicateDeelnames(owned: $owned, deelnames: []);

        $this->assertEmpty($result);

    }//end testDeduplicationWithEmptyDeelnames()

    // -------------------------------------------------------------------------
    // Error handling scenarios
    // -------------------------------------------------------------------------

    public function testOwnedQueryFailureReturnsWarningAndStillFetchesDeelnames(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $callCount = 0;
        $mockObjService->method('searchObjectsPaginated')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('DB timeout');
                }

                return ['results' => [['id' => 'deel-1']]];
            });

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: true
        );

        $this->assertEmpty($result['owned']);
        $this->assertCount(1, $result['deelnames']);
        $this->assertNotEmpty($result['warnings']);

    }//end testOwnedQueryFailureReturnsWarningAndStillFetchesDeelnames()

    public function testDeelnamesQueryFailureReturnsWarningAndStillReturnsOwned(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $callCount = 0;
        $mockObjService->method('searchObjectsPaginated')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return ['results' => [['id' => 'owned-1']]];
                }

                throw new \RuntimeException('Schema not found');
            });

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: true
        );

        $this->assertCount(1, $result['owned']);
        $this->assertEmpty($result['deelnames']);
        $this->assertNotEmpty($result['warnings']);

    }//end testDeelnamesQueryFailureReturnsWarningAndStillReturnsOwned()

    public function testOpenRegisterNotInstalledReturnsWarningGracefully(): void
    {
        // Per spec: graceful error handling - view must still render with available data.
        // "OpenRegister not installed" is treated like any other query failure.
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->getGebruikForOrganization(
            organizationId: 'org-uuid',
            includeGebruik: true,
            includeDeelnames: false
        );

        $this->assertEmpty($result['owned']);
        $this->assertNotEmpty($result['warnings']);

    }//end testOpenRegisterNotInstalledReturnsWarningGracefully()

    // -------------------------------------------------------------------------
    // Configuration scenarios
    // -------------------------------------------------------------------------

    public function testGetGebruikRegisterReadsConfig(): void
    {
        $this->config->method('getValueString')
            ->with('opencatalogi', 'gebruik_register', '')
            ->willReturn('voorzieningen');

        $this->assertEquals('voorzieningen', $this->service->getGebruikRegister());

    }//end testGetGebruikRegisterReadsConfig()

    public function testGetGebruikSchemaReadsConfig(): void
    {
        $this->config->method('getValueString')
            ->with('opencatalogi', 'gebruik_schema', '')
            ->willReturn('gebruik');

        $this->assertEquals('gebruik', $this->service->getGebruikSchema());

    }//end testGetGebruikSchemaReadsConfig()

}//end class
