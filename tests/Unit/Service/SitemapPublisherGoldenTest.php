<?php

/**
 * Golden test for the DiWoo publisher element: its label, its TOOI resource and the organisation lookups.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The publisher of a document in every organisation world matches the recording.
 *
 * @covers \OCA\OpenCatalogi\Service\SitemapService
 * @covers \OCA\OpenCatalogi\Service\SitemapDocumentMapper
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission
 * @uses \OCA\OpenCatalogi\Service\InstanceSettings
 */
class SitemapPublisherGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/sitemap-publisher-golden.json';

	/**
	 * Every organisation world, mapped twice in one request, matches the recording.
	 *
	 * @return void
	 */
	public function testThePublisherMatchesTheRecording(): void {
		$tooi = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855';
		$self = ['owner' => 'jan.jansen', 'organisation' => 'org-uuid-1'];
		$worlds = [
			'organisation with name and TOOI id' => [['@self' => $self], ['org-uuid-1' => ['name' => 'Gemeente Zuiddrecht', 'tooi' => $tooi]]],
			'organisation without name' => [['@self' => $self], ['org-uuid-1' => ['name' => null, 'tooi' => $tooi]]],
			'organisation with a blank name' => [['@self' => $self], ['org-uuid-1' => ['name' => '   ', 'tooi' => $tooi]]],
			'organisation with a padded name' => [['@self' => $self], ['org-uuid-1' => ['name' => '  Gemeente Eerste  ', 'tooi' => $tooi]]],
			'organisation without TOOI id' => [['@self' => $self], ['org-uuid-1' => ['name' => 'Gemeente Zuiddrecht', 'tooi' => null]]],
			'organisation with a TOOI code, not a TOOI id' => [['@self' => $self], ['org-uuid-1' => ['name' => 'Gemeente Zuiddrecht', 'tooi' => 'gm0855']]],
			'organisation not found' => [['@self' => $self], []],
			'lookup fails' => [['@self' => $self], 'fails'],
			'TOOI id on the publication, organisation by uuid' => [['tooiIdentifier' => $tooi, '@self' => $self], ['org-uuid-1' => ['name' => 'Gemeente Zuiddrecht', 'tooi' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0363']]],
			'organisation is a TOOI id' => [['@self' => ['owner' => 'jan.jansen', 'organisation' => $tooi]], []],
			'no organisation' => [['@self' => ['owner' => 'jan.jansen']], []],
			'nothing at all' => [[], []],
		];
		$file = ['downloadUrl' => 'https://example.com/a.pdf', 'extension' => 'pdf', 'owner' => 'piet.pietersen'];

		$actual = [];
		foreach ($worlds as $name => [$publication, $store]) {
			$lookups = [];
			$method = new \ReflectionMethod(SitemapService::class, 'mapDiwooDocument');
			$service = $this->service(store: $store, lookups: $lookups);
			$runs = [];
			foreach ([$publication + ['id' => 'p1'], $publication + ['id' => 'p2']] as $one) {
				$violations = [];
				$entry = $method->invokeArgs($service, [$one, $file, &$violations]);
				$runs[] = ['publisher' => $entry['diwoo:Document']['diwoo:DiWoo']['diwoo:publisher'], 'violations' => $violations];
			}

			$actual[$name] = ['runs' => $runs, 'lookups' => $lookups];
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
		$this->assertStringNotContainsString('jansen', (string)json_encode($actual), 'No user id reaches the publisher.');
		$this->assertStringNotContainsString('pietersen', (string)json_encode($actual), 'No user id reaches the publisher.');
	}//end testThePublisherMatchesTheRecording()

	/**
	 * An OpenRegister organisation with a name and a TOOI identifier.
	 *
	 * The real entity when OpenRegister is loaded, otherwise a double with the same getters.
	 *
	 * @param array{name: string|null, tooi: string|null} $fields The name and the TOOI identifier.
	 *
	 * @return object The organisation.
	 */
	public static function organisation(array $fields): object {
		if (class_exists('OCA\OpenRegister\Db\Organisation') === true) {
			$organisation = new \OCA\OpenRegister\Db\Organisation();
			$organisation->setName($fields['name']);
			$organisation->setTooi($fields['tooi']);

			return $organisation;
		}

		return new class($fields) {
			/**
			 * @param array{name: string|null, tooi: string|null} $fields The name and the TOOI identifier.
			 */
			public function __construct(
				private array $fields,
			) {
			}

			public function getName(): ?string {
				return $this->fields['name'];
			}

			public function getTooi(): ?string {
				return $this->fields['tooi'];
			}
		};
	}//end organisation()

	/**
	 * A SitemapService whose OpenRegister answers organisation lookups from the store.
	 *
	 * @param mixed $store The organisations by uuid, or 'fails'.
	 * @param array<int, mixed> $lookups The lookups, by reference.
	 *
	 * @return SitemapService
	 */
	private function service(mixed $store, array &$lookups): SitemapService {
		$objects = new class($lookups) {
			/**
			 * @param array<int, mixed> $lookups The lookups, by reference.
			 */
			public function __construct(
				private array &$lookups,
			) {
			}

			/**
			 * Answer as OpenRegister does for a query without a register and schema: nothing.
			 *
			 * @return array
			 */
			public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->lookups[] = ['searchObjects' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return [];
			}
		};
		$organisations = new class($store, $lookups) {
			/**
			 * @param mixed $store The organisations by uuid, or 'fails'.
			 * @param array<int, mixed> $lookups The lookups, by reference.
			 */
			public function __construct(
				private mixed $store,
				private array &$lookups,
			) {
			}

			/**
			 * Find an organisation as OpenRegister's OrganisationMapper does: the entity, or an exception.
			 *
			 * @return object The organisation.
			 */
			public function findByUuid(string $uuid): object {
				$this->lookups[] = ['findByUuid' => $uuid];
				if ($this->store === 'fails') {
					throw new \RuntimeException('store down');
				}

				if (isset($this->store[$uuid]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Organisation not found');
				}

				return SitemapPublisherGoldenTest::organisation(fields: $this->store[$uuid]);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === 'OCA\OpenRegister\Db\OrganisationMapper' ? $organisations : $objects)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$categories = new WooCategoryRegistry(new TooiVocabularyService(), $settings, $this->createMock(LoggerInterface::class), new LocalCategoryAdmission(new TooiVocabularyService()));

		return new SitemapService(
			new OpenRegisterLookup($container, $apps),
			$settings,
			new InstanceSettings($this->createMock(IURLGenerator::class), $this->createMock(IAppConfig::class)),
			new TooiVocabularyService(),
			$this->createMock(PublicationQueryService::class),
			$categories
		);
	}//end service()
}//end class
