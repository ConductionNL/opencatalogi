<?php

/**
 * Tests for the harvest feeds: OpenRegister sources owned by OpenCatalogi.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Harvest
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Harvest;

use OCA\OpenCatalogi\Service\Harvest\HarvestFeedCatalogues;
use OCA\OpenCatalogi\Service\Harvest\HarvestFeedRefused;
use OCA\OpenCatalogi\Service\Harvest\HarvestFeedService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Service\Sync\SourceRefused;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The service is admin only, lists this app's sources, targets the catalogue
 * and writes the draft-only and provenance fields on every save.
 */
class HarvestFeedServiceTest extends TestCase {

	/**
	 * The catalogue the feeds land in.
	 */
	private const CATALOG = 'c0ffee00-0000-4000-8000-000000000001';

	/**
	 * OpenRegister's source writer, recording each write.
	 *
	 * @var object
	 */
	private object $sources;

	/**
	 * OpenRegister's source table.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * OpenRegister's register table.
	 *
	 * @var object
	 */
	private object $registers;

	/**
	 * The catalogues the object service answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $catalogs = [];

	/**
	 * How often the catalogues were searched.
	 *
	 * @var integer
	 */
	private int $catalogSearches = 0;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogs = [
			['id' => self::CATALOG, 'title' => 'Open data', 'registers' => [5], 'schemas' => [12]],
		];
		$this->sources   = new class {

			/**
			 * The writes.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * The refusal the next write throws, if any.
			 *
			 * @var \Throwable|null
			 */
			public ?\Throwable $refusal = null;

			/**
			 * Create.
			 *
			 * @param array<string, mixed> $data The fields.
			 *
			 * @return object
			 */
			public function create(array $data): object {
				return $this->record(write: ['create', $data]);
			}//end create()

			/**
			 * Update.
			 *
			 * @param int                  $id   The id.
			 * @param array<string, mixed> $data The fields.
			 *
			 * @return object
			 */
			public function update(int $id, array $data): object {
				return $this->record(write: ['update', $data, $id]);
			}//end update()

			/**
			 * Delete.
			 *
			 * @param int $id The id.
			 *
			 * @return object
			 */
			public function delete(int $id): object {
				return $this->record(write: ['delete', [], $id]);
			}//end delete()

			/**
			 * Record a write and answer with its fields.
			 *
			 * @param array<int, mixed> $write The write.
			 *
			 * @return object
			 */
			private function record(array $write): object {
				if ($this->refusal !== null) {
					throw $this->refusal;
				}

				$this->writes[] = $write;
				return new class($write[1]) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $data The fields.
					 */
					public function __construct(private array $data) {
					}//end __construct()

					/**
					 * The fields.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}//end jsonSerialize()
				};
			}//end record()
		};
		$this->mapper    = new class {

			/**
			 * The stored sources.
			 *
			 * @var array<int, object>
			 */
			public array $stored = [];

			/**
			 * The filters the list was asked with.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $filters = null;

			/**
			 * All sources.
			 *
			 * @param int|null                  $limit   The limit.
			 * @param int|null                  $offset  The offset.
			 * @param array<string, mixed>|null $filters The filters.
			 *
			 * @return array<int, object>
			 */
			public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[]): array {
				$this->filters = $filters;
				return array_values($this->stored);
			}//end findAll()

			/**
			 * One source.
			 *
			 * @param int $id The id.
			 *
			 * @return object
			 */
			public function find(int $id): object {
				if (isset($this->stored[$id]) === false) {
					throw new RuntimeException('Source not found');
				}

				return $this->stored[$id];
			}//end find()
		};
		$this->registers = new class {

			/**
			 * The schemas per register.
			 *
			 * @var array<int, array<int, int>>
			 */
			public array $schemas = [];

			/**
			 * One register.
			 *
			 * @param int $id The id.
			 *
			 * @return object
			 */
			public function find(int $id): object {
				$schemas = ($this->schemas[$id] ?? []);
				return new class($schemas) {
					/**
					 * Constructor.
					 *
					 * @param array<int, int> $schemas The schemas.
					 */
					public function __construct(private array $schemas) {
					}//end __construct()

					/**
					 * The schemas.
					 *
					 * @return array<int, int>
					 */
					public function getSchemas(): array {
						return $this->schemas;
					}//end getSchemas()
				};
			}//end find()
		};

	}//end setUp()

	/**
	 * A stored source double.
	 *
	 * @param string               $application The owning app.
	 * @param array<string, mixed> $config      Its config.
	 * @param string|null          $schema      Its target schema.
	 *
	 * @return object
	 */
	private static function storedSource(string $application, array $config=[], ?string $schema='12'): object {
		return new class($application, $config, $schema) {
			/**
			 * Constructor.
			 *
			 * @param string               $application The owning app.
			 * @param array<string, mixed> $config      Its config.
			 * @param string|null          $schema      Its target schema.
			 */
			public function __construct(private string $application, private array $config, private ?string $schema) {
			}//end __construct()

			/**
			 * The owning app.
			 *
			 * @return string
			 */
			public function getApplication(): string {
				return $this->application;
			}//end getApplication()

			/**
			 * The config.
			 *
			 * @return array<string, mixed>
			 */
			public function getConfig(): array {
				return $this->config;
			}//end getConfig()

			/**
			 * The target schema.
			 *
			 * @return string|null
			 */
			public function getTargetSchema(): ?string {
				return $this->schema;
			}//end getTargetSchema()

			/**
			 * The serialised source.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['application' => $this->application];
			}//end jsonSerialize()
		};

	}//end storedSource()

	/**
	 * The service, as an administrator or not.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return HarvestFeedService
	 */
	private function service(bool $admin=true): HarvestFeedService {
		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('installedService')->willReturnCallback(
			fn (string $serviceId, string $unavailable): object => match ($serviceId) {
				'OCA\OpenRegister\Service\Sync\SourceService' => $this->sources,
				'OCA\OpenRegister\Db\SourceMapper' => $this->mapper,
				'OCA\OpenRegister\Db\RegisterMapper' => $this->registers,
			}
		);
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param HarvestFeedServiceTest $test The test, holding the catalogues.
			 */
			public function __construct(private HarvestFeedServiceTest $test) {
			}//end __construct()

			/**
			 * The catalogues.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array{results: array<int, array<string, mixed>>}
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				return ['results' => $this->test->searchCatalogs(query: $query)];
			}//end searchObjectsPaginated()
		};
		$lookup->method('installedObjectService')->willReturn($objects);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => match ($key) {
				'catalog_register' => '1',
				'catalog_schema' => '2',
				default => $default,
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new HarvestFeedService(
			openRegister: $lookup,
			catalogues: new HarvestFeedCatalogues(config: $config, openRegister: $lookup),
			session: $session,
			groups: $groups
		);

	}//end service()

	/**
	 * The catalogue search, answered from the fixture catalogues.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchCatalogs(array $query): array {
		$this->assertSame(expected: ['register' => '1', 'schema' => '2'], actual: $query['@self']);
		$this->catalogSearches++;
		return $this->catalogs;

	}//end searchCatalogs()

	/**
	 * A valid new feed.
	 *
	 * @param array<string, mixed> $extra Fields to add or override.
	 *
	 * @return array<string, mixed>
	 */
	private static function feed(array $extra=[]): array {
		return array_merge(
			[
				'title' => 'Open data Zuiddrecht',
				'config' => ['sourceUrl' => 'https://data.example.nl/catalog.jsonld', 'targetCatalog' => self::CATALOG],
				'targetSchema' => '12',
				'mappingId' => 3,
				'schedule' => '15 3 * * *',
				'runAs' => 'harvest-bot',
			],
			$extra
		);

	}//end feed()

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return HarvestFeedRefused
	 */
	private function refusalOf(callable $call): HarvestFeedRefused {
		try {
			$call();
		} catch (HarvestFeedRefused $e) {
			return $e;
		}

		$this->fail(message: 'Expected a refusal');

	}//end refusalOf()

	/**
	 * A valid feed is written through the source service, targeted at the catalogue.
	 *
	 * @return void
	 */
	public function testAValidFeedIsSaved(): void {
		$stored = $this->service()->create(data: self::feed());

		$this->assertCount(expectedCount: 1, haystack: $this->sources->writes);
		$this->assertSame(expected: 'create', actual: $this->sources->writes[0][0]);
		$this->assertSame(expected: 'opencatalogi', actual: $stored['application']);
		$this->assertSame(expected: '5', actual: $stored['targetRegister']);
		$this->assertSame(expected: '12', actual: $stored['targetSchema']);
		$this->assertSame(expected: 'harvest-bot', actual: $stored['runAs']);
		$this->assertSame(expected: 'manual', actual: $stored['conflictStrategy']);
		$this->assertTrue(condition: $stored['syncEnabled']);

	}//end testAValidFeedIsSaved()

	/**
	 * A schema outside the catalogue is refused, naming it, and nothing is saved.
	 *
	 * @return void
	 */
	public function testASchemaOutsideTheCatalogueIsRefused(): void {
		$refusal = $this->refusalOf(call: fn () => $this->service()->create(data: self::feed(extra: ['targetSchema' => '13'])));

		$this->assertSame(expected: 422, actual: $refusal->status);
		$this->assertSame(expected: 'targetSchema', actual: $refusal->field);
		$this->assertStringContainsString(needle: '13', haystack: $refusal->getMessage());
		$this->assertStringContainsString(needle: '"Open data"', haystack: $refusal->getMessage());
		$this->assertSame(expected: [], actual: $this->sources->writes);

	}//end testASchemaOutsideTheCatalogueIsRefused()

	/**
	 * A catalogue that does not exist is refused on the catalogue field.
	 *
	 * @return void
	 */
	public function testAnUnknownCatalogueIsRefused(): void {
		$feed    = self::feed(extra: ['config' => ['sourceUrl' => 'https://x.example/c', 'targetCatalog' => 'missing']]);
		$refusal = $this->refusalOf(call: fn () => $this->service()->create(data: $feed));

		$this->assertSame(expected: 'targetCatalog', actual: $refusal->field);
		$this->assertSame(expected: [], actual: $this->sources->writes);

	}//end testAnUnknownCatalogueIsRefused()

	/**
	 * A feed without catalogue or schema says which is missing.
	 *
	 * @return void
	 */
	public function testAFeedNeedsACatalogueAndASchema(): void {
		$withoutCatalog = self::feed(extra: ['config' => ['sourceUrl' => 'https://x.example/c']]);
		$noCatalog      = $this->refusalOf(call: fn () => $this->service()->create(data: $withoutCatalog));
		$noSchema  = $this->refusalOf(call: fn () => $this->service()->create(data: self::feed(extra: ['targetSchema' => ''])));

		$this->assertSame(expected: 'targetCatalog', actual: $noCatalog->field);
		$this->assertSame(expected: 'targetSchema', actual: $noSchema->field);

	}//end testAFeedNeedsACatalogueAndASchema()

	/**
	 * With several registers the one holding the schema is the target.
	 *
	 * @return void
	 */
	public function testOfSeveralRegistersTheOneHoldingTheSchemaIsTheTarget(): void {
		$this->catalogs[0]['registers'] = [5, 7];
		$this->registers->schemas       = [5 => [11], 7 => [12, 14]];

		$stored = $this->service()->create(data: self::feed());

		$this->assertSame(expected: '7', actual: $stored['targetRegister']);

		$this->registers->schemas = [5 => [11], 7 => [14]];
		$refusal = $this->refusalOf(call: fn () => $this->service()->create(data: self::feed()));
		$this->assertSame(expected: 'targetSchema', actual: $refusal->field);

	}//end testOfSeveralRegistersTheOneHoldingTheSchemaIsTheTarget()

	/**
	 * Every save writes the draft-only and provenance fields, whatever the request says.
	 *
	 * @return void
	 */
	public function testEveryFeedProtectsThePublicationFields(): void {
		$this->mapper->stored[4] = self::storedSource(application: 'opencatalogi', config: self::feed()['config']);
		$forged = [
			'application' => 'elsewhere',
			'type' => 'rest-api',
			'protectedFields' => [],
			'provenance' => ['externalIdProperty' => 'title'],
			'identityProperty' => 'title',
			'deleteStrategy' => 'hard-delete',
		];

		$created = $this->service()->create(data: self::feed(extra: $forged));
		$updated = $this->service()->update(id: 4, data: $forged);

		foreach ([$created, $updated] as $stored) {
			$this->assertSame(expected: 'opencatalogi', actual: $stored['application']);
			$this->assertSame(expected: 'opencatalogi.dcat-jsonld', actual: $stored['type']);
			$this->assertSame(expected: ['publicationDate', 'depublicationDate', 'status'], actual: $stored['protectedFields']);
			$this->assertSame(expected: ['externalIdProperty' => 'source', 'sourceProperty' => 'derivedFrom'], actual: $stored['provenance']);
			$this->assertSame(expected: 'source', actual: $stored['identityProperty']);
			$this->assertSame(expected: 'flag', actual: $stored['deleteStrategy']);
		}

	}//end testEveryFeedProtectsThePublicationFields()

	/**
	 * OpenRegister's refusal of a schedule without an account is shown on Run as.
	 *
	 * @return void
	 */
	public function testARunAsRefusalIsShownOnTheField(): void {
		if (class_exists(SourceRefused::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s SourceRefused is not next to the app.');
		}

		$this->sources->refusal = new SourceRefused(field: 'runAs', message: 'A scheduled harvest needs an account to act as.');

		$refusal = $this->refusalOf(call: fn () => $this->service()->create(data: self::feed(extra: ['runAs' => ''])));

		$this->assertSame(expected: 422, actual: $refusal->status);
		$this->assertSame(expected: 'runAs', actual: $refusal->field);
		$this->assertSame(expected: ['error' => 'A scheduled harvest needs an account to act as.', 'field' => 'runAs'], actual: $refusal->toArray());

	}//end testARunAsRefusalIsShownOnTheField()

	/**
	 * Switch off saves syncEnabled false and leaves the target alone.
	 *
	 * @return void
	 */
	public function testSwitchOffSavesSyncDisabled(): void {
		$this->mapper->stored[4] = self::storedSource(application: 'opencatalogi');

		$stored = $this->service()->update(id: 4, data: ['syncEnabled' => false]);

		$this->assertSame(expected: ['update'], actual: [$this->sources->writes[0][0]]);
		$this->assertSame(expected: 4, actual: $this->sources->writes[0][2]);
		$this->assertFalse(condition: $stored['syncEnabled']);
		$this->assertArrayNotHasKey(key: 'targetSchema', array: $stored);
		$this->assertSame(expected: 0, actual: $this->catalogSearches);

	}//end testSwitchOffSavesSyncDisabled()

	/**
	 * A new source on update targets the catalogue again, keeping the stored schema.
	 *
	 * @return void
	 */
	public function testANewSourceOnUpdateIsTargetedAgain(): void {
		$this->mapper->stored[4] = self::storedSource(application: 'opencatalogi', config: self::feed()['config'], schema: '12');

		$stored = $this->service()->update(
			id: 4,
			data: ['config' => ['sourceSlug' => 'data-overheid', 'targetCatalog' => self::CATALOG]]
		);

		$this->assertSame(expected: '12', actual: $stored['targetSchema']);
		$this->assertSame(expected: '5', actual: $stored['targetRegister']);
		$this->assertSame(expected: 1, actual: $this->catalogSearches);

	}//end testANewSourceOnUpdateIsTargetedAgain()

	/**
	 * A non-administrator reads and writes nothing.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->mapper->stored[4] = self::storedSource(application: 'opencatalogi');
		$service = $this->service(admin: false);

		foreach ([
			fn () => $service->list(),
			fn () => $service->create(data: self::feed()),
			fn () => $service->update(id: 4, data: ['syncEnabled' => false]),
			fn () => $service->delete(id: 4),
		] as $call) {
			$this->assertSame(expected: 403, actual: $this->refusalOf(call: $call)->status);
		}

		$this->assertNull(actual: $this->mapper->filters);
		$this->assertSame(expected: [], actual: $this->sources->writes);

	}//end testANonAdminIsRefused()

	/**
	 * The list holds only this app's sources.
	 *
	 * @return void
	 */
	public function testTheListHoldsOnlyThisAppsSources(): void {
		$this->mapper->stored = [
			1 => self::storedSource(application: 'opencatalogi'),
			2 => self::storedSource(application: 'pipelinq'),
		];

		$feeds = $this->service()->list();

		$this->assertSame(expected: [['application' => 'opencatalogi']], actual: $feeds);
		$this->assertSame(expected: ['application' => 'opencatalogi'], actual: $this->mapper->filters);

	}//end testTheListHoldsOnlyThisAppsSources()

	/**
	 * Another app's source, or none, is not a feed.
	 *
	 * @return void
	 */
	public function testAnotherAppsSourceIsNotFound(): void {
		$this->mapper->stored[2] = self::storedSource(application: 'pipelinq');

		$other   = $this->refusalOf(call: fn () => $this->service()->update(id: 2, data: ['syncEnabled' => false]));
		$missing = $this->refusalOf(call: fn () => $this->service()->delete(id: 9));

		$this->assertSame(expected: 404, actual: $other->status);
		$this->assertSame(expected: 404, actual: $missing->status);
		$this->assertSame(expected: [], actual: $this->sources->writes);

	}//end testAnotherAppsSourceIsNotFound()

	/**
	 * Delete removes the source through the source service.
	 *
	 * @return void
	 */
	public function testDeleteRemovesTheSource(): void {
		$this->mapper->stored[4] = self::storedSource(application: 'opencatalogi');

		$this->service()->delete(id: 4);

		$this->assertSame(expected: [['delete', [], 4]], actual: $this->sources->writes);

	}//end testDeleteRemovesTheSource()
}//end class
