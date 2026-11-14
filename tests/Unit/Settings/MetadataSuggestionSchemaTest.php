<?php

/**
 * The metadataSuggestion schema as the instance imports it
 * (woo-metadata-suggestions REQ-WMS-001, REQ-WMS-003): the monolith with every
 * register.d fragment merged by SettingsService::deepMergeConfig().
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the metadataSuggestion schema fragment.
 */
class MetadataSuggestionSchemaTest extends TestCase {

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
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($fragment), true));
		}

		return $merged;
	}

	/**
	 * The schema is attached to the publication register with its own table.
	 *
	 * @return void
	 */
	public function testTheSchemaIsOnThePublicationRegister(): void {
		$register = $this->register()['components']['registers']['publication'];

		$this->assertContains(needle: 'metadataSuggestion', haystack: $register['schemas']);
		$this->assertTrue(condition: $register['configuration']['schemas']['metadataSuggestion']['magicMapping']);
	}

	/**
	 * A suggestion names its publication, field, value, source and a pending
	 * status, and only accept or reject end it.
	 *
	 * @return void
	 */
	public function testASuggestionWaitsForAPerson(): void {
		$schema = $this->register()['components']['schemas']['metadataSuggestion'];

		$this->assertSame(expected: ['publication', 'field', 'value', 'source', 'status'], actual: $schema['required']);
		$this->assertSame(expected: ['rules', 'hermiq'], actual: $schema['properties']['source']['enum']);
		$this->assertSame(expected: 'pending', actual: $schema['x-openregister-lifecycle']['initial']);
		$this->assertSame(expected: ['accepted', 'rejected'], actual: $schema['x-openregister-lifecycle']['final']);
		$this->assertSame(expected: ['pending'], actual: $schema['x-openregister-lifecycle']['transitions']['accept']['from']);
		$this->assertSame(expected: ['status' => 'pending'], actual: $schema['x-openregister-aggregations']['pendingByPublication']['filter']);
	}

	/**
	 * Suggestions are never readable anonymously.
	 *
	 * @return void
	 */
	public function testSuggestionsAreNotPublic(): void {
		$schema = $this->register()['components']['schemas']['metadataSuggestion'];

		$this->assertSame(expected: ['authenticated'], actual: $schema['authorization']['read']);
		$this->assertStringNotContainsString(needle: 'public', haystack: (string)json_encode($schema['authorization']));
	}
}
