<?php

/**
 * Tests for the shipped council documents harvest: its flows, mappings,
 * catalogue and the integriq templates set-up writes.
 *
 * All of it is configuration, so nothing fails loudly at run time. A flow
 * shipped enabled starts reading a council system the moment the app is
 * installed; a mapping that forgets the Woo category puts council papers in
 * the wrong catalogue; a filter that lets a confidential document through
 * publishes it. Each is asserted here because nothing else would notice.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CouncilHarvestFragmentTest extends TestCase {

	/**
	 * The integriq sources the templates may name: the council systems integriq
	 * ships in configurations/decidesk-ris-import/sources.
	 *
	 * @var array<string, string>
	 */
	private const INTEGRIQ_SOURCES = ['notubiz' => 'notubiz-ris-v1', 'ibabs' => 'ibabs-ris-v1'];

	/**
	 * The parsed harvest fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * The parsed source templates.
	 *
	 * @var array<string, mixed>
	 */
	private array $sources;

	/**
	 * Parse both files once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$root = __DIR__ . '/../../..';
		$this->fragment = (array)json_decode((string)file_get_contents($root . '/lib/Settings/register.d/council-documents-harvest.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->sources = (array)json_decode((string)file_get_contents($root . '/lib/Settings/council-documents-sources.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end setUp()

	/**
	 * The flows, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function flows(): array {
		$flows = [];
		foreach ($this->fragment['components']['schemas']['publication']['configuration']['x-openregister-flows'] as $flow) {
			$flows[$flow['name']] = $flow;
		}

		return $flows;
	}//end flows()

	/**
	 * One node of a flow by id.
	 *
	 * @param array<string, mixed> $flow The flow.
	 * @param string $id The node id.
	 *
	 * @return array<string, mixed>
	 */
	private static function node(array $flow, string $id): array {
		foreach ($flow['nodes'] as $node) {
			if ($node['id'] === $id) {
				return $node;
			}
		}

		self::fail('No node ' . $id . ' in ' . $flow['name']);
	}//end node()

	/**
	 * Every flow ships switched off, with a nightly schedule and a manual trigger (REQ-CDH-001).
	 *
	 * @return void
	 */
	public function testTheFlowShipsDisabled(): void {
		$flows = $this->flows();
		$this->assertSame(['Council documents harvest (Notubiz)', 'Council documents harvest (iBabs)'], array_keys($flows));
		foreach ($flows as $flow) {
			$this->assertFalse($flow['enabled'], $flow['name'] . ' must ship disabled');
			$this->assertSame('opencatalogi', $flow['app']);
			$this->assertSame('30 2 * * *', self::node($flow, 'nightly')['config']['cron']);
			$this->assertSame('openregister.trigger-manual', self::node($flow, 'on-demand')['type']);
		}
	}//end testTheFlowShipsDisabled()

	/**
	 * Every mapping sets Woo category infocat008 and the source identifier the write matches on (REQ-CDH-002, REQ-CDH-003).
	 *
	 * @return void
	 */
	public function testTheMappingSetsCategoryInfocat008(): void {
		$mappings = $this->fragment['components']['mappings'];
		$this->assertCount(4, $mappings);
		foreach ($mappings as $slug => $mapping) {
			$kind = explode('-', substr($slug, strlen('council-document-to-publication-')))[0];
			$this->assertSame('infocat008', $mapping['mapping']['publication.wooCategory'], $slug);
			$this->assertSame($kind . ':{{ document.id }}', $mapping['mapping']['publication.caseReference'], $slug);
			$this->assertStringContainsString('meeting of', $mapping['mapping']['publication.summary'], $slug);
		}
	}//end testTheMappingSetsCategoryInfocat008()

	/**
	 * Only the published-on-arrival mappings carry a publication date; the default ones arrive as concepts (REQ-CDH-002).
	 *
	 * @return void
	 */
	public function testOnlyThePublishOnArrivalMappingsSetAPublicationDate(): void {
		foreach ($this->fragment['components']['mappings'] as $slug => $mapping) {
			$this->assertSame(
				str_ends_with($slug, '-published'),
				array_key_exists('publication.publicationDate', $mapping['mapping']),
				$slug
			);
		}

		foreach ($this->flows() as $flow) {
			$this->assertStringEndsNotWith('-published', self::node($flow, 'map')['config']['mapping'], 'A flow ships writing concepts');
		}
	}//end testOnlyThePublishOnArrivalMappingsSetAPublicationDate()

	/**
	 * The filter lets only documents the source marks public through, with an id, a title and a file (REQ-CDH-002).
	 *
	 * @return void
	 */
	public function testTheFilterKeepsOnlyPublicDocumentsWithATitleAndAFile(): void {
		$flows = $this->flows();
		$notubiz = self::node($flows['Council documents harvest (Notubiz)'], 'public-only')['config']['condition']['and'];
		$this->assertContains(['!' => ['var' => 'json.document.confidential']], $notubiz);
		$this->assertContains(['!!' => [['var' => 'json.document.url']]], $notubiz);

		$ibabs = self::node($flows['Council documents harvest (iBabs)'], 'public-only')['config']['condition']['and'];
		$this->assertContains(['==' => [['var' => 'json.document.isPublic'], true]], $ibabs);
		$this->assertContains(['!!' => [['var' => 'json.document.downloadUrl']]], $ibabs);

		foreach ($flows as $flow) {
			$this->assertContains(['!!' => [['var' => 'json.document.id']]], self::node($flow, 'public-only')['config']['condition']['and']);
			$this->assertContains(['!!' => [['var' => 'json.document.title']]], self::node($flow, 'public-only')['config']['condition']['and']);
		}
	}//end testTheFilterKeepsOnlyPublicDocumentsWithATitleAndAFile()

	/**
	 * The write upserts on the source identifier, so a second run creates nothing new (REQ-CDH-003).
	 *
	 * @return void
	 */
	public function testTheWriteUpsertsOnTheSourceIdentifier(): void {
		foreach ($this->flows() as $flow) {
			$write = self::node($flow, 'write')['config'];
			$this->assertSame('upsert', $write['operation']);
			$this->assertSame(['caseReference' => '{{ publication.caseReference }}'], $write['match']);
			$this->assertSame(['publication', 'publication'], [$write['register'], $write['schema']]);
		}
	}//end testTheWriteUpsertsOnTheSourceIdentifier()

	/**
	 * The steps run in order from both triggers to the file fetch, with nothing hanging loose.
	 *
	 * @return void
	 */
	public function testTheStepsRunInOrderFromBothTriggers(): void {
		foreach ($this->flows() as $flow) {
			$edges = array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['to'], $flow['edges']);
			$this->assertSame(
				['nightly>documents', 'on-demand>documents', 'documents>each-document', 'each-document>public-only', 'public-only>map', 'map>write', 'write>file'],
				$edges
			);
			$this->assertSame('openconnector.fetch-file', self::node($flow, 'file')['type']);
		}
	}//end testTheStepsRunInOrderFromBothTriggers()

	/**
	 * The Raadsinformatie catalogue holds the publication schema, narrowed to Woo category infocat008 (REQ-CDH-002).
	 *
	 * @return void
	 */
	public function testTheRaadsinformatieCatalogueIsSeeded(): void {
		$objects = $this->fragment['components']['objects'];
		$this->assertCount(1, $objects);
		$this->assertSame(['register' => 'publication', 'schema' => 'catalog', 'slug' => 'raadsinformatie'], $objects[0]['@self']);
		$this->assertSame('Raadsinformatie', $objects[0]['title']);
		$this->assertSame(['wooCategory' => 'infocat008'], $objects[0]['filters']);
		$this->assertSame(['publication'], $objects[0]['schemas']);
	}//end testTheRaadsinformatieCatalogueIsSeeded()

	/**
	 * Every template names an existing integriq source, and each flow names the synchronization and rule set-up writes (REQ-CDH-001).
	 *
	 * @return void
	 */
	public function testEveryTemplateNamesAnExistingIntegriqSourceSlug(): void {
		$flows = $this->flows();
		$this->assertSame(array_keys(self::INTEGRIQ_SOURCES), array_keys($this->sources['kinds']));
		foreach ($this->sources['kinds'] as $kind => $template) {
			$source = self::INTEGRIQ_SOURCES[$kind];
			$this->assertSame($source, $template['source']);
			$this->assertSame($source, $template['synchronization']['sourceId']);
			$this->assertSame($source, $template['rule']['configuration']['fetch_file']['source']);
			$this->assertSame('fetch_file', $template['rule']['type']);
			$this->assertNotEmpty($template['required']);

			$flow = $flows[$template['flow']];
			$this->assertSame($template['synchronization']['slug'], self::node($flow, 'documents')['config']['synchronization']);
			$this->assertSame($template['rule']['slug'], self::node($flow, 'file')['config']['rule']);
		}
	}//end testEveryTemplateNamesAnExistingIntegriqSourceSlug()
}//end class
