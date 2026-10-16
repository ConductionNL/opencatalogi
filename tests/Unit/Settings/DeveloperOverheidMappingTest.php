<?php

/**
 * Runs the developer.overheid.nl harvest's three shipped mappings through the
 * real mapping engine and validates the result against the real schema.
 *
 * The locate mapping is run over every publicCodeUrl shape of design D2. The
 * repository mapping is run over a register RepositorySummary plus this
 * repository's own publiccode.yml, and compared with what mapping
 * `publiccode-github-hit` makes of the same file: both harvests must land on one
 * slug, or a repository both find becomes two components. The fallback is run
 * over a register RepositoryDetail and must not write `rawPubliccode`.
 *
 * OpenRegister is a sibling app, not a composer dependency. Where its source is
 * not next to this app (a bare CI container), the tests skip and say so.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-005-one-object-per-repository-shared-with-the-github-harvest
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICacheFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @coversNothing
 */
class DeveloperOverheidMappingTest extends TestCase {

	/**
	 * The real mapping engine.
	 *
	 * @var MappingService
	 */
	private MappingService $mappingService;

	/**
	 * Build the engine, or skip when OpenRegister is absent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(MappingService::class) === false || class_exists(Mapping::class) === false) {
			$this->markTestSkipped('OpenRegister source is not next to this app, so its MappingService cannot run here.');
		}

		if (class_exists(Yaml::class) === false) {
			$this->markTestSkipped('symfony/yaml is not installed.');
		}

		$this->mappingService = new MappingService(
			$this->createMock(MappingMapper::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Read a JSON file from the app root.
	 *
	 * @param string $path Path relative to the app root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		return (array)json_decode(
			(string)file_get_contents(__DIR__ . '/../../..' . $path),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}//end json()

	/**
	 * A shipped mapping as the entity OpenRegister would load.
	 *
	 * @param string $fragment The register fragment, relative to lib/Settings/register.d.
	 * @param string $slug The mapping slug.
	 *
	 * @return Mapping
	 */
	private function mapping(string $fragment, string $slug): Mapping {
		$definition = $this->json(path: '/lib/Settings/register.d/' . $fragment)['components']['mappings'][$slug];

		$mapping = new Mapping();
		$mapping->setName($definition['name']);
		$mapping->setSlug($definition['slug']);
		$mapping->setMapping($definition['mapping']);
		$mapping->setCast($definition['cast']);
		$mapping->setUnset($definition['unset']);
		$mapping->setPassThrough($definition['passThrough']);

		return $mapping;
	}//end mapping()

	/**
	 * Run one of this harvest's mappings.
	 *
	 * @param string $slug The mapping slug.
	 * @param array<string, mixed> $item The flow item's record.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(string $slug, array $item): array {
		return $this->mappingService->executeMapping(
			mapping: $this->mapping(fragment: 'publiccode-developer-overheid-harvest.json', slug: $slug),
			input: $item
		);
	}//end apply()

	/**
	 * The register's RepositorySummary fixture, without its comment.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(): array {
		$summary = $this->json(path: '/tests/fixtures/developer-overheid/repository-summary.json');
		unset($summary['$comment']);

		return $summary;
	}//end summary()

	/**
	 * The register's RepositoryDetail fixture, without its comment.
	 *
	 * @return array<string, mixed>
	 */
	private function detail(): array {
		$detail = $this->json(path: '/tests/fixtures/developer-overheid/repository-detail.json');
		unset($detail['$comment']);

		return $detail;
	}//end detail()

	/**
	 * This repository's own publiccode.yml.
	 *
	 * @return string
	 */
	private function ownPubliccode(): string {
		return (string)file_get_contents(__DIR__ . '/../../../publiccode.yml');
	}//end ownPubliccode()

	/**
	 * What the locate step makes of one register repository.
	 *
	 * @param array<string, mixed> $repo The repository.
	 *
	 * @return array<string, mixed>
	 */
	private function locate(array $repo): array {
		$result = $this->apply(slug: 'publiccode-developer-overheid-locate', item: ['repo' => $repo]);
		$this->assertSame($repo, $result['repo'], 'The locate step passes the repository through.');
		$this->assertIsArray($result['locate']);

		return $result['locate'];
	}//end locate()

