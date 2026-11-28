<?php

/**
 * The MCP tool surface OpenRegister derives for OpenCatalogi (opencatalogi-mcp-adoption, task 4).
 *
 * Builds the catalogue the way OpenRegister does, in process: the schema tools
 * from the `x-openregister-mcp` blocks of the shipped register (monolith plus
 * every register.d fragment, merged by SettingsService::deepMergeConfig()), and
 * the service tools by running OpenRegister's own AttributeToolScanner over the
 * classes OpenCatalogiScannableServices declares. Re-importing the register on a
 * live instance is not needed to read what the catalogue holds.
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
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-mcp-surface-is-read-only-no-write-verb-is-declared-req-002
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Mcp\OpenCatalogiScannableServices;
use OCA\OpenCatalogi\Mcp\PublicationAgentTools;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Mcp\McpLeafApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The derived catalogue: eight read tools, no retired id, no read tool that
 * takes a publication date or a published flag.
 */
#[CoversNothing]
class DerivedToolSurfaceTest extends TestCase {

	/**
	 * The tool ids the removed hand-written provider served. None may come back.
	 */
	private const RETIRED_IDS = ['opencatalogi.searchCatalog', 'opencatalogi.getPublication'];

	/**
	 * Skip with the reason when OpenRegister's scanner is not next to the app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(AttributeToolScanner::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not next to the app, so its AttributeToolScanner cannot derive the catalogue.');
		}
	}//end setUp()

	/**
	 * The schema tool ids, `opencatalogi.<schema>.<verb>`, with each verb's block.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemaTools(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($settings . '/publication_register.json'), true);

		$merge = new \ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($fragment), true));
		}

		$tools = [];
		foreach ($merged['components']['schemas'] as $slug => $schema) {
			$dialect = $schema['configuration']['x-openregister-mcp'] ?? null;
			if (is_array($dialect) === false || ($dialect['enabled'] ?? false) !== true) {
				continue;
			}

			foreach ($dialect['tools'] as $verb => $tool) {
				$tools['opencatalogi.' . $slug . '.' . $verb] = $tool;
			}
		}

		return $tools;
	}//end schemaTools()

	/**
	 * The service tools OpenRegister's scanner derives from the given classes.
	 *
	 * @param list<class-string> $classes The scannable classes.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function serviceTools(array $classes): array {
		$app = new McpLeafApp(
			appId: 'opencatalogi',
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		return (new AttributeToolScanner())->scanClasses(app: $app, classNames: $classes);
	}//end serviceTools()

	/**
	 * The read service derives exactly the two curated tools, both read-only.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 *
	 * @return void
	 */
	public function testTheReadServiceDerivesTheTwoCuratedTools(): void {
		$tools = $this->serviceTools(classes: [PublicationService::class]);

		$ids = array_column($tools, 'id');
		sort($ids);
		$this->assertSame(expected: ['opencatalogi.listPublicationFiles', 'opencatalogi.searchCatalogPublications'], actual: $ids);
		foreach ($tools as $tool) {
			$this->assertTrue(condition: $tool['readOnlyHint'], message: $tool['id']);
			$this->assertFalse(condition: $tool['destructiveHint'], message: $tool['id']);
			$this->assertSame(expected: 'read', actual: $tool['scope'], message: $tool['id']);
		}
	}//end testTheReadServiceDerivesTheTwoCuratedTools()

	/**
	 * The read surface holds eight tools: search and get on the three curated
	 * schemas, plus the two service tools.
	 *
	 * @return void
	 */
	public function testTheReadSurfaceHoldsEightTools(): void {
		$schemaIds = array_keys($this->schemaTools());
		sort($schemaIds);
		$this->assertSame(
			expected: [
				'opencatalogi.catalog.get',
				'opencatalogi.catalog.search',
				'opencatalogi.publication.get',
				'opencatalogi.publication.search',
				'opencatalogi.theme.get',
				'opencatalogi.theme.search',
			],
			actual: $schemaIds
		);

		$serviceIds = array_column($this->serviceTools(classes: [PublicationService::class]), 'id');
		$this->assertCount(expectedCount: 8, haystack: array_merge($schemaIds, $serviceIds));
	}//end testTheReadSurfaceHoldsEightTools()

