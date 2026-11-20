<?php

/**
 * The MCP dialect OpenCatalogi declares on its schemas (opencatalogi-mcp-adoption).
 *
 * Reads the SHIPPED register, the monolith with every register.d fragment merged
 * onto it through SettingsService::deepMergeConfig() itself, so the surface
 * asserted here is the one an instance imports.
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
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `x-openregister-mcp` blocks of the shipped register.
 */
#[CoversNothing]
class McpToolSurfaceDeclarationTest extends TestCase {

	/**
	 * The curated schemas and the search filters each declares.
	 *
	 * `document` and `organization` from the change's first list no longer exist
	 * in this register: organisations are OpenRegister's shared `nc-organisation`,
	 * and a publication's documents are Nextcloud files (listPublicationFiles).
	 */
	private const CURATED = [
		'publication' => ['title', 'organization', 'status', 'publicationDate', 'retentionCategory'],
		'catalog' => ['title', 'organization', 'status', 'listed', 'slug'],
		'theme' => ['title', 'sort'],
	];

	/**
	 * The shipped register's schemas, monolith plus every register.d fragment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
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

		return $merged['components']['schemas'];
	}

	/**
	 * Exactly the curated schemas carry the dialect, each enabled; every other
	 * schema (the admin-only usage counter included) derives no tool.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-exactly-five-curated-schemas-declare-the-mcp-dialect-req-001
	 *
	 * @return void
	 */
	public function testOnlyTheCuratedSchemasDeclareTheDialect(): void {
		$declaring = [];
		foreach ($this->schemas() as $slug => $schema) {
			if (isset($schema['configuration']['x-openregister-mcp']) === true) {
				$declaring[] = $slug;
				$this->assertTrue($schema['configuration']['x-openregister-mcp']['enabled'], $slug);
				$this->assertFalse($schema['configuration']['autoPublish'], $slug . ' keeps autoPublish off');
			}
		}

		sort($declaring);
		$this->assertSame(['catalog', 'publication', 'theme'], $declaring);
	}

	/**
	 * Every declared verb is a read: search or get, scope read, readOnlyHint true.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-mcp-surface-is-read-only-no-write-verb-is-declared-req-002
	 *
	 * @return void
	 */
	public function testTheSurfaceIsReadOnly(): void {
		$schemas = $this->schemas();
		foreach (array_keys(self::CURATED) as $slug) {
			$tools = $schemas[$slug]['configuration']['x-openregister-mcp']['tools'];
			$this->assertSame(['search', 'get'], array_keys($tools), $slug);
			foreach ($tools as $verb => $tool) {
				$this->assertSame('read', $tool['scope'], "$slug.$verb");
				$this->assertTrue($tool['readOnlyHint'], "$slug.$verb");
				$this->assertNotSame('', trim($tool['description']), "$slug.$verb");
			}
		}

		$fragment = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/mcp-tool-surface.json');
		$this->assertDoesNotMatchRegularExpression('/"(create|update|delete)"\s*:/', $fragment);
	}

	/**
	 * Every search filter is a real property of its schema, as declared.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-every-declared-search-filter-is-a-real-property-of-its-schema-req-003
	 *
	 * @return void
	 */
	public function testEverySearchFilterIsARealProperty(): void {
		$schemas = $this->schemas();
		foreach (self::CURATED as $slug => $filters) {
			$search = $schemas[$slug]['configuration']['x-openregister-mcp']['tools']['search'];
			$this->assertSame($filters, $search['filters'], $slug);
			foreach ($filters as $filter) {
				$this->assertArrayHasKey($filter, $schemas[$slug]['properties'], "$slug filter $filter");
			}
		}
	}

	/**
	 * OpenRegister's own dialect validator accepts every block, so the import
	 * does not fail on it.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-every-declared-search-filter-is-a-real-property-of-its-schema-req-003
	 *
	 * @return void
	 */
	public function testOpenRegistersValidatorAcceptsEveryBlock(): void {
		$validatorClass = 'OCA\\OpenRegister\\Mcp\\Service\\Mcp\\McpAnnotationValidator';
		$this->assertTrue(class_exists($validatorClass), 'OpenRegister is checked out beside the app');

		$validator = new $validatorClass();
		$schemas = $this->schemas();
		foreach (array_keys(self::CURATED) as $slug) {
			$errors = $validator->validate(schema: [
				'x-openregister-mcp' => $schemas[$slug]['configuration']['x-openregister-mcp'],
				'properties' => $schemas[$slug]['properties'],
			]);
			$this->assertSame([], $errors, $slug);
		}

		// The validator is a real gate: an unknown filter is refused.
		$refused = $validator->validate(schema: [
			'x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => ['publicatiedatum']]]],
			'properties' => $schemas['publication']['properties'],
		]);
		$this->assertSame('mcp-unknown-filter-property', $refused[0]['code']);
	}
}
