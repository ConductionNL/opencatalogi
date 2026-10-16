<?php

/**
 * Tests for the shipped developer.overheid.nl publiccode harvest flow and its
 * synchronization file.
 *
 * Both are configuration, so nothing about them fails loudly at run time. A
 * branch edge that joins another branch before the end node makes one forge's
 * items flow through another forge's fetch. A router instead of filters leaves
 * the join waiting for branches that never got a token. A flow shipped enabled
 * starts calling the register the moment the app is installed. Each of those
 * is asserted here because nothing else would notice.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-001-the-harvest-flow-ships-with-the-app-and-stays-inert-until-adopted
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCA\OpenRegister\Service\MappingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenCatalogi\Service\PubliccodeHarvestService
 */
class DeveloperOverheidHarvestFlowTest extends TestCase {

	/**
	 * The three branches, by forge filter value.
	 *
	 * @var array<string, string>
	 */
	private const BRANCHES = ['github' => 'github', 'gitlab' => 'gitlab', 'register' => 'other'];

	/**
	 * The parsed harvest fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * The parsed synchronization file.
	 *
	 * @var array<string, mixed>
	 */
	private array $definitions;

	/**
	 * Parse both files once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$root = __DIR__ . '/../../..';
		$this->fragment = (array)json_decode(
			(string)file_get_contents($root . '/lib/Settings/register.d/publiccode-developer-overheid-harvest.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->definitions = (array)json_decode(
			(string)file_get_contents($root . PubliccodeHarvestService::DEVELOPER_OVERHEID_FILE),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}//end setUp()

	/**
	 * The one shipped flow.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(): array {
		$flows = $this->fragment['components']['schemas']['publiccode']['configuration']['x-openregister-flows'];
		$this->assertCount(1, $flows);

		return (array)$flows[0];
	}//end flow()

	/**
	 * The flow's nodes by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes(): array {
		return array_column($this->flow()['nodes'], null, 'id');
	}//end nodes()

	/**
	 * Outgoing node ids per node id.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function next(): array {
		$next = [];
		foreach ($this->flow()['edges'] as $edge) {
			$next[$edge['from']][] = $edge['to'];
		}

		return $next;
	}//end next()

	/**
	 * Load JSON Logic with the casts OpenRegister registers, or skip.
	 *
	 * json-logic-php is an OpenRegister dependency. When this app's vendor does
	 * not carry it, it is loaded from OpenRegister's own vendor next to it.
	 *
	 * @return void
	 */
	private function jsonLogic(): void {
		if (class_exists(\JWadhams\JsonLogic::class) === false && class_exists(MappingService::class) === true) {
			$openRegister = dirname((string)(new ReflectionClass(MappingService::class))->getFileName(), 3);
			$file = $openRegister . '/vendor/jwadhams/json-logic-php/src/JWadhams/JsonLogic.php';
			if (is_readable($file) === true) {
				include_once $file;
			}
		}

		if (class_exists(\JWadhams\JsonLogic::class) === false) {
			$this->markTestSkipped('json-logic-php (an OpenRegister dependency) is not installed here or next to this app.');
		}

		// The same casts FlowExpression registers: PHP's (string) cast of a list
		// yields "Array" (with a warning the engine's logger absorbs), so a list
		// reads as "array" here too, spelled out to keep the test run clean.
		$text = static fn (mixed $a): string => is_array($a) === true ? 'Array' : (string)$a;
		\JWadhams\JsonLogic::add_operation('lower', static fn ($a) => mb_strtolower($text($a)));
		\JWadhams\JsonLogic::add_operation('trim', static fn ($a) => trim($text($a)));
	}//end jsonLogic()