	/**
	 * No retired provider id comes back, and no id is served twice.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 *
	 * @return void
	 */
	public function testNoRetiredIdShadowsTheDerivedTools(): void {
		$classes = (new OpenCatalogiScannableServices())->getScannableServiceClasses();
		$ids = array_merge(array_keys($this->schemaTools()), array_column($this->serviceTools(classes: $classes), 'id'));

		foreach (self::RETIRED_IDS as $retired) {
			$this->assertNotContains(needle: $retired, haystack: $ids);
		}

		$this->assertSame(expected: $ids, actual: array_values(array_unique($ids)));
	}//end testNoRetiredIdShadowsTheDerivedTools()

	/**
	 * No read tool takes a publication date or a published flag, and every
	 * tool that is not read-only is one of the governed agent writes.
	 *
	 * @return void
	 */
	public function testNoReadToolCanSetThePublicationDate(): void {
		foreach ($this->schemaTools() as $id => $tool) {
			$this->assertSame(expected: 'read', actual: $tool['scope'], message: $id);
			$this->assertTrue(condition: $tool['readOnlyHint'], message: $id);
		}

		$classes = (new OpenCatalogiScannableServices())->getScannableServiceClasses();
		foreach ($this->serviceTools(classes: $classes) as $tool) {
			if (($tool['readOnlyHint'] ?? false) === true) {
				$properties = array_keys($tool['inputSchema']['properties'] ?? []);
				$this->assertEmpty(actual: array_intersect($properties, ['publicationDate', 'publicatiedatum', 'published']), message: $tool['id']);
				continue;
			}

			$this->assertSame(expected: PublicationAgentTools::class, actual: $tool['class'], message: $tool['id'] . ' writes outside the governed tools');
		}
	}//end testNoReadToolCanSetThePublicationDate()

	/**
	 * The whole derived catalogue holds twelve tools, the eight reads and
	 * exactly four writes, each write with the scope and reach REQ-007 names;
	 * the two external-reach tools say their distribution cannot be recalled.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 *
	 * @return void
	 */
	public function testTheCatalogueCarriesExactlyFourGovernedWrites(): void {
		$classes = (new OpenCatalogiScannableServices())->getScannableServiceClasses();
		$service = $this->serviceTools(classes: $classes);
		$this->assertCount(expectedCount: 12, haystack: array_merge(array_keys($this->schemaTools()), array_column($service, 'id')));

		$writes = [];
		foreach ($service as $tool) {
			$this->assertSame(expected: 1, actual: substr_count($tool['id'], '.'), message: $tool['id'] . ' is a two-segment curated id');
			if (($tool['readOnlyHint'] ?? false) === false) {
				$writes[$tool['id']] = [$tool['scope'], $tool['reach']];
				$this->assertNotSame(expected: 'delete', actual: $tool['scope'], message: $tool['id']);
			}
		}

		ksort($writes);
		$this->assertSame(
			expected: [
				'opencatalogi.archivePublication' => ['update', 'instance'],
				'opencatalogi.createDraftPublication' => ['create', 'instance'],
				'opencatalogi.depublishPublication' => ['update', 'external'],
				'opencatalogi.publishPublication' => ['update', 'external'],
			],
			actual: $writes
		);

		$descriptions = array_column($service, 'description', 'id');
		$this->assertStringContainsString(needle: 'cannot be undone', haystack: $descriptions['opencatalogi.publishPublication']);
		$this->assertStringContainsString(needle: 'does not recall', haystack: $descriptions['opencatalogi.depublishPublication']);
	}//end testTheCatalogueCarriesExactlyFourGovernedWrites()
}//end class
