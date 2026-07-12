<?php
/**
 * Grep assertion: no OOAPI-related source file touches OpenRegister's raw
 * storage internals.
 *
 * Enforces the OOAPI-003 scenario "OpenCatalogi contains no raw OR-storage
 * SQL for OOAPI resources" and `opencatalogi-adopt-or-abstractions`'s
 * no-raw-SQL rule: OOAPI resources are queried exclusively through
 * OpenRegister's ObjectService (searchObjects/searchObjectsPaginated/find),
 * never by naming a magic-mapper table or querying information_schema
 * directly.
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

namespace Unit;

use PHPUnit\Framework\TestCase;

/**
 * Grep-based source-hygiene test for the OOAPI capability.
 *
 * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-courseprogramoffering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
 */
class OoapiNoRawSqlTest extends TestCase
{

    /**
     * The OOAPI-related source files this test scans.
     *
     * @var array<int, string>
     */
    private const OOAPI_FILES = [
        __DIR__.'/../../lib/Controller/OoapiController.php',
        __DIR__.'/../../lib/Service/OoapiService.php',
        __DIR__.'/../../lib/Service/OoapiMappingService.php',
    ];

    /**
     * The forbidden raw-storage substrings (OOAPI-003).
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_SUBSTRINGS = [
        'oc_openregister_table_',
        'information_schema',
    ];

    public function testNoOoapiSourceFileTouchesRawOpenRegisterStorage(): void
    {
        foreach (self::OOAPI_FILES as $file) {
            $this->assertFileExists($file, "Expected OOAPI source file to exist: $file");
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $contents,
                    "OOAPI source file $file must not reference OpenRegister's raw storage internals ('$needle')"
                );
            }
        }

    }//end testNoOoapiSourceFileTouchesRawOpenRegisterStorage()
}
