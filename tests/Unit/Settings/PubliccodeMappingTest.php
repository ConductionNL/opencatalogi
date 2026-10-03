<?php

/**
 * Runs a real publiccode.yml through the shipped mapping and the real schema.
 *
 * The input is this repository's own publiccode.yml, decoded as YAML the way
 * the flow's fetch step decodes it. It goes through OpenRegister's real
 * MappingService with the shipped `publiccode-github-hit` mapping, and the
 * result is validated with opis/json-schema against the real `publiccode`
 * schema fragment. A mapping that writes what its own schema refuses passes
 * every structural test and fails on the first live harvest, so this checks
 * the payload itself.
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
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-003-each-harvested-file-becomes-one-publiccode-object-and-a-re-harvest-updates-it
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
class PubliccodeMappingTest extends TestCase {

	/**
	 * The real mapping engine.
	 *
	 * @var MappingService
	 */
	private MappingService $mappingService;

	/**
	 * The shipped mapping.
	 *
	 * @var Mapping
	 */
	private Mapping $mapping;

	/**
	 * Build the engine and load the mapping, or skip when OpenRegister is absent.
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

		$fragment = $this->json(path: '/lib/Settings/register.d/publiccode-github-harvest.json');
		$definition = $fragment['components']['mappings']['publiccode-github-hit'];

		$this->mapping = new Mapping();
		$this->mapping->setName($definition['name']);
		$this->mapping->setSlug($definition['slug']);
		$this->mapping->setMapping($definition['mapping']);
		$this->mapping->setCast($definition['cast']);
		$this->mapping->setUnset($definition['unset']);
		$this->mapping->setPassThrough($definition['passThrough']);

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
	 * The flow item the fetch step hands the mapping for one hit.
	 *
	 * @param string $yaml The publiccode.yml text.
	 * @param string $path The file's path in the repository.
	 *
	 * @return array<string, mixed>
	 */
	private function item(string $yaml, string $path = 'publiccode.yml'): array {
		return [
			'hit' => [
				'name' => 'publiccode.yml',
				'path' => $path,
				'repository' => ['full_name' => 'ConductionNL/opencatalogi'],
			],
			'publiccode' => ['status' => 200, 'body' => Yaml::parse($yaml)],
		];
	}//end item()

	/**
	 * This repository's own publiccode.yml.
	 *
	 * @return string
	 */
	private function ownPubliccode(): string {
		return (string)file_get_contents(__DIR__ . '/../../../publiccode.yml');
	}//end ownPubliccode()

	/**
	 * Map one item and return the component.
	 *
	 * @param array<string, mixed> $item The flow item.
	 *
	 * @return array<string, mixed>
	 */
	private function map(array $item): array {
		$result = $this->mappingService->executeMapping(mapping: $this->mapping, input: $item);
		$this->assertArrayHasKey('component', $result);

		return $result['component'];
	}//end map()

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
	 * A real file maps to an object the schema accepts.
	 *
	 * @return void
	 */
	public function testARealPubliccodeYmlMapsOntoTheSchema(): void {
		$component = $this->map(item: $this->item(yaml: $this->ownPubliccode()));

		$result = (new Validator())->validate(json_decode((string)json_encode($component)), $this->schema());
		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->args()));

		$this->assertSame('github-com-conductionnl-opencatalogi', $component['slug']);
		$this->assertSame('OpenCatalogi', $component['name']);
		$this->assertSame('https://github.com/ConductionNL/opencatalogi', $component['url']);
		$this->assertSame('EUPL-1.2', $component['legalLicense']);
		$this->assertSame('2026-05-26', $component['releaseDate']);
		$this->assertSame(['nextcloud'], $component['platforms']);
		$this->assertSame(['nl', 'en'], $component['localisationAvailableLanguages']);
		$this->assertSame('internal', $component['maintenanceType']);
		$this->assertSame('Conduction Development Team', $component['maintainers'][0]['name']);
		$this->assertSame('Framework for federated catalogs in Nextcloud', $component['description']['en']['shortDescription']);
		$this->assertSame('github.com', $component['harvestedFrom']);
		$this->assertSame('OpenCatalogi', $component['rawPubliccode']['name']);
		$this->assertNotFalse(strtotime($component['harvestedAt']));
	}//end testARealPubliccodeYmlMapsOntoTheSchema()

	/**
	 * A field the file does not declare is absent, never the text of its own path.
	 *
	 * @return void
	 */
	public function testAnUndeclaredFieldIsAbsentNotItsPath(): void {
		$component = $this->map(item: $this->item(yaml: $this->ownPubliccode()));

		foreach (['applicationSuite', 'landingURL', 'logo', 'softwareType', 'legalRepoOwner', 'itConformsTo'] as $absent) {
			$this->assertArrayNotHasKey($absent, $component, $absent . ' is not in the file, so it must not be in the object.');
		}

		foreach ($component as $field => $value) {
			if (is_string($value) === true) {
				$this->assertStringNotContainsString('publiccode.body', $value, $field . ' holds a path instead of a value.');
			}
		}
	}//end testAnUndeclaredFieldIsAbsentNotItsPath()

	/**
	 * An unquoted date, which YAML decodes to a timestamp, still lands as a date.
	 *
	 * @return void
	 */
	public function testAnUnquotedReleaseDateStillLandsAsADate(): void {
		$yaml = str_replace('releaseDate: "2026-05-26"', 'releaseDate: 2026-05-26', $this->ownPubliccode());
		$this->assertIsInt(Yaml::parse($yaml)['releaseDate'], 'The fixture must exercise the timestamp path.');

		$this->assertSame('2026-05-26', $this->map(item: $this->item(yaml: $yaml))['releaseDate']);
	}//end testAnUnquotedReleaseDateStillLandsAsADate()

	/**
	 * Two files in one repository get two slugs.
	 *
	 * @return void
	 */
	public function testAFileBelowTheRootAddsItsDirectoryToTheSlug(): void {
		$component = $this->map(item: $this->item(yaml: $this->ownPubliccode(), path: 'apps/Search_UI/publiccode.yml'));

		$this->assertSame('github-com-conductionnl-opencatalogi-apps-search-ui', $component['slug']);
	}//end testAFileBelowTheRootAddsItsDirectoryToTheSlug()

	/**
	 * The identity is the url the file declares, not the repository the file was
	 * found in. A fork or a copy of the same publiccode.yml therefore maps onto the
	 * SAME slug as the original, and the upsert keeps one component.
	 *
	 * Measured on the first real crawl (WOO-585): 253 of the first 1,000 objects
	 * were copies of another repository's file, nine of them for one project.
	 *
	 * @return void
	 */
	public function testACopyInAnotherRepositoryMapsOntoTheOriginalSlug(): void {
		$item = $this->item(yaml: $this->ownPubliccode());
		$item['hit']['repository']['full_name'] = 'someone-else/hack-day-fork';
		$component = $this->map(item: $item);
		$this->assertSame('github-com-conductionnl-opencatalogi', $component['slug']);
		$this->assertSame('https://github.com/ConductionNL/opencatalogi', $component['url']);
	}//end testACopyInAnotherRepositoryMapsOntoTheOriginalSlug()

	/**
	 * `.git`, `www.`, the scheme and a trailing slash do not change the identity.
	 *
	 * @return void
	 */
	public function testTheUrlIsNormalisedBeforeItBecomesTheSlug(): void {
		$yaml = str_replace(
			'url: "https://github.com/ConductionNL/opencatalogi"',
			'url: "http://www.github.com/ConductionNL/opencatalogi.git/"',
			$this->ownPubliccode()
		);
		$this->assertStringContainsString('opencatalogi.git/', $yaml, 'The fixture must exercise the normalisation.');
		$this->assertSame('github-com-conductionnl-opencatalogi', $this->map(item: $this->item(yaml: $yaml))['slug']);
	}//end testTheUrlIsNormalisedBeforeItBecomesTheSlug()

	/**
	 * A releaseDate that is not a date is dropped. It used to throw inside the
	 * mapping and fail the whole run: one template repository on GitHub ships
	 * `releaseDate: ${RELEASE_DATE}`.
	 *
	 * @return void
	 */
	public function testAReleaseDateThatIsNotADateIsDroppedNotFatal(): void {
		$yaml = str_replace('releaseDate: "2026-05-26"', 'releaseDate: "${RELEASE_DATE}"', $this->ownPubliccode());
		$component = $this->map(item: $this->item(yaml: $yaml));
		$this->assertArrayNotHasKey('releaseDate', $component);
		$this->assertSame('OpenCatalogi', $component['name'], 'The rest of the file still maps.');
	}//end testAReleaseDateThatIsNotADateIsDroppedNotFatal()

	/**
	 * A date with a time part is cut to the date.
	 *
	 * @return void
	 */
	public function testAReleaseDateWithATimeIsCutToTheDate(): void {
		$yaml = str_replace('releaseDate: "2026-05-26"', 'releaseDate: "2026-05-26T10:00:00Z"', $this->ownPubliccode());
		$this->assertSame('2026-05-26', $this->map(item: $this->item(yaml: $yaml))['releaseDate']);
	}//end testAReleaseDateWithATimeIsCutToTheDate()

	/**
	 * A structured field that arrives as plain text is dropped instead of being
	 * written into a json column. Code search also matches files such as
	 * `publiccodeyml__publiccode.yml.json`, whose `description` is one string;
	 * that write failed with a database error and stopped the run.
	 *
	 * @return void
	 */
	public function testAStructuredFieldThatIsPlainTextIsDropped(): void {
		$body = Yaml::parse($this->ownPubliccode());
		$body['description'] = 'A metadata standard for public software';
		$body['platforms'] = 'web';
		$body['categories'] = 7;
		$body['maintenance'] = 'none';
		$body['localisation'] = 'nl';
		$component = $this->map(item: $this->item(yaml: Yaml::dump($body, 4)));
		foreach (['description', 'platforms', 'categories', 'maintainers', 'localisationAvailableLanguages'] as $field) {
			$this->assertTrue(
				isset($component[$field]) === false || $component[$field] === [],
				$field . ' arrived as text and must not be written as structure.'
			);
		}
	}//end testAStructuredFieldThatIsPlainTextIsDropped()

	/**
	 * Italian conformance flags become the list of standards that are met.
	 *
	 * @return void
	 */
	public function testItalianConformanceBecomesAListOfStandards(): void {
		$yaml = $this->ownPubliccode() . "\nit:\n  conforme:\n    lineeGuidaDesign: true\n    modelloInteroperabilita: false\n    misureMinimeSicurezza: true\n";

		$this->assertSame(
			['lineeGuidaDesign', 'misureMinimeSicurezza'],
			$this->map(item: $this->item(yaml: $yaml))['itConformsTo']
		);
	}//end testItalianConformanceBecomesAListOfStandards()
}//end class
