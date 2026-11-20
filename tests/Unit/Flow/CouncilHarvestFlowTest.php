<?php

/**
 * Tests for the Notubiz council documents harvest, run over a recorded page.
 *
 * The flow is configuration that OpenRegister and integriq execute, so this
 * test replays its tail itself over a recorded Notubiz page: the page is read
 * at the synchronization's `resultsPosition`, each document goes through the
 * flow's own filter condition (JSON Logic) and the flow's own mapping (Twig,
 * as OpenRegister renders it), and the write upserts on the flow's own
 * `match`. A changed filter, mapping or match key changes what this replay
 * writes, which is what the change's REQ-CDH-003 is about: a second run
 * updates and creates nothing.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/tasks.md#2-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @coversNothing The flow and its mapping are configuration; nothing in lib/ runs here.
 */
class CouncilHarvestFlowTest extends TestCase {

	/**
	 * The Notubiz flow.
	 *
	 * @var array<string, mixed>
	 */
	private array $flow;

	/**
	 * The mappings of the fragment, by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $mappings;

	/**
	 * The Notubiz synchronization and rule templates.
	 *
	 * @var array<string, mixed>
	 */
	private array $kind;

	/**
	 * The publications written so far, by match key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * How many writes created a publication and how many updated one.
	 *
	 * @var array{created: int, updated: int}
	 */
	private array $counts = ['created' => 0, 'updated' => 0];

	/**
	 * Read the shipped fragment and sources file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$root     = __DIR__ . '/../../..';
		$fragment = json_decode((string)file_get_contents($root . '/lib/Settings/register.d/council-documents-harvest.json'), true);
		$sources  = json_decode((string)file_get_contents($root . '/lib/Settings/council-documents-sources.json'), true);

		$this->kind     = $sources['kinds']['notubiz'];
		$this->mappings = $fragment['components']['mappings'];
		foreach ($fragment['components']['schemas']['publication']['configuration']['x-openregister-flows'] as $flow) {
			if ($flow['name'] === $this->kind['flow']) {
				$this->flow = $flow;
			}
		}

		$this->assertNotEmpty($this->flow ?? null, 'the Notubiz flow is shipped under the name the sources file names');
	}//end setUp()

	/**
	 * A public document with a file becomes one concept publication, and the
	 * file node that follows the write can find its file.
	 *
	 * @return void
	 */
	public function testAPublicDocumentBecomesAConceptPublicationWithItsFile(): void {
		$files = $this->harvest(page: $this->recordedPage());

		$this->assertSame(['notubiz:4410021'], array_keys($this->store));
		$this->assertSame(
			[
				'title' => 'Raadsvoorstel vaststellen bestemmingsplan Centrum',
				'summary' => 'Gemeenteraad, meeting of 2026-10-08',
				'wooCategory' => 'infocat008',
				'caseReference' => 'notubiz:4410021',
			],
			$this->store['notubiz:4410021']
		);
		$this->assertArrayNotHasKey('publicationDate', $this->store['notubiz:4410021'], 'a concept carries no publication date');
		$this->assertSame(['notubiz:4410021' => 'https://api.notubiz.nl/document/4410021/1'], $files);
	}//end testAPublicDocumentBecomesAConceptPublicationWithItsFile()

	/**
	 * A confidential document and one without a file are never written.
	 *
	 * @return void
	 */
	public function testADocumentNotMarkedPublicIsNotWritten(): void {
		$this->harvest(page: $this->recordedPage());

		$this->assertArrayNotHasKey('notubiz:4410022', $this->store, 'a confidential document');
		$this->assertArrayNotHasKey('notubiz:4410023', $this->store, 'a document without a file');
		$this->assertSame(['created' => 1, 'updated' => 0], $this->counts);
	}//end testADocumentNotMarkedPublicIsNotWritten()

	/**
	 * A second run over the same page, with one title corrected at the
	 * source, updates the publication it wrote and creates nothing.
	 *
	 * @return void
	 */
	public function testASecondRunUpdatesAndCreatesNothing(): void {
		$page = $this->recordedPage();
		$this->harvest(page: $page);

		$page['documents'][0]['title'] = 'Raadsvoorstel vaststellen bestemmingsplan Centrum (gewijzigd)';
		$this->counts = ['created' => 0, 'updated' => 0];
		$this->harvest(page: $page);

		$this->assertSame(['created' => 0, 'updated' => 1], $this->counts);
		$this->assertCount(1, $this->store);
		$this->assertSame('Raadsvoorstel vaststellen bestemmingsplan Centrum (gewijzigd)', $this->store['notubiz:4410021']['title']);
	}//end testASecondRunUpdatesAndCreatesNothing()

	/**
	 * The recorded page.
	 *
	 * @return array<string, mixed>
	 */
	private function recordedPage(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/notubiz/documents-page.json'), true);
	}//end recordedPage()

