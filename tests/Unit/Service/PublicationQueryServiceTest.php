<?php
/**
 * Unit tests for PublicationQueryService.
 *
 * Covers: findObjectLocation (the constrained object-location query) and isObjectPublic
 * (the public-relation-endpoint visibility guard). Bulk visibility filtering lives in
 * OpenRegister RBAC; isObjectPublic mirrors the same RBAC rule
 * (`publicatiedatum <= now`, APB-006) for the per-object guard on the public uses/used
 * relation endpoints. The removed object-level @self.published predicate is not consulted.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PublicationQueryService.
 *
 * Focuses on the constrained findObjectLocation lookup (#734), which now routes
 * through OpenRegister's ObjectService (ADR-022) instead of raw SQL against OR's
 * internal magic-mapper tables. Object visibility is enforced by OpenRegister RBAC,
 * not by an app-side published predicate.
 */
class PublicationQueryServiceTest extends TestCase
{

    /**
     * DI container mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * OpenRegister ObjectService mock resolved from the container.
     *
     * @var ObjectService|MockObject
     */
    private ObjectService|MockObject $objectService;

    /**
     * Service under test.
     *
     * @var PublicationQueryService
     */
    private PublicationQueryService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->service = new PublicationQueryService(
            container: $this->container
        );

    }//end setUp()

    /**
     * Wire the container so it resolves the ObjectService mock.
     *
     * @return void
     */
    private function wireObjectService(): void
    {
        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

    }//end wireObjectService()

    // -------------------------------------------------------------------------
    // findObjectLocation() tests
    // -------------------------------------------------------------------------

    /**
     * Security (#734): findObjectLocation MUST return null without touching
     * OpenRegister when no constraint is supplied.
     *
     * @return void
     */
    public function testFindObjectLocationFailsClosedWithoutConstraint(): void
    {
        // OpenRegister must NOT be resolved or queried at all.
        $this->container->expects($this->never())->method('get');

        $this->assertNull($this->service->findObjectLocation('any-uuid'));
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [1], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [1])
        );

    }//end testFindObjectLocationFailsClosedWithoutConstraint()

    /**
     * Constrained lookup locates the object via ObjectService::find within the
     * allowed (register × schema) pair — no raw SQL against OR storage internals.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationLocatesViaObjectService(): void
    {
        $this->wireObjectService();

        $entity = $this->createMock(ObjectEntity::class);

        // Object lives only in register 21 / schema 11; every other pair misses.
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
                if ((int) $register === 21 && (int) $schema === 11) {
                    return $entity;
                }

                return null;
            }
        );

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [20, 21],
            allowedSchemas: [10, 11]
        );

        $this->assertSame(expected: ['register' => 21, 'schema' => 11], actual: $location);

    }//end testFindObjectLocationLocatesViaObjectService()

    /**
     * Returns null when ObjectService finds the UUID in none of the allowed pairs.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationReturnsNullWhenNotFound(): void
    {
        $this->wireObjectService();

        $this->objectService->method('find')->willReturn(null);

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-missing',
            allowedRegisters: [1],
            allowedSchemas: [2]
        );

        $this->assertNull($location);

    }//end testFindObjectLocationReturnsNullWhenNotFound()

    /**
     * A DoesNotExistException from one pair is swallowed and the search continues
     * to the next pair rather than bubbling up.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationContinuesPastMissingPair(): void
    {
        $this->wireObjectService();

        $entity = $this->createMock(ObjectEntity::class);

        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
                if ((int) $register === 1 && (int) $schema === 2) {
                    throw new \OCP\AppFramework\Db\DoesNotExistException('missing table');
                }

                if ((int) $register === 1 && (int) $schema === 3) {
                    return $entity;
                }

                return null;
            }
        );

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [1],
            allowedSchemas: [2, 3]
        );

        $this->assertSame(expected: ['register' => 1, 'schema' => 3], actual: $location);

    }//end testFindObjectLocationContinuesPastMissingPair()

    // -------------------------------------------------------------------------
    // isObjectPublic() tests — RBAC publicatiedatum model (APB-006)
    // -------------------------------------------------------------------------

    /**
     * A past publicatiedatum with no depublicatiedatum is publicly visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithPastPublicatiedatum(): void
    {
        $this->assertTrue(
            $this->service->isObjectPublic(['publicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithPastPublicatiedatum()

    /**
     * No publicatiedatum means the object is not public (concept).
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithoutPublicatiedatum(): void
    {
        $this->assertFalse($this->service->isObjectPublic([]));
        $this->assertFalse(
            $this->service->isObjectPublic(['depublicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithoutPublicatiedatum()

    /**
     * A future publicatiedatum (embargo) is not yet public.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithFuturePublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);
        $this->assertFalse($this->service->isObjectPublic(['publicatiedatum' => $future]));

    }//end testIsObjectPublicWithFuturePublicatiedatum()

    /**
     * A reached depublicatiedatum hides the object; a future one keeps it visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicRespectsDepublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        $this->assertFalse(
            $this->service->isObjectPublic(
                [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => '2024-06-01T10:00:00+00:00',
                ]
            )
        );

        $this->assertTrue(
            $this->service->isObjectPublic(
                [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => $future,
                ]
            )
        );

    }//end testIsObjectPublicRespectsDepublicatiedatum()
}//end class
