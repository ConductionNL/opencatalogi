<?php
/**
 * Unit tests for OoapiMappingService.
 *
 * Pure mapping-layer tests: `x-ooapi` annotation resolution (OOAPI-004),
 * identity vs. declared mapping, dot-path extraction/assignment, and RIO
 * identifier omission (OOAPI-005). No Nextcloud bootstrap required.
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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OoapiMappingService.
 */
class OoapiMappingServiceTest extends TestCase
{

    private OoapiMappingService $service;

    protected function setUp(): void
    {
        $this->service = new OoapiMappingService();
    }

    public function testIsAnnotatedFalseWhenNoXOoapiKey(): void
    {
        $this->assertFalse($this->service->isAnnotated(['title' => 'x']));
        $this->assertFalse($this->service->isAnnotated(null));
    }

    public function testIsAnnotatedFalseWhenXOoapiHasNoResource(): void
    {
        $this->assertFalse($this->service->isAnnotated(['x-ooapi' => []]));
        $this->assertFalse($this->service->isAnnotated(['x-ooapi' => ['resource' => '']]));
    }

    public function testIsAnnotatedTrueWhenResourceDeclared(): void
    {
        $this->assertTrue($this->service->isAnnotated(['x-ooapi' => ['resource' => 'course']]));
    }

    public function testResolveResourceTypeReturnsNullWhenUnannotated(): void
    {
        $this->assertNull($this->service->resolveResourceType(['title' => 'x']));
    }

    public function testResolveResourceTypeReturnsDeclaredType(): void
    {
        $this->assertSame('organization', $this->service->resolveResourceType(['x-ooapi' => ['resource' => 'organization']]));
    }

    public function testResolveMappingReturnsNullForUnannotatedSchema(): void
    {
        $this->assertNull($this->service->resolveMapping(['title' => 'x']));
    }

    public function testResolveMappingReturnsNullForIdentityAnnotation(): void
    {
        // OOAPI-004: "resource" only, no "mapping" key → identity.
        $this->assertNull($this->service->resolveMapping(['x-ooapi' => ['resource' => 'course']]));
    }

    public function testResolveMappingReturnsDeclaredMap(): void
    {
        $schema = ['x-ooapi' => ['resource' => 'organization', 'mapping' => ['name' => 'name', 'primaryCode.code' => 'code']]];
        $this->assertSame(['name' => 'name', 'primaryCode.code' => 'code'], $this->service->resolveMapping($schema));
    }

    public function testBuildResourceIdentityCopiesOwnProperties(): void
    {
        $object = [
            'id'      => 'row-1',
            '@self'   => ['uuid' => 'uuid-1'],
            'catalog' => 'catalog-uuid',
            'code'    => 'INF101',
            'name'    => 'Introduction to Computer Science',
            'level'   => 'bachelor',
        ];

        $resource = $this->service->buildResource($object, null, 'courseId');

        $this->assertSame('uuid-1', $resource['courseId']);
        $this->assertSame('INF101', $resource['code']);
        $this->assertSame('Introduction to Computer Science', $resource['name']);
        $this->assertSame('bachelor', $resource['level']);
        // Internal envelope/scoping fields never leak into the OOAPI resource.
        $this->assertArrayNotHasKey('@self', $resource);
        $this->assertArrayNotHasKey('catalog', $resource);
        $this->assertArrayNotHasKey('id', $resource);
    }

    public function testBuildResourceIdentityOmitsEmptyRioField(): void
    {
        // OOAPI-005: absent/empty RIO identifier MUST be omitted, never null.
        $object = [
            '@self'                  => ['uuid' => 'uuid-2'],
            'catalog'                => 'catalog-uuid',
            'code'                   => 'INF101',
            'name'                   => 'Course',
            'rioOpleidingseenheidId' => '',
        ];

        $resource = $this->service->buildResource($object, null, 'courseId');

        $this->assertArrayNotHasKey('rioOpleidingseenheidId', $resource);
    }

    public function testBuildResourceIdentityIncludesNonEmptyRioField(): void
    {
        $object = [
            '@self'                  => ['uuid' => 'uuid-3'],
            'catalog'                => 'catalog-uuid',
            'code'                   => 'INF101',
            'name'                   => 'Course',
            'rioOpleidingseenheidId' => 'RIO-123',
        ];

        $resource = $this->service->buildResource($object, null, 'courseId');

        $this->assertSame('RIO-123', $resource['rioOpleidingseenheidId']);
    }

    public function testBuildResourceAppliesDeclaredMappingWithNestedOutputPath(): void
    {
        $object = [
            '@self' => ['uuid' => 'uuid-4'],
            'name'  => 'Universiteit van Amsterdam',
            'tooi'  => 'UvA1234',
        ];
        $mapping = ['name' => 'name', 'shortName' => 'tooi'];

        $resource = $this->service->buildResource($object, $mapping, 'organizationId');

        $this->assertSame('uuid-4', $resource['organizationId']);
        $this->assertSame('Universiteit van Amsterdam', $resource['name']);
        $this->assertSame('UvA1234', $resource['shortName']);
    }

    public function testBuildResourceDeclaredMappingWritesNestedDotPath(): void
    {
        $object  = ['@self' => ['uuid' => 'uuid-5'], 'code' => 'INF101', 'name' => 'Course'];
        $mapping = ['primaryCode.code' => 'code', 'name' => 'name'];

        $resource = $this->service->buildResource($object, $mapping, 'courseId');

        $this->assertSame(['code' => 'INF101'], $resource['primaryCode']);
        $this->assertSame('Course', $resource['name']);
    }

    public function testBuildResourceDeclaredMappingSkipsEmptySourceValues(): void
    {
        $object  = ['@self' => ['uuid' => 'uuid-6'], 'name' => 'Org', 'description' => ''];
        $mapping = ['name' => 'name', 'description' => 'description'];

        $resource = $this->service->buildResource($object, $mapping, 'organizationId');

        $this->assertSame('Org', $resource['name']);
        $this->assertArrayNotHasKey('description', $resource);
    }

    public function testBuildResourceIdFallsBackToTopLevelId(): void
    {
        $object   = ['id' => 'plain-id-1', 'name' => 'Org'];
        $resource = $this->service->buildResource($object, ['name' => 'name'], 'organizationId');

        $this->assertSame('plain-id-1', $resource['organizationId']);
    }
}