	/**
	 * The record a forge branch hands the repository mapping.
	 *
	 * @param array<string, mixed> $repo The repository.
	 * @param string $yaml The fetched publiccode.yml.
	 *
	 * @return array<string, mixed>
	 */
	private function forgeItem(array $repo, string $yaml): array {
		return [
			'repo' => $repo,
			'locate' => $this->locate(repo: $repo),
			'publiccode' => ['status' => 200, 'body' => Yaml::parse($yaml)],
		];
	}//end forgeItem()

	/**
	 * Map a record with the repository mapping and return the component.
	 *
	 * @param array<string, mixed> $item The record.
	 *
	 * @return array<string, mixed>
	 */
	private function component(array $item): array {
		$result = $this->apply(slug: 'publiccode-developer-overheid-repository', item: $item);
		$this->assertArrayHasKey('component', $result);

		return $result['component'];
	}//end component()

	/**
	 * What the GitHub harvest's mapping makes of the same file.
	 *
	 * @param string $yaml The publiccode.yml text.
	 * @param string $repository The GitHub repository, owner/name.
	 * @param string $path The file's path in the repository.
	 *
	 * @return array<string, mixed>
	 */
	private function githubComponent(string $yaml, string $repository = 'ConductionNL/opencatalogi', string $path = 'publiccode.yml'): array {
		$result = $this->mappingService->executeMapping(
			mapping: $this->mapping(fragment: 'publiccode-github-harvest.json', slug: 'publiccode-github-hit'),
			input: [
				'hit' => ['name' => 'publiccode.yml', 'path' => $path, 'repository' => ['full_name' => $repository]],
				'publiccode' => ['status' => 200, 'body' => Yaml::parse($yaml)],
			]
		);

		return $result['component'];
	}//end githubComponent()

	/**
	 * The publiccode schema as plain JSON Schema, from the real fragment.
	 *
	 * @return object
	 */
	private function schema(): object {
		$publiccode = $this->json(path: '/lib/Settings/register.d/publiccode-software.json')['components']['schemas']['publiccode'];

		return (object)json_decode(
			(string)json_encode(
				[
					'type' => 'object',
					'required' => $publiccode['required'],
					'properties' => $publiccode['properties'],
				]
			)
		);
	}//end schema()

