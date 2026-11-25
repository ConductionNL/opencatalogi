<?php

/**
 * OpenCatalogi catalog schema re-import key test.
 *
 * A re-import with `force: false` must update the catalog schema in place, not
 * create a twin. OpenRegister's ImportHandler matches an incoming schema to the
 * stored one by its slug among the schemas the target register links (see its
 * ImportHandlerRegisterSchemaLinkOnVersionSkipTest). That match holds only while
 * the shipped register document gives the catalog schema one stable slug and
 * links it from the publication register; this test pins both on the effective
 * register (the monolith deep-merged with every `register.d` fragment).
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
 * @spec openspec/changes/catalogs/specs/catalogs/spec.md#requirement-cat-003-catalogs-stored-as-openregister-objects
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The catalog schema keeps the slug OpenRegister matches a re-import on.
 */
class CatalogSchemaReimportKeyTest extends TestCase {

	/**
	 * Exactly one schema in the effective register carries the slug `catalog`.
	 *
	 * Two definitions with that slug would leave the match ambiguous, and a
	 * renamed slug would make the next import create a second catalog schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/catalogs/specs/catalogs/spec.md#requirement-cat-003-catalogs-stored-as-openregister-objects
	 */
	public function testExactlyOneSchemaCarriesTheCatalogSlug(): void {
		$schemas = $this->effectiveRegister()['components']['schemas'] ?? [];

		$withSlug = array_keys(
			array_filter(
				$schemas,
				static fn ($schema): bool => is_array($schema) === true && ($schema['slug'] ?? null) === 'catalog'
			)
		);

		$this->assertSame(expected: ['catalog'], actual: $withSlug);
	}//end testExactlyOneSchemaCarriesTheCatalogSlug()

	/**
	 * The publication register links the catalog schema by its slug.
	 *
	 * OpenRegister looks for the stored schema among the schemas the register
	 * links; an unlinked schema is not found and forks on the next import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/catalogs/specs/catalogs/spec.md#requirement-cat-003-catalogs-stored-as-openregister-objects
	 */
	public function testThePublicationRegisterLinksTheCatalogSchema(): void {
		$register = $this->effectiveRegister()['components']['registers']['publication'] ?? [];

		$this->assertSame(expected: 'publication', actual: $register['slug'] ?? null);
		$this->assertContains(needle: 'catalog', haystack: $register['schemas'] ?? []);
	}//end testThePublicationRegisterLinksTheCatalogSchema()

	/**
	 * Assemble the effective register document as `SettingsService::loadSettings()` does.
	 *
	 * @return array<string, mixed> The merged register configuration.
	 */
	private function effectiveRegister(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string) file_get_contents($root . '/lib/Settings/publication_register.json'), true);
		$this->assertIsArray(actual: $base, message: 'publication_register.json must parse as JSON');

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = json_decode((string) file_get_contents($fragment), true);
			if (is_array($data) === true) {
				$base = $this->deepMerge(base: $base, overlay: $data);
			}
		}

		return $base;
	}//end effectiveRegister()

	/**
	 * Mirror of `SettingsService::deepMergeConfig()`: associative arrays union by key, lists concatenate.
	 *
	 * @param array<mixed> $base    The base document.
	 * @param array<mixed> $overlay The fragment to merge on top.
	 *
	 * @return array<mixed> The merged result.
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
				if (array_is_list($value) === true && array_is_list($base[$key]) === true) {
					$base[$key] = array_merge($base[$key], $value);
					continue;
				}

				$base[$key] = $this->deepMerge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMerge()
}//end class
