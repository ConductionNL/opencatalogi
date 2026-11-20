<?php

/**
 * Publication and catalogue text is translatable (publication-translations
 * REQ-PTR-001).
 *
 * Reads the SHIPPED register: the monolith with every register.d fragment
 * merged onto it by SettingsService::deepMergeConfig(), in filename order,
 * because that merge is what OpenRegister imports.
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
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-publication-and-catalogue-text-is-translatable-req-ptr-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the translatable flags on the publication and catalog schemas.
 */
class PublicationTranslationsSchemaTest extends TestCase {

	/**
	 * The shipped register, monolith plus every register.d fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($settings . '/publication_register.json'), true);

		$merge = new \ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$merged = $merge->invoke(null, $merged, $overlay);
		}

		return $merged;
	}

	/**
	 * Title, summary and description of publication and catalog are
	 * translatable with Dutch as source, and keep their own definition.
	 *
	 * @return void
	 */
	public function testTheThreeFieldsAreTranslatable(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['publication', 'catalog'] as $schema) {
			foreach (['title', 'summary', 'description'] as $field) {
				$property = $schemas[$schema]['properties'][$field];
				$this->assertTrue(condition: $property['translatable'] ?? false, message: "$schema.$field is not translatable");
				$this->assertSame(expected: 'nl', actual: $property['sourceLanguage'] ?? null, message: "$schema.$field has no Dutch source");
				$this->assertSame(expected: 'string', actual: $property['type'], message: "$schema.$field lost its type in the merge");
			}
		}
	}

	/**
	 * No other property is translatable: organisation, Woo category, dates
	 * and retention are the same in every language.
	 *
	 * @return void
	 */
	public function testNoOtherPropertyIsTranslatable(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['publication', 'catalog'] as $schema) {
			$translatable = array_keys(
				array_filter(
					$schemas[$schema]['properties'],
					static fn ($property): bool => is_array($property) === true && ($property['translatable'] ?? false) === true
				)
			);
			sort($translatable);
			$this->assertSame(expected: ['description', 'summary', 'title'], actual: $translatable, message: $schema);
		}
	}

	/**
	 * Both schema versions are bumped past their last shipped value, so the
	 * import updates the schema.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionsAreBumped(): void {
		$schemas = $this->register()['components']['schemas'];

		$this->assertTrue(condition: version_compare($schemas['publication']['version'], '0.0.7', '>'));
		$this->assertTrue(condition: version_compare($schemas['catalog']['version'], '0.4.0', '>'));
	}
}