	/**
	 * The flow ships disabled, unowned, under the name the admin service looks
	 * it up by, with the schedule and limits of the design.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-001-the-harvest-flow-ships-with-the-app-and-stays-inert-until-adopted
	 *
	 * @return void
	 */
	public function testTheFlowShipsDisabledUnderTheNameTheServiceFinds(): void {
		$flow = $this->flow();

		$this->assertFalse($flow['enabled']);
		$this->assertArrayNotHasKey('owner', $flow);
		$this->assertSame(PubliccodeHarvestService::DEVELOPER_OVERHEID_FLOW_NAME, $flow['name']);
		$this->assertSame(PubliccodeHarvestService::FLOW_APP, $flow['app']);
		$this->assertSame('schedule', $flow['trigger']);
		$this->assertSame('async', $flow['executionMode']);
		$this->assertSame(60, $flow['limits']['maxRuntimeMinutes']);
		$this->assertSame(['cron' => '15 4 * * *', 'runAs' => 'admin'], $this->nodes()['nightly']['config']);
	}//end testTheFlowShipsDisabledUnderTheNameTheServiceFinds()

	/**
	 * Both triggers lead to the list step and nothing else; the list step
	 * explodes into repositories, which the locate step fans out to exactly the
	 * three forge filters.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-001-the-harvest-flow-ships-with-the-app-and-stays-inert-until-adopted
	 *
	 * @return void
	 */
	public function testBothTriggersLeadToTheListAndLocateFansOutToThreeFilters(): void {
		$nodes = $this->nodes();
		$next = $this->next();

		$this->assertSame('openregister.trigger-schedule', $nodes['nightly']['type']);
		$this->assertSame('openregister.trigger-manual', $nodes['on-demand']['type']);
		$this->assertSame(['list'], $next['nightly']);
		$this->assertSame(['list'], $next['on-demand']);
		$this->assertSame(['repos'], $next['list']);
		$this->assertSame(['locate'], $next['repos']);
		$this->assertSame(['to-github', 'to-gitlab', 'to-register'], $next['locate']);

		$this->assertSame('openconnector.source-paginate', $nodes['list']['type']);
		$this->assertSame('openregister.explode', $nodes['repos']['type']);
		$this->assertSame(['path' => 'page.results', 'as' => 'repo', 'keepRecord' => false], $nodes['repos']['config']);
		$this->assertSame('openregister.map', $nodes['locate']['type']);
		$this->assertSame('publiccode-developer-overheid-locate', $nodes['locate']['config']['mapping']);
		$this->assertSame('continue', $nodes['locate']['onError'], 'A URL that trips the template must drop its repository, not the run.');

		foreach (self::BRANCHES as $branch => $forge) {
			$this->assertSame('openregister.filter', $nodes['to-' . $branch]['type']);
			$this->assertSame(['==' => [['var' => 'json.locate.forge'], $forge]], $nodes['to-' . $branch]['config']['condition']);
		}
	}//end testBothTriggersLeadToTheListAndLocateFansOutToThreeFilters()

