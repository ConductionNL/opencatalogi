<?php
/**
 * Tests for SettingsService::attachOrphanSchemasToPublicationRegister().
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the safety net that stops a register.d fragment orphaning its schemas.
 *
 * The OOAPI fragment declared course/program/offering under `components.schemas`
 * but never under `components.registers.publication.schemas`, so OpenRegister
 * created the schema rows and no register held them — no magic table was
 * provisioned and every `ooapi_*_register`/`_schema` pair resolved inconsistently.
 *
 * These tests exercise the pure payload transform directly, so they need neither
 * OpenRegister nor a Nextcloud container.
 */
class AttachOrphanSchemasTest extends TestCase
{


    /**
     * Invoke the private static transform under test.
     *
     * @param array $data The merged configuration payload.
     *
     * @return array The payload after the transform.
     */
    private function attach(array $data): array
    {
        $method = new ReflectionMethod(
            SettingsService::class,
            'attachOrphanSchemasToPublicationRegister'
        );
        $method->setAccessible(true);

        return $method->invoke(null, $data);

    }//end attach()


    /**
     * Build a payload with the given schemas and publication-register declaration.
     *
     * @param array $schemas          Slugs to declare under components.schemas.
     * @param array $declaredOnRegister Slugs the publication register already holds.
     * @param array $configuration    The register's existing schema configuration.
     *
     * @return array The payload.
     */
    private function payload(array $schemas, array $declaredOnRegister, array $configuration=[]): array
    {
        $schemaMap = [];
        foreach ($schemas as $slug) {
            $schemaMap[$slug] = ['title' => $slug];
        }

        return [
            'components' => [
                'schemas'   => $schemaMap,
                'registers' => [
                    'publication' => [
                        'schemas'       => $declaredOnRegister,
                        'configuration' => $configuration,
                    ],
                ],
            ],
        ];

    }//end payload()


    /**
     * A schema no register declares is attached to the publication register.
     *
     * @return void
     */
    public function testOrphanSchemaIsAttachedWithMagicMapping(): void
    {
        $data = $this->payload(
            schemas: ['publication', 'course', 'program'],
            declaredOnRegister: ['publication']
        );

        $register = $this->attach($data)['components']['registers']['publication'];

        $this->assertSame(['publication', 'course', 'program'], $register['schemas']);
        $this->assertTrue($register['configuration']['schemas']['course']['magicMapping']);
        $this->assertTrue($register['configuration']['schemas']['course']['autoCreateTable']);
        $this->assertTrue($register['configuration']['schemas']['program']['magicMapping']);

    }//end testOrphanSchemaIsAttachedWithMagicMapping()


    /**
     * A correctly declared payload is left exactly as it is.
     *
     * This is the shape the fixed ooapi fragment produces, so the guard must be a
     * no-op there — otherwise it would append duplicates on every import.
     *
     * @return void
     */
    public function testFullyDeclaredPayloadIsUnchanged(): void
    {
        $data = $this->payload(
            schemas: ['publication', 'course'],
            declaredOnRegister: ['publication', 'course']
        );

        $this->assertSame($data, $this->attach($data));

    }//end testFullyDeclaredPayloadIsUnchanged()


    /**
     * Running the transform twice yields the same payload as running it once.
     *
     * @return void
     */
    public function testTransformIsIdempotent(): void
    {
        $data = $this->payload(
            schemas: ['publication', 'course'],
            declaredOnRegister: ['publication']
        );

        $once  = $this->attach($data);
        $twice = $this->attach($once);

        $this->assertSame($once, $twice);
        $this->assertSame(['publication', 'course'], $twice['components']['registers']['publication']['schemas']);

    }//end testTransformIsIdempotent()


    /**
     * An explicit `magicMapping: false` opt-out survives the attach.
     *
     * @return void
     */
    public function testExplicitMagicMappingOptOutIsPreserved(): void
    {
        $data = $this->payload(
            schemas: ['publication', 'course'],
            declaredOnRegister: ['publication'],
            configuration: ['schemas' => ['course' => ['magicMapping' => false]]]
        );

        $register = $this->attach($data)['components']['registers']['publication'];

        $this->assertSame(['publication', 'course'], $register['schemas']);
        $this->assertFalse($register['configuration']['schemas']['course']['magicMapping']);

    }//end testExplicitMagicMappingOptOutIsPreserved()


    /**
     * A slug another register already declares is not stolen by publication.
     *
     * @return void
     */
    public function testSchemaHeldByAnotherRegisterIsNotAttached(): void
    {
        $data = $this->payload(
            schemas: ['publication', 'course'],
            declaredOnRegister: ['publication']
        );
        $data['components']['registers']['other'] = ['schemas' => ['course']];

        $register = $this->attach($data)['components']['registers']['publication'];

        $this->assertSame(['publication'], $register['schemas']);
        $this->assertArrayNotHasKey('course', ($register['configuration']['schemas'] ?? []));

    }//end testSchemaHeldByAnotherRegisterIsNotAttached()


    /**
     * Without a publication register there is nothing to attach to.
     *
     * @return void
     */
    public function testPayloadWithoutPublicationRegisterIsUnchanged(): void
    {
        $data = [
            'components' => [
                'schemas'   => ['course' => ['title' => 'course']],
                'registers' => [],
            ],
        ];

        $this->assertSame($data, $this->attach($data));

    }//end testPayloadWithoutPublicationRegisterIsUnchanged()


}//end class