	/**
	 * Assert a component validates against the schema.
	 *
	 * @param array<string, mixed> $component The component.
	 *
	 * @return void
	 */
	private function assertValid(array $component): void {
		$result = (new Validator())->validate(json_decode((string)json_encode($component)), $this->schema());
		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->args()));
	}//end assertValid()

	/**
	 * Every publicCodeUrl shape of design D2 is classified and rewritten as the
	 * table says, never into an absolute URL.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-003-each-repository-is-fetched-from-its-own-forge
	 *
	 * @return void
	 */
	public function testEveryPublicCodeUrlShapeIsLocatedAsDesigned(): void {
		$cases = [
			'github blob' => ['https://github.com/ConductionNL/opencatalogi/blob/main/publiccode.yml', 'github', 'github.com', '/ConductionNL/opencatalogi/main/publiccode.yml', 'publiccode.yml'],
			'github raw' => ['https://github.com/ConductionNL/opencatalogi/raw/main/publiccode.yml', 'github', 'github.com', '/ConductionNL/opencatalogi/main/publiccode.yml', 'publiccode.yml'],
			'www.github.com' => ['https://www.github.com/ConductionNL/opencatalogi/blob/main/publiccode.yml', 'github', 'github.com', '/ConductionNL/opencatalogi/main/publiccode.yml', 'publiccode.yml'],
			'github monorepo' => ['https://github.com/example-org/monorepo/blob/main/apps/portal/publiccode.yml', 'github', 'github.com', '/example-org/monorepo/main/apps/portal/publiccode.yml', 'apps/portal/publiccode.yml'],
			'raw.githubusercontent.com refs/heads' => ['https://raw.githubusercontent.com/example-org/app/refs/heads/main/publiccode.yaml', 'github', 'raw.githubusercontent.com', '/example-org/app/refs/heads/main/publiccode.yaml', 'publiccode.yaml'],
			'raw.githubusercontent.com plain ref' => ['https://raw.githubusercontent.com/example-org/app/main/publiccode.yml', 'github', 'raw.githubusercontent.com', '/example-org/app/main/publiccode.yml', 'publiccode.yml'],
			'gitlab blob with a query' => ['https://gitlab.com/example-group/sub/project/-/blob/main/publiccode.yml?ref_type=heads', 'gitlab', 'gitlab.com', '/example-group/sub/project/-/raw/main/publiccode.yml', 'publiccode.yml'],
			'gitlab raw with a fragment' => ['https://gitlab.com/example-group/project/-/raw/main/publiccode.yml#L1', 'gitlab', 'gitlab.com', '/example-group/project/-/raw/main/publiccode.yml', 'publiccode.yml'],
			'codeberg' => ['https://codeberg.org/example-org/app/src/branch/main/publiccode.yml', 'other', 'codeberg.org', null, null],
			'github without a file path' => ['https://github.com/example-org/app', 'other', 'github.com', null, null],
			'gitlab without a project' => ['https://gitlab.com/example-group/-/blob/main/publiccode.yml', 'other', 'gitlab.com', null, null],
			'path traversal' => ['https://github.com/example-org/app/blob/main/../../evil/publiccode.yml', 'other', 'github.com', null, null],
			'not a web address' => ['ftp://github.com/example-org/app/blob/main/publiccode.yml', 'other', 'github.com', null, null],
		];

		foreach ($cases as $case => [$url, $forge, $host, $rawPath, $filePath]) {
			$locate = $this->locate(repo: ['id' => 'x', 'url' => 'https://github.com/ConductionNL/opencatalogi', 'publicCodeUrl' => $url]);
			$this->assertSame($forge, $locate['forge'], $case . ': forge');
			$this->assertSame($host, $locate['host'], $case . ': host');
			$this->assertSame($rawPath, $locate['rawPath'], $case . ': rawPath');
			$this->assertSame($filePath, $locate['filePath'], $case . ': filePath');
			if ($rawPath !== null) {
				$this->assertStringStartsWith('/', $rawPath, $case . ': a path on the source, never an absolute URL');
				$this->assertStringStartsNotWith('//', $rawPath, $case);
			}
		}
	}//end testEveryPublicCodeUrlShapeIsLocatedAsDesigned()

	/**
	 * The register's own url becomes the repository key, normalised as the slug
	 * is; an empty publicCodeUrl falls back to that url's host.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-004-a-repository-on-another-forge-falls-back-to-the-registers-parsed-publiccode
	 *
	 * @return void
	 */
	public function testTheRepositoryKeyIsTheNormalisedRegisterUrl(): void {
		$this->assertSame('github.com/conductionnl/opencatalogi', $this->locate(repo: $this->summary())['repoKey']);

		$locate = $this->locate(repo: ['id' => 'x', 'url' => 'http://www.GitLab.com/Example-Group/Project.git/', 'publicCodeUrl' => '']);
		$this->assertSame('gitlab.com/example-group/project', $locate['repoKey']);
		$this->assertSame('other', $locate['forge']);
		$this->assertSame('gitlab.com', $locate['host']);

		$locate = $this->locate(repo: ['id' => 'x', 'url' => 'https://codeberg.org/example-org/app']);
		$this->assertSame('other', $locate['forge'], 'A missing publicCodeUrl takes the fallback.');
		$this->assertSame('codeberg.org', $locate['host']);
	}//end testTheRepositoryKeyIsTheNormalisedRegisterUrl()

	/**
	 * This repository's own file, listed in the register, maps onto the schema
	 * under the slug the GitHub harvest gives it, with the register listing.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-005-one-object-per-repository-shared-with-the-github-harvest
	 *
	 * @return void
	 */
	public function testARepositoryBothHarvestsFindIsOneComponent(): void {
		$summary = $this->summary();
		$component = $this->component(item: $this->forgeItem(repo: $summary, yaml: $this->ownPubliccode()));
		$github = $this->githubComponent(yaml: $this->ownPubliccode());

		$this->assertValid(component: $component);
		$this->assertSame('github-com-conductionnl-opencatalogi', $component['slug']);
		$this->assertSame($github['slug'], $component['slug']);
		$this->assertSame('github.com', $component['harvestedFrom']);
		$this->assertNotFalse(strtotime($component['harvestedAt']));
		$this->assertSame(Yaml::parse($this->ownPubliccode()), $component['rawPubliccode']);

		// Every publiccode field is shaped exactly as the GitHub harvest shapes it.
		foreach ($github as $field => $value) {
			if (in_array($field, ['harvestedAt'], true) === true) {
				continue;
			}

			$this->assertSame($value, $component[$field], $field . ' differs between the two harvests.');
		}

		$this->assertSame(
			[
				'id' => $summary['id'],
				'organisation' => ['label' => 'Example: Gemeente Voorbeeldstad', 'uri' => 'https://identifier.example.invalid/organisation/1'],
				'registerUrl' => 'https://oss.developer.overheid.nl/repositories/' . $summary['id'],
				'lastCrawledAt' => '2026-10-15T02:00:00Z',
				'archived' => false,
			],
			$component['developerOverheid']
		);
		$this->assertArrayNotHasKey('forkType', $component['developerOverheid'], 'An original has no fork type.');
	}//end testARepositoryBothHarvestsFindIsOneComponent()

	/**
	 * A file below the repository root adds its directory to the slug, as the
	 * GitHub harvest does.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-005-one-object-per-repository-shared-with-the-github-harvest
	 *
	 * @return void
	 */
	public function testAFileBelowTheRootAddsItsDirectoryToTheSlugAsTheGithubHarvestDoes(): void {
		$repo = $this->summary();
		$repo['publicCodeUrl'] = 'https://github.com/ConductionNL/opencatalogi/blob/main/apps/Search_UI/publiccode.yml';

		$component = $this->component(item: $this->forgeItem(repo: $repo, yaml: $this->ownPubliccode()));

		$this->assertSame('github-com-conductionnl-opencatalogi-apps-search-ui', $component['slug']);
		$this->assertSame($this->githubComponent(yaml: $this->ownPubliccode(), path: 'apps/Search_UI/publiccode.yml')['slug'], $component['slug']);
	}//end testAFileBelowTheRootAddsItsDirectoryToTheSlugAsTheGithubHarvestDoes()

	/**
	 * The url's spelling (.git, www., scheme, fragment, query, slash) does not
	 * change the identity, in either harvest.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-005-one-object-per-repository-shared-with-the-github-harvest
	 *
	 * @return void
	 */
	public function testTheSlugRuleMatchesTheGithubHarvestForEverySpelling(): void {
		foreach (['http://www.github.com/ConductionNL/opencatalogi.git/', 'https://github.com/ConductionNL/opencatalogi#readme', 'https://github.com/ConductionNL/opencatalogi?ref=main', 'https://github.com/ConductionNL/mywww.site'] as $url) {
			$yaml = str_replace('url: "https://github.com/ConductionNL/opencatalogi"', 'url: "' . $url . '"', $this->ownPubliccode());
			$this->assertSame(
				$this->githubComponent(yaml: $yaml)['slug'],
				$this->component(item: $this->forgeItem(repo: $this->summary(), yaml: $yaml))['slug'],
				$url
			);
		}
	}//end testTheSlugRuleMatchesTheGithubHarvestForEverySpelling()

	/**
	 * A gitlab.com file is written with its host and its raw file.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-003-each-repository-is-fetched-from-its-own-forge
	 *
	 * @return void
	 */
	public function testAGitlabFileIsWrittenWithItsHostAndRawFile(): void {
		$repo = $this->summary();
		$repo['url'] = 'https://gitlab.com/example-group/sub/project';
		$repo['publicCodeUrl'] = 'https://gitlab.com/example-group/sub/project/-/blob/main/publiccode.yml?ref_type=heads';
		$yaml = str_replace('url: "https://github.com/ConductionNL/opencatalogi"', 'url: "https://gitlab.com/example-group/sub/project"', $this->ownPubliccode());

		$component = $this->component(item: $this->forgeItem(repo: $repo, yaml: $yaml));

		$this->assertValid(component: $component);
		$this->assertSame('gitlab-com-example-group-sub-project', $component['slug']);
		$this->assertSame('gitlab.com', $component['harvestedFrom']);
		$this->assertSame('https://gitlab.com/example-group/sub/project', $component['rawPubliccode']['url']);
	}//end testAGitlabFileIsWrittenWithItsHostAndRawFile()

	/**
	 * The raw file arrives byte for byte, HTML entities and all: the template
	 * engine and the cast each decode entities once.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-003-each-repository-is-fetched-from-its-own-forge
	 *
	 * @return void
	 */
	public function testTheRawFileSurvivesTheMappingUnchanged(): void {
		$body = Yaml::parse($this->ownPubliccode());
		$body['description']['en']['longDescription'] = 'Tom &amp; Jerry say &quot;hi&quot; <b>loudly</b> & \'often\' – €5 \\o/';
		$body['nl'] = ['countryExtensionVersion' => '1.0', 'empty' => [], 'number' => 3, 'flag' => false];

		$component = $this->component(item: $this->forgeItem(repo: $this->summary(), yaml: Yaml::dump($body, 6)));

		$this->assertSame($body, $component['rawPubliccode']);
	}//end testTheRawFileSurvivesTheMappingUnchanged()

	/**
	 * The fallback lifts the register's parse, writes what it has, and writes no
	 * rawPubliccode, so it never patches a full file away.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-004-a-repository-on-another-forge-falls-back-to-the-registers-parsed-publiccode
	 *
	 * @return void
	 */
	public function testTheFallbackWritesTheRegistersParseWithoutRawPubliccode(): void {
		$detail = $this->detail();
		$repo = $detail;
		unset($repo['publicCode']);

		$item = ['repo' => $repo, 'locate' => $this->locate(repo: $repo)];
		$this->assertSame('other', $item['locate']['forge']);
		$item['detail'] = ['status' => 200, 'body' => $detail];
		$lifted = $this->apply(slug: 'publiccode-developer-overheid-detail', item: $item);
		$this->assertSame($detail['publicCode'], $lifted['publiccode']['body']);
		$this->assertSame($item['repo'], $lifted['repo'], 'The lift passes the record through.');

		$component = $this->component(item: $lifted);

		$this->assertValid(component: $component);
		$this->assertArrayNotHasKey('rawPubliccode', $component);
		$this->assertSame('codeberg-org-example-org-app', $component['slug']);
		$this->assertSame('codeberg.org', $component['harvestedFrom']);
		$this->assertSame('Example App', $component['name']);
		$this->assertSame('EUPL-1.2', $component['legalLicense']);
		$this->assertSame('VARIANT_FORK', $component['developerOverheid']['forkType']);
		$this->assertSame('2026-10-14T02:00:00.123Z', $component['developerOverheid']['lastCrawledAt']);
		$this->assertSame('https://oss.developer.overheid.nl/repositories/00000000-0000-0000-0000-000000000000', $component['developerOverheid']['registerUrl']);

		// What the register's parse lacks stays absent, so the patch keeps what
		// the GitHub harvest wrote there.
		foreach (['categories', 'softwareVersion', 'releaseDate', 'logo', 'applicationSuite', 'itConformsTo', 'legalRepoOwner'] as $absent) {
			$this->assertArrayNotHasKey($absent, $component, $absent);
		}
	}//end testTheFallbackWritesTheRegistersParseWithoutRawPubliccode()

	/**
	 * A failed detail call leaves nothing to lift, not the text of the path.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-006-a-file-that-cannot-be-read-is-skipped-not-written
	 *
	 * @return void
	 */
	public function testAFailedDetailCallLiftsNothing(): void {
		$repo = $this->detail();
		unset($repo['publicCode']);

		$lifted = $this->apply(
			slug: 'publiccode-developer-overheid-detail',
			item: ['repo' => $repo, '__error' => ['status' => 404]]
		);

		$this->assertFalse(isset($lifted['publiccode']['body']), 'An unresolved path must not be written as text.');
		$this->assertSame(['status' => 404], $lifted['__error']);
	}//end testAFailedDetailCallLiftsNothing()

	/**
	 * A register value the column would refuse is dropped before the write;
	 * a listing with nothing usable leaves no empty block behind.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-007-the-publiccode-schema-records-the-register-listing-additively
	 *
	 * @return void
	 */
	public function testARegisterValueTheColumnWouldRefuseIsDropped(): void {
		$repo = $this->summary();
		$repo['id'] = 'not-a-uuid';
		$repo['organisation'] = ['label' => ['a list'], 'uri' => 'not a uri'];
		$repo['lastCrawledAt'] = 'yesterday';
		$repo['forkType'] = 'SPOON_FORK';
		$repo['archived'] = 'yes';

		$component = $this->component(item: $this->forgeItem(repo: $repo, yaml: $this->ownPubliccode()));

		$this->assertArrayNotHasKey('developerOverheid', $component);
		$this->assertSame('OpenCatalogi', $component['name'], 'The rest still maps.');
		$this->assertValid(component: $component);

		$repo = $this->summary();
		$repo['archived'] = true;
		$repo['organisation'] = 'Gemeente';
		$listing = $this->component(item: $this->forgeItem(repo: $repo, yaml: $this->ownPubliccode()))['developerOverheid'];
		$this->assertTrue($listing['archived']);
		$this->assertArrayNotHasKey('organisation', $listing);
	}//end testARegisterValueTheColumnWouldRefuseIsDropped()

	/**
	 * Schema 0.4.0 adds one optional object and changes nothing else, so an
	 * object written before it (the GitHub harvest's) stays valid.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-007-the-publiccode-schema-records-the-register-listing-additively
	 *
	 * @return void
	 */
	public function testSchemaZeroFourZeroIsAdditive(): void {
		$schema = $this->json(path: '/lib/Settings/register.d/publiccode-software.json')['components']['schemas']['publiccode'];

		$this->assertSame('0.4.0', $schema['version']);
		$this->assertSame(['name', 'url'], $schema['required']);
		$this->assertSame('object', $schema['properties']['developerOverheid']['type']);
		$this->assertSame(
			['id', 'organisation', 'registerUrl', 'lastCrawledAt', 'forkType', 'archived'],
			array_keys($schema['properties']['developerOverheid']['properties'])
		);
		$this->assertArrayNotHasKey('required', $schema['properties']['developerOverheid']);

		// No separate flag: present means listed (REQ-PDH-007).
		foreach (array_keys($schema['properties']) as $property) {
			$this->assertStringNotContainsStringIgnoringCase('register', $property === 'developerOverheid' ? '' : $property, 'Only developerOverheid records the listing.');
		}

		$before = $this->githubComponent(yaml: $this->ownPubliccode());
		$this->assertArrayNotHasKey('developerOverheid', $before);
		$this->assertValid(component: $before);
	}//end testSchemaZeroFourZeroIsAdditive()

	/**
	 * The demo data shows a listed and an unlisted component, each valid against
	 * 0.4.0, under the slugs it always had, so a re-import updates them.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-007-the-publiccode-schema-records-the-register-listing-additively
	 *
	 * @return void
	 */
	public function testTheDemoDataShowsAListedAndAnUnlistedComponent(): void {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/opencatalogi_mock_register.json'), false, 512, JSON_THROW_ON_ERROR);
		$this->assertTrue(version_compare($mock->info->version, '1.0.3', '>='), 'A demo-data change raises the register version.');

		$listed = [];
		foreach ($mock->components->objects as $object) {
			if ($object->{'@self'}->schema !== 'publiccode') {
				continue;
			}

			$data = clone $object;
			unset($data->{'@self'});
			$result = (new Validator())->validate($data, $this->schema());
			$this->assertTrue($result->isValid(), $object->{'@self'}->slug . ': ' . json_encode($result->error()?->args()));
			$listed[$object->{'@self'}->slug] = isset($object->developerOverheid);
		}

		$this->assertSame(
			['publiccode-voorbeeld-name-1-1' => true, 'publiccode-voorbeeld-name-2-2' => true, 'publiccode-voorbeeld-name-3-3' => false],
			$listed
		);
	}//end testTheDemoDataShowsAListedAndAnUnlistedComponent()
}//end class