	/**
	 * Each branch is one chain that meets the others only at the end node,
	 * which waits for all three.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-001-the-harvest-flow-ships-with-the-app-and-stays-inert-until-adopted
	 *
	 * @return void
	 */
	public function testEachBranchIsItsOwnChainJoinedOnlyAtTheEnd(): void {
		$flow = $this->flow();
		$nodes = $this->nodes();
		$next = $this->next();

		$chains = [
			'github' => ['to-github', 'fetch-github', 'decoded-github', 'map-github', 'write-github', 'end'],
			'gitlab' => ['to-gitlab', 'fetch-gitlab', 'decoded-gitlab', 'map-gitlab', 'write-gitlab', 'end'],
			'register' => ['to-register', 'detail', 'lift', 'decoded-register', 'map-register', 'write-register', 'end'],
		];
		foreach ($chains as $branch => $expected) {
			$chain = ['to-' . $branch];
			while (isset($next[end($chain)]) === true) {
				$this->assertCount(1, $next[end($chain)], 'The ' . $branch . ' branch is linear.');
				$chain[] = $next[end($chain)][0];
			}

			$this->assertSame($expected, $chain);
		}

		$into = [];
		foreach ($flow['edges'] as $edge) {
			$into[$edge['to']][] = $edge['from'];
			$this->assertArrayHasKey($edge['from'], $nodes, 'Edge from an unknown node: ' . $edge['id']);
			$this->assertArrayHasKey($edge['to'], $nodes, 'Edge to an unknown node: ' . $edge['id']);
		}

		foreach ($nodes as $id => $node) {
			if (str_starts_with($node['type'], 'openregister.trigger-') === true) {
				$this->assertArrayNotHasKey($id, $into, 'A trigger has no way in.');
				continue;
			}

			if ($id === 'list' || $id === 'end') {
				continue;
			}

			$this->assertCount(1, $into[$id], 'Only the end node may have more than one way in: ' . $id);
		}

		// The end node stops the WHOLE run, so it must wait for every branch.
		$this->assertSame('openregister.end', $nodes['end']['type']);
		$this->assertTrue($nodes['end']['join']);
		$this->assertSame(['write-github', 'write-gitlab', 'write-register'], $into['end']);
		$this->assertSame(['nightly', 'on-demand'], $into['list']);

		// Every node is reachable and every node type is one the GitHub
		// harvest's flow already runs, so OpenRegister or integriq registers it.
		$this->assertCount(count($nodes), array_unique(array_column($flow['nodes'], 'id')), 'Node ids are unique.');
		$github = (array)json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/publiccode-github-harvest.json'), true);
		$knownTypes = array_unique(array_column($github['components']['schemas']['publiccode']['configuration']['x-openregister-flows'][0]['nodes'], 'type'));
		foreach ($nodes as $id => $node) {
			$this->assertContains($node['type'], $knownTypes, $id . ' uses a node type no shipped flow runs.');
		}
	}//end testEachBranchIsItsOwnChainJoinedOnlyAtTheEnd()

	/**
	 * Each branch fetches from its own source, never from an absolute URL,
	 * and carries a failed fetch, map or write past instead of ending the run.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-003-each-repository-is-fetched-from-its-own-forge
	 *
	 * @return void
	 */
	public function testEachBranchFetchesFromItsOwnSourceAndCarriesFailuresPast(): void {
		$nodes = $this->nodes();
		$mappings = $this->fragment['components']['mappings'];

		foreach (['github' => 'github-raw', 'gitlab' => 'gitlab-raw'] as $branch => $source) {
			$fetch = $nodes['fetch-' . $branch];
			$this->assertSame('openconnector.source-call', $fetch['type']);
			$this->assertSame($source, $fetch['config']['source']);
			$this->assertSame('{{ locate.rawPath }}', $fetch['config']['endpoint']);
			$this->assertSame('GET', $fetch['config']['method']);
			$this->assertSame('yaml', $fetch['config']['decode']);
			$this->assertSame('publiccode', $fetch['config']['output']);
			// source-call reads onError from its config: a node-level key alone lets one bad file fail the whole page.
			$this->assertSame('continue', $fetch['config']['onError']);
			$this->assertSame('continue', $fetch['onError']);
		}

		$detail = $nodes['detail'];
		$this->assertSame('openconnector.source-call', $detail['type']);
		$this->assertSame('developer-overheid-oss', $detail['config']['source']);
		$this->assertSame('/repositories/{{ repo.id }}', $detail['config']['endpoint']);
		$this->assertSame('json', $detail['config']['decode']);
		$this->assertSame('detail', $detail['config']['output']);
		$this->assertSame('continue', $detail['config']['onError']);
		$this->assertSame('publiccode-developer-overheid-detail', $nodes['lift']['config']['mapping']);
		$this->assertSame('continue', $nodes['lift']['onError']);

		foreach (array_keys(self::BRANCHES) as $branch) {
			$map = $nodes['map-' . $branch];
			$this->assertSame('publiccode-developer-overheid-repository', $map['config']['mapping']);
			$this->assertSame('continue', $map['onError']);

			$write = $nodes['write-' . $branch];
			$this->assertSame('openregister.object-write', $write['type']);
			$this->assertSame(
				['operation' => 'upsert', 'register' => 'publication', 'schema' => 'publiccode', 'payloadFrom' => 'component', 'match' => ['@self.slug' => '{{ component.slug }}'], 'maxWrites' => 5000],
				$write['config']
			);
			$this->assertSame('continue', $write['onError'], 'A refused write ends its own item, not the run.');
		}

		foreach ($nodes as $id => $node) {
			if ($node['type'] === 'openregister.map') {
				$this->assertArrayHasKey($node['config']['mapping'], $mappings, $id . ' names a mapping the fragment does not ship.');
			}
		}

		$this->assertSame(['publiccode-developer-overheid-locate', 'publiccode-developer-overheid-detail', 'publiccode-developer-overheid-repository'], array_keys($mappings));
	}//end testEachBranchFetchesFromItsOwnSourceAndCarriesFailuresPast()

