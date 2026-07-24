<?php
/**
 * OpenCatalogi WOO register-schema shape test.
 *
 * Implements the WOO-PROV-001 acceptance criterion: cross-check the SHIPPED
 * register definition against the field inventory `WooService` actually
 * persists, so the storage schemas cannot drift from their only writer.
 *
 * The register is read the way `SettingsService::loadSettings()` assembles it —
 * the `lib/Settings/publication_register.json` monolith deep-merged with every
 * `lib/Settings/register.d/*.json` fragment (ADR-037) — rather than reading the
 * monolith alone. That matters: the WOO schemas ship as a fragment precisely so
 * the fragment hash folds into the import version (`<appVersion>+frag.<hash>`)
 * and OpenRegister's version-gated `importFromApp` re-imports on deploy. A test
 * that read only the monolith would pass while the effective register was
 * broken, and would have to be rewritten every time a schema moved between
 * monolith and fragment.
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
 *
 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-and-assessment-objects-have-shipped-storage-schemas-woo-prov-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped WOO storage schemas against drifting from WooService.
 */
class WooRegisterSchemasTest extends TestCase
{

    /**
     * Fields `WooService` persists on a `wooBatch` object.
     *
     * Derived from `createBatch()`'s batch literal plus the later
     * `$batch[...]` assignments in `getBatch()` / `publishBatch()`.
     *
     * @var string[]
     */
    private const BATCH_FIELDS = [
        'caseReference',
        'status',
        'deckBoardId',
        'deckAvailable',
        'documents',
        'besluit',
        'inventarislijst',
        'documentSummary',
        'publishedAt',
        'publishedCount',
        'wooPublication',
        'createdAt',
        'updatedAt',
        'createdBy',
    ];

    /**
     * Fields `WooService` persists on a `wooAssessment` object.
     *
     * Derived from the assessment literal in `createBatch()`.
     *
     * @var string[]
     */
    private const ASSESSMENT_FIELDS = [
        'documentReference',
        'fileName',
        'fileType',
        'assessment',
        'weigeringsgronden',
        'redactionInstructions',
        'anonymizedDocument',
        'caseReference',
        'assessedBy',
        'assessedAt',
    ];

    /**
     * Assemble the effective register document exactly as
     * `SettingsService::loadSettings()` does.
     *
     * @return array<string, mixed> The merged register configuration.
     */
    private function effectiveRegister(): array
    {
        $root = dirname(__DIR__, 3);

        $base = json_decode((string) file_get_contents($root.'/lib/Settings/publication_register.json'), true);
        $this->assertIsArray($base, 'publication_register.json must parse as JSON');

        $fragments = glob($root.'/lib/Settings/register.d/*.json');
        sort($fragments);
        foreach ($fragments as $fragment) {
            $data = json_decode((string) file_get_contents($fragment), true);
            if (is_array($data) === false) {
                continue;
            }

            $base = $this->deepMerge($base, $data);
        }

        return $base;

    }//end effectiveRegister()

    /**
     * Mirror of `SettingsService::deepMergeConfig()` — associative arrays union
     * by key (recursing), list arrays concatenate, scalars overwrite.
     *
     * @param array<mixed> $base    The base document.
     * @param array<mixed> $overlay The fragment to merge on top.
     *
     * @return array<mixed> The merged result.
     */
    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
            ) {
                $baseIsList    = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
                $overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
                if ($baseIsList === true && $overlayIsList === true) {
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    $base[$key] = $this->deepMerge($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;

    }//end deepMerge()

    /**
     * Both WOO schemas must exist in the effective register with exactly the
     * field set `WooService` reads and writes — no field the service persists
     * may be missing, and no property may be invented that it never writes.
     *
     * @return void
     */
    public function testWooSchemasMatchTheWooServiceFieldInventory(): void
    {
        $schemas = $this->effectiveRegister()['components']['schemas'];

        foreach ([
            'wooBatch'      => self::BATCH_FIELDS,
            'wooAssessment' => self::ASSESSMENT_FIELDS,
        ] as $slug => $expectedFields) {
            $this->assertArrayHasKey(
                $slug,
                $schemas,
                'The effective register must ship a "'.$slug.'" schema; without it WooService has nowhere to store objects and /woo 404s.'
            );

            $actual = array_keys($schemas[$slug]['properties']);
            sort($actual);
            $expected = $expectedFields;
            sort($expected);

            $this->assertSame(
                $expected,
                $actual,
                'The "'.$slug.'" schema properties must match the fields WooService persists exactly.'
            );
        }

    }//end testWooSchemasMatchTheWooServiceFieldInventory()

    /**
     * Every property must carry a human-friendly English title + description
     * (ADR-011 / the schema-property-titles gate) so the generated forms and
     * table headers never leak raw technical keys.
     *
     * @return void
     */
    public function testWooSchemaPropertiesCarryTitlesAndDescriptions(): void
    {
        $schemas = $this->effectiveRegister()['components']['schemas'];

        foreach (['wooBatch', 'wooAssessment'] as $slug) {
            foreach ($schemas[$slug]['properties'] as $name => $property) {
                $this->assertNotEmpty(
                    ($property['title'] ?? ''),
                    $slug.'.'.$name.' must declare a title.'
                );
                $this->assertNotEmpty(
                    ($property['description'] ?? ''),
                    $slug.'.'.$name.' must declare a description.'
                );
            }
        }

    }//end testWooSchemaPropertiesCarryTitlesAndDescriptions()

    /**
     * The schemas must also be wired into the publication register itself —
     * listed in its `schemas` array and present in its `configuration.schemas`
     * map. Shipping the definitions without registering them would import the
     * schemas but leave the register unaware of them.
     *
     * @return void
     */
    public function testWooSchemasAreRegisteredOnThePublicationRegister(): void
    {
        $register = $this->effectiveRegister()['components']['registers']['publication'];

        foreach (['wooBatch', 'wooAssessment'] as $slug) {
            $this->assertContains(
                $slug,
                $register['schemas'],
                'The publication register must list "'.$slug.'" in its schemas array.'
            );
            $this->assertArrayHasKey(
                $slug,
                $register['configuration']['schemas'],
                'The publication register must configure storage for "'.$slug.'".'
            );
        }

    }//end testWooSchemasAreRegisteredOnThePublicationRegister()

    /**
     * ADR-037 guard: the WOO schemas must ship as a `register.d/*.json`
     * fragment, never inlined into the monolith. Only a fragment change alters
     * the folded import version (`<appVersion>+frag.<hash>`), so a monolith-only
     * edit leaves OpenRegister's version-gated `importFromApp` closed and the
     * schemas never provision on an existing install — the exact failure this
     * change was written to eliminate.
     *
     * @return void
     */
    public function testWooSchemasShipAsARegisterFragmentNotInTheMonolith(): void
    {
        $root     = dirname(__DIR__, 3);
        $monolith = (string) file_get_contents($root.'/lib/Settings/publication_register.json');

        foreach (['wooBatch', 'wooAssessment'] as $slug) {
            $this->assertStringNotContainsString(
                $slug,
                $monolith,
                'The "'.$slug.'" schema must live in a lib/Settings/register.d/*.json fragment (ADR-037), '.
                'not in publication_register.json — a monolith-only edit does not change the fragment hash, '.
                'so the version-gated import never re-runs and the schema never provisions.'
            );
        }

    }//end testWooSchemasShipAsARegisterFragmentNotInTheMonolith()
}//end class
