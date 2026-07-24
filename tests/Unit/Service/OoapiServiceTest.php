<?php
/**
 * Unit tests for OoapiService.
 *
 * Covers OOAPI enablement, endpoint URL construction, the OOAPI-008
 * consumer-credential allowlist, and the catalog-scoped course/organization
 * lookups against a mocked OpenRegister ObjectService/SchemaMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\OoapiMappingService;
use OCA\OpenCatalogi\Service\OoapiService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OoapiService.
 */
class OoapiServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private IURLGenerator|MockObject $urlGenerator;
    private IAppConfig|MockObject $appConfig;
    private OoapiService $service;

    protected function setUp(): void
    {
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);

        $this->urlGenerator->method('getBaseUrl')->willReturn('https://host');

        $this->service = new OoapiService(
            $this->container,
            $this->appManager,
            new OoapiMappingService(),
            $this->urlGenerator,
            $this->appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testIsOoapiEnabled(): void
    {
        $this->assertTrue($this->service->isOoapiEnabled(['hasOoapi' => true]));
        $this->assertTrue($this->service->isOoapiEnabled(['hasOoapi' => 'true']));
        $this->assertFalse($this->service->isOoapiEnabled(['hasOoapi' => false]));
        $this->assertFalse($this->service->isOoapiEnabled([]));
    }

    public function testEndpointUrlIsAbsoluteAndStable(): void
    {
        $a = $this->service->endpointUrl('hva-onderwijs');
        $b = $this->service->endpointUrl('hva-onderwijs');
        $this->assertSame('https://host/apps/opencatalogi/api/catalogs/hva-onderwijs/ooapi/v5', $a);
        $this->assertSame($a, $b);
    }

    public function testIsConsumerAllowedTrueWhenAllowlistEmpty(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->assertTrue($this->service->isConsumerAllowed('any-user'));
    }

    public function testIsConsumerAllowedRestrictsToAllowlist(): void
    {
        $this->appConfig->method('getValueString')->willReturn('surf-consumer, hva-sync');
        $this->assertTrue($this->service->isConsumerAllowed('surf-consumer'));
        $this->assertTrue($this->service->isConsumerAllowed('hva-sync'));
        $this->assertFalse($this->service->isConsumerAllowed('random-user'));
    }

    /**
     * Configure the container so ResolvesRegisterConfiguration-style lookups and
     * OoapiService's own container calls resolve to a mocked ObjectService/SchemaMapper.
     *
     * @param \OCA\OpenRegister\Service\ObjectService|MockObject $objectService The mocked ObjectService.
     * @param mixed                                               $schemaMapper  The mocked SchemaMapper (or null to skip).
     *
     * @return void
     */
    private function wireOpenRegister($objectService, $schemaMapper=null): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->container->method('get')->willReturnCallback(
            function (string $id) use ($objectService, $schemaMapper) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                if ($id === 'OCA\OpenRegister\Db\SchemaMapper' && $schemaMapper !== null) {
                    return $schemaMapper;
                }

                throw new \RuntimeException('unexpected container lookup: '.$id);
            }
        );

    }//end wireOpenRegister()

    /**
     * Build a real ObjectEntity stub instance carrying the given jsonSerialize() payload.
     *
     * ObjectService::find() is typed `?ObjectEntity`, so a plain array cannot stand
     * in for it under PHPUnit's return-type-checked mocks.
     *
     * @param array<string, mixed> $data The object payload (as jsonSerialize() would return).
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity The stub entity.
     */
    private function makeObjectEntity(array $data): \OCA\OpenRegister\Db\ObjectEntity
    {
        $entity = new \OCA\OpenRegister\Db\ObjectEntity();
        $entity->setObject($data);
        if (isset($data['@self']['uuid']) === true) {
            $entity->setUuid($data['@self']['uuid']);
        }

        return $entity;

    }//end makeObjectEntity()

    public function testListCoursesScopesQueryToCatalogAndAppliesIdentityMapping(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['x-ooapi' => ['resource' => 'course']]);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with(
                $this->callback(
                    static function (array $query): bool {
                        return ($query['catalog'] ?? null) === 'catalog-1'
                            && ($query['@self']['register'] ?? null) === 'reg-1'
                            && ($query['@self']['schema'] ?? null) === 'schema-1';
                    }
                ),
                true,
                false,
                false
            )
            ->willReturn(
                [
                    'results' => [
                        ['@self' => ['uuid' => 'course-1'], 'catalog' => 'catalog-1', 'code' => 'INF101', 'name' => 'Course 1'],
                    ],
                    'next'    => null,
                ]
            );

        $this->wireOpenRegister($objectService, $schemaMapper);

        $result = $this->service->listCourses(['id' => 'catalog-1'], 'reg-1', 'schema-1', 1, 50);

        $this->assertCount(1, $result['items']);
        $this->assertSame('course-1', $result['items'][0]['courseId']);
        $this->assertSame('INF101', $result['items'][0]['code']);
        $this->assertFalse($result['hasNext']);
    }

    public function testListCoursesReturnsEmptyWhenSchemaUnannotated(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['title' => 'course']);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $this->wireOpenRegister($objectService, $schemaMapper);

        $result = $this->service->listCourses(['id' => 'catalog-1'], 'reg-1', 'schema-1', 1, 50);

        $this->assertSame([], $result['items']);
    }

    public function testGetResourceReturnsNullWhenObjectBelongsToDifferentCatalog(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['x-ooapi' => ['resource' => 'course']]);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->makeObjectEntity(['@self' => ['uuid' => 'course-1', 'schema' => 'schema-1'], 'catalog' => 'catalog-OTHER', 'code' => 'X'])
        );

        $this->wireOpenRegister($objectService, $schemaMapper);

        $result = $this->service->getResource(['id' => 'catalog-1'], 'reg-1', 'schema-1', 'course-1', 'courseId');

        $this->assertNull($result);
    }

    public function testGetResourceReturnsResourceWhenScopedToCatalog(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['x-ooapi' => ['resource' => 'course']]);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->makeObjectEntity(['@self' => ['uuid' => 'course-1', 'schema' => 'schema-1'], 'catalog' => 'catalog-1', 'code' => 'INF101', 'name' => 'Course'])
        );

        $this->wireOpenRegister($objectService, $schemaMapper);

        $result = $this->service->getResource(['id' => 'catalog-1'], 'reg-1', 'schema-1', 'course-1', 'courseId');

        $this->assertNotNull($result);
        $this->assertSame('course-1', $result['courseId']);
        $this->assertSame('INF101', $result['code']);
    }

    public function testRenderOrganizationReturnsNullWhenCatalogHasNoOrganization(): void
    {
        $result = $this->service->renderOrganization(['id' => 'catalog-1'], 'reg-1', 'schema-1');
        $this->assertNull($result);
    }

    public function testOrganizationByIdReturnsNullWhenIdDoesNotMatchCatalogsOwnOrganization(): void
    {
        $result = $this->service->organizationById(['id' => 'catalog-1', 'organization' => 'org-1'], 'org-2', 'reg-1', 'schema-1');
        $this->assertNull($result);
    }

    public function testOrganizationByIdRendersTheCatalogsOwningOrganization(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(
            ['x-ooapi' => ['resource' => 'organization', 'mapping' => ['name' => 'name']]]
        );
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->with('org-1', [])->willReturn(
            $this->makeObjectEntity(['@self' => ['uuid' => 'org-1'], 'name' => 'Universiteit van Amsterdam'])
        );

        $this->wireOpenRegister($objectService, $schemaMapper);

        $result = $this->service->organizationById(['id' => 'catalog-1', 'organization' => 'org-1'], 'org-1', 'reg-1', 'schema-1');

        $this->assertNotNull($result);
        $this->assertSame('org-1', $result['organizationId']);
        $this->assertSame('Universiteit van Amsterdam', $result['name']);
    }
}