	/**
	 * The list step names the synchronization the file defines, and set-up
	 * turns that entry into a paged read of the register's repositories.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 *
	 * @return void
	 */
	public function testTheListStepAndTheSynchronizationFileAgree(): void {
		$list = $this->nodes()['list'];
		$this->assertCount(1, $this->definitions['synchronizations']);
		$entry = $this->definitions['synchronizations'][0];

		$this->assertSame('list', $entry['node']);
		$this->assertSame(['synchronization' => $entry['synchronization'], 'output' => 'page'], $list['config']);
		$this->assertSame('opencatalogi-publiccode-developer-overheid', $entry['synchronization']);
		$this->assertSame('developer-overheid-oss', $this->definitions['source']);
		$this->assertSame(['github-raw', 'gitlab-raw'], $this->definitions['sources']);
		$this->assertArrayNotHasKey('credential', $this->definitions, 'OpenCatalogi never looks the credential up.');

		$synchronization = PubliccodeHarvestService::developerOverheidSynchronizationFor(definitions: $this->definitions, entry: $entry);
		$this->assertSame('opencatalogi-publiccode-developer-overheid', $synchronization['slug']);
		$this->assertSame('developer-overheid-oss', $synchronization['sourceId']);
		$this->assertSame('api', $synchronization['sourceType']);
		$this->assertSame(
			[
				'endpoint' => '/repositories',
				'query' => ['publiccode' => 'true', 'perPage' => 100],
				'resultsPosition' => '_root',
				'paginationQuery' => 'page',
				'maxPages' => 10,
				'prefetchConcurrency' => 1,
			],
			$synchronization['sourceConfig']
		);
	}//end testTheListStepAndTheSynchronizationFileAgree()

	/**
	 * Every repository goes down exactly one branch, and one that the locate
	 * step could not classify goes down none.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-004-a-repository-on-another-forge-falls-back-to-the-registers-parsed-publiccode
	 *
	 * @return void
	 */
	public function testEveryRepositoryTakesExactlyOneBranch(): void {
		$this->jsonLogic();
		$nodes = $this->nodes();

		foreach (['github' => 'github', 'gitlab' => 'gitlab', 'other' => 'register'] as $forge => $branch) {
			$taken = [];
			foreach (array_keys(self::BRANCHES) as $candidate) {
				if (\JWadhams\JsonLogic::apply($nodes['to-' . $candidate]['config']['condition'], ['json' => ['locate' => ['forge' => $forge]]]) === true) {
					$taken[] = $candidate;
				}
			}

			$this->assertSame([$branch], $taken, $forge);
		}

		foreach (array_keys(self::BRANCHES) as $candidate) {
			$this->assertFalse(\JWadhams\JsonLogic::apply($nodes['to-' . $candidate]['config']['condition'], ['json' => ['__error' => []]]), 'A failed locate is dropped by ' . $candidate);
		}
	}//end testEveryRepositoryTakesExactlyOneBranch()

