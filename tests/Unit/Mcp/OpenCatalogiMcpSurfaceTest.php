<?php

/**
 * Unit tests for OpenCatalogi's MCP surface: the scannable services and the
 * x-openregister-mcp declarations in the publication register.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Mcp\OpenCatalogiScannableServices;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Mcp\IMcpScannableServices;
use PHPUnit\Framework\TestCase;

/**
 * The derived MCP surface is curated and read-only (opencatalogi-mcp-adoption).
 *
 * @covers \OCA\OpenCatalogi\Mcp\OpenCatalogiScannableServices
 */
class OpenCatalogiMcpSurfaceTest extends TestCase {

	/**
	 * The schemas that declare the dialect: the curated set that exists in the register.
	 *
	 * @var array<string, string[]>
	 */
	private const CURATED_FILTERS = [
		'publication' => ['title', 'organization', 'status', 'publicationDate', 'retentionCategory'],
		'catalog'     => ['title', 'organization', 'status', 'listed', 'slug'],
		'theme'       => ['title', 'sort'],
	];

	/**
	 * Every register document of the app: the base register and its fragments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registerDocuments(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$files = array_merge([$root . '/publication_register.json'], glob($root . '/register.d/*.json'));
		$documents = [];
		foreach ($files as $file) {
			$documents[basename($file)] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		}

		return $documents;
	}//end registerDocuments()

	/**
	 * The scannable services list PublicationService, which carries the curated tools.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-the-curated-service-tools-are-discovered
	 */
	public function testTheScannableServicesListPublicationService(): void {
		$services = new OpenCatalogiScannableServices();
		$this->assertInstanceOf(IMcpScannableServices::class, $services);
		$this->assertSame([PublicationService::class], $services->getScannableServiceClasses());
	}//end testTheScannableServicesListPublicationService()

	/**
	 * Only the curated schemas declare the dialect, each enabled with exactly a
	 * read-only search and get, and every search filter is a real property.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-mcp-surface-is-read-only-no-write-verb-is-declared-req-002
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-every-declared-search-filter-is-a-real-property-of-its-schema-req-003
	 */
	public function testOnlyTheCuratedSchemasDeclareAReadOnlySurface(): void {
		$declared = [];
		foreach ($this->registerDocuments() as $file => $document) {
			foreach (($document['components']['schemas'] ?? []) as $name => $schema) {
				$mcp = ($schema['configuration']['x-openregister-mcp'] ?? null);
				$this->assertArrayNotHasKey('x-openregister-mcp', $schema, $file . ' ' . $name . ': the declaration lives under configuration');
				if ($mcp === null) {
					continue;
				}

				$declared[] = $name;
				$this->assertSame('publication_register.json', $file, $name . ' is declared outside the base register');
				$this->assertTrue($mcp['enabled']);
				$this->assertSame(['search', 'get'], array_keys($mcp['tools']), $name . ' declares only search and get');
				foreach ($mcp['tools'] as $verb => $tool) {
					$this->assertSame('read', $tool['scope'], $name . '.' . $verb);
					$this->assertTrue($tool['readOnlyHint'], $name . '.' . $verb);
					$this->assertNotSame('', trim($tool['description']), $name . '.' . $verb);
				}

				$this->assertSame(self::CURATED_FILTERS[$name] ?? null, $mcp['tools']['search']['filters'], $name . ' search filters');
				foreach ($mcp['tools']['search']['filters'] as $filter) {
					$this->assertArrayHasKey($filter, $schema['properties'], $name . ' filter ' . $filter . ' is a property');
				}
			}//end foreach
		}//end foreach

		$this->assertSame(array_keys(self::CURATED_FILTERS), $declared);
	}//end testOnlyTheCuratedSchemasDeclareAReadOnlySurface()

	/**
	 * The admin-only usage counter and the other left-out schemas derive no tool.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-the-admin-only-usage-counter-derives-no-tool
	 */
	public function testTheLeftOutSchemasDeclareNothing(): void {
		$schemas = $this->registerDocuments()['publication_register.json']['components']['schemas'];
		foreach (['listing', 'glossary', 'usageCounter'] as $name) {
			$this->assertArrayHasKey($name, $schemas);
			$this->assertArrayNotHasKey('x-openregister-mcp', ($schemas[$name]['configuration'] ?? []), $name);
		}
	}//end testTheLeftOutSchemasDeclareNothing()
}//end class