	/**
	 * Replay the flow's tail over one raw page, following its edges from the
	 * paginate node: explode, filter, map, write, file.
	 *
	 * @param array<string, mixed> $page The raw source page.
	 *
	 * @return array<string, string> The file address the file node reads, by the publication it attaches to.
	 */
	private function harvest(array $page): array {
		$nodes = array_column($this->flow['nodes'], null, 'id');
		$order = $this->chainFrom(start: 'documents');
		$this->assertSame(
			['openregister.explode', 'openregister.filter', 'openregister.map', 'openregister.object-write', 'openconnector.fetch-file'],
			array_map(static fn (string $id): string => $nodes[$id]['type'], $order)
		);
		[$explode, $filter, $map, $write, $file] = array_map(static fn (string $id): array => $nodes[$id]['config'], $order);

		// What source-paginate hands on: the page's items at the synchronization's resultsPosition.
		$context = ['page' => ['results' => $page[$this->kind['synchronization']['sourceConfig']['resultsPosition']]]];
		$this->assertSame('page.results', $explode['path']);
		$this->assertSame('opencatalogi-council-documents-notubiz', $nodes['documents']['config']['synchronization']);

		$files = [];
		foreach ($context['page']['results'] as $document) {
			$item = [$explode['as'] => $document];
			if ($this->logic(rule: $filter['condition'], data: ['json' => $item]) !== true) {
				continue;
			}

			$item['publication'] = $this->mapped(slug: $map['mapping'], input: $item);
			$key = $this->upsert(write: $write, item: $item);

			$this->assertSame($this->kind['rule']['slug'], $file['rule']);
			$files[$key] = (string)$this->at(data: $item, path: $this->kind['rule']['configuration']['fetch_file']['filePath']);
		}

		return $files;
	}//end harvest()

	/**
	 * The node ids after a node, following the single outgoing edge of each.
	 *
	 * @param string $start The node to start after.
	 *
	 * @return array<int, string>
	 */
	private function chainFrom(string $start): array {
		$next  = array_column($this->flow['edges'], 'to', 'from');
		$chain = [];
		$at    = $start;
		while (isset($next[$at]) === true) {
			$at      = $next[$at];
			$chain[] = $at;
		}

		return $chain;
	}//end chainFrom()

	/**
	 * Render a mapping as OpenRegister does: each dotted key a path in the
	 * output, each value a Twig template over the input. Empty values are left out.
	 *
	 * @param string               $slug  The mapping slug.
	 * @param array<string, mixed> $input The item being mapped.
	 *
	 * @return array<string, mixed> The publication.
	 */
	private function mapped(string $slug, array $input): array {
		$out = [];
		foreach ($this->mappings[$slug]['mapping'] as $target => $template) {
			$value = $this->render(template: $template, data: $input);
			if ($value === '') {
				continue;
			}

			$this->assertStringStartsWith('publication.', $target, 'the write reads its payload from publication');
			$out[substr($target, strlen('publication.'))] = $value;
		}

		return $out;
	}//end mapped()

	/**
	 * Write the mapped publication, matched on the write node's own match key.
	 *
	 * @param array<string, mixed> $write The object-write config.
	 * @param array<string, mixed> $item  The item, with its publication.
	 *
	 * @return string The match key.
	 */
	private function upsert(array $write, array $item): string {
		$this->assertSame('upsert', $write['operation']);
		$this->assertSame(['caseReference'], array_keys($write['match']));
		$key = $this->render(template: $write['match']['caseReference'], data: $item);
		$this->assertNotSame('', $key, 'a document is always matched on its source identifier');

		if (isset($this->store[$key]) === true) {
			$this->counts['updated']++;
		} else {
			$this->counts['created']++;
		}

		$this->store[$key] = $item[$write['payloadFrom']];

		return $key;
	}//end upsert()

	/**
	 * A Twig template rendered over data.
	 *
	 * @param string               $template The template.
	 * @param array<string, mixed> $data     The data.
	 *
	 * @return string
	 */
	private function render(string $template, array $data): string {
		$twig = new Environment(new ArrayLoader(['t' => $template]), ['strict_variables' => false, 'autoescape' => false]);

		return trim($twig->render('t', $data));
	}//end render()

	/**
	 * The value at a dotted path, or null.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $path The dotted path.
	 *
	 * @return mixed
	 */
	private function at(array $data, string $path): mixed {
		foreach (explode('.', $path) as $part) {
			if (is_array($data) === false || array_key_exists($part, $data) === false) {
				return null;
			}

			$data = $data[$part];
		}

		return $data;
	}//end at()

	/**
	 * The JSON Logic operators the shipped filters use, evaluated as JSON Logic does.
	 *
	 * @param mixed                $rule The rule.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return mixed
	 */
	private function logic(mixed $rule, array $data): mixed {
		if (is_array($rule) === false || array_is_list($rule) === true) {
			return $rule;
		}

		$operator = (string)array_key_first($rule);
		$args     = $rule[$operator];
		if (is_array($args) === false || array_is_list($args) === false) {
			$args = [$args];
		}

		return match ($operator) {
			'var' => $this->at(data: $data, path: (string)$args[0]),
			'!!' => $this->truthy(value: $this->logic(rule: $args[0], data: $data)),
			'!' => $this->truthy(value: $this->logic(rule: $args[0], data: $data)) === false,
			'==' => $this->logic(rule: $args[0], data: $data) == $this->logic(rule: $args[1], data: $data),
			'and' => array_reduce($args, fn (bool $carry, mixed $part): bool => $carry && $this->truthy(value: $this->logic(rule: $part, data: $data)), true),
			default => throw new RuntimeException('Operator not replayed by this test: ' . $operator),
		};
	}//end logic()

	/**
	 * JSON Logic truthiness: empty arrays, empty strings, 0 and null are false.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function truthy(mixed $value): bool {
		if (is_array($value) === true) {
			return $value !== [];
		}

		return (bool)$value;
	}//end truthy()
}//end class