	/**
	 * The three decoded filters are one rule: they keep originals in every
	 * spelling and other hosts' urls, and drop a fork that names the original,
	 * a 404, a broken file and non-text.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-006-a-file-that-cannot-be-read-is-skipped-not-written
	 *
	 * @return void
	 */
	public function testTheDecodedFilterKeepsOriginalsAndDropsForksFailuresAndNonText(): void {
		$this->jsonLogic();
		$nodes = $this->nodes();
		$rule = $nodes['decoded-github']['config']['condition'];
		$this->assertSame($rule, $nodes['decoded-gitlab']['config']['condition']);
		$this->assertSame($rule, $nodes['decoded-register']['config']['condition']);

		$item = static fn (mixed $url, mixed $name = 'OpenCatalogi', string $repoKey = 'github.com/conductionnl/opencatalogi', string $host = 'github.com'): array => [
			'json' => [
				'repo' => ['id' => '00000000-0000-0000-0000-000000000001'],
				'locate' => ['forge' => 'github', 'host' => $host, 'repoKey' => $repoKey],
				'publiccode' => ['status' => 200, 'body' => ['name' => $name, 'url' => $url]],
			],
		];

		$kept = [
			'canonical' => $item('https://github.com/ConductionNL/opencatalogi'),
			'original with .git' => $item('https://github.com/ConductionNL/opencatalogi.git'),
			'trailing slash' => $item('https://github.com/ConductionNL/opencatalogi/'),
			'fragment' => $item('https://github.com/ConductionNL/opencatalogi#readme'),
			'query' => $item('https://github.com/ConductionNL/opencatalogi?ref=x'),
			'www and http' => $item('http://www.github.com/ConductionNL/OpenCatalogi'),
			'monorepo sub-path' => $item('https://github.com/ConductionNL/opencatalogi/tree/main/apps/search-ui'),
			'file names another host' => $item('https://codeberg.org/example-org/opencatalogi'),
			'gitlab original' => $item('https://gitlab.com/example-group/sub/project', 'Project', 'gitlab.com/example-group/sub/project', 'gitlab.com'),
			'register fallback on codeberg' => $item('https://codeberg.org/example-org/app', 'Example App', 'codeberg.org/example-org/app', 'codeberg.org'),
		];
		foreach ($kept as $case => $data) {
			$this->assertTrue(\JWadhams\JsonLogic::apply($rule, $data), 'kept: ' . $case);
		}

		$notFound = $item('https://github.com/ConductionNL/opencatalogi');
		unset($notFound['json']['publiccode']);
		$notFound['json']['__error'] = ['status' => 404, 'kind' => 'call'];
		$notYaml = $item('https://github.com/ConductionNL/opencatalogi');
		unset($notYaml['json']['publiccode']);
		$notYaml['json']['__error'] = ['kind' => 'decode'];
		$scalar = $item('https://github.com/ConductionNL/opencatalogi');
		$scalar['json']['publiccode']['body'] = 'name: OpenCatalogi';

		$dropped = [
			'fork naming the original' => $item('https://github.com/ConductionNL/opencatalogi', 'OpenCatalogi', 'github.com/example-fork/opencatalogi'),
			'another repository with the same prefix' => $item('https://github.com/ConductionNL/opencatalogi-extra'),
			'a 404' => $notFound,
			'not YAML' => $notYaml,
			'a YAML scalar' => $scalar,
			'no url' => $item(null),
			'no name' => $item('https://github.com/ConductionNL/opencatalogi', null),
			'url is a list' => $item(['https://github.com/ConductionNL/opencatalogi']),
			'url is not a web address' => $item('ftp://github.com/ConductionNL/opencatalogi'),
			'url has a space' => $item('https://github.com/ConductionNL/open catalogi'),
			'url is only a scheme' => $item('https://'),
			'name is a list' => $item('https://github.com/ConductionNL/opencatalogi', ['OpenCatalogi']),
		];
		foreach ($dropped as $case => $data) {
			$this->assertFalse(\JWadhams\JsonLogic::apply($rule, $data), 'dropped: ' . $case);
		}
	}//end testTheDecodedFilterKeepsOriginalsAndDropsForksFailuresAndNonText()
}//end class
