<?php

/**
 * Characterization tests for SettingsService::updateObjectTypeConfiguration().
 *
 * Pins every config write, in order, for an import result in both the entity
 * and the array shape, and for the slug and register fallback lookups.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SettingsObjectTypeConfigurationTest extends TestCase {
	private ContainerInterface|MockObject $container;
	private LoggerInterface|MockObject $logger;
	private SettingsService $service;

	/** @var array<int, string> every setValueString() call as "key=value", in order */
	private array $writes = [];

	protected function setUp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->writes[] = $key . '=' . $value;
				return true;
			}
		);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->service = new SettingsService(
			$config,
			$this->container,
			$appManager,
			$this->logger,
			$this->createMock(RegisterSchemaLinkService::class)
		);
	}

	/**
	 * Run the private method under test.
	 *
	 * @param array $importResult The import result.
	 *
	 * @return void
	 */
	private function update(array $importResult): void {
		$method = new \ReflectionMethod(SettingsService::class, 'updateObjectTypeConfiguration');
		$method->invoke($this->service, $importResult);
	}

	/**
	 * An entity-shaped object with an id and a serialised form.
	 *
	 * @param array $data The serialised entity.
	 * @param int   $id   The id.
	 *
	 * @return object
	 */
	private function entity(array $data, int $id = 0): object {
		return new class($data, $id) {
			public function __construct(private array $data, private int $id) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}

			public function getId(): int {
				return $this->id;
			}
		};
	}

	/**
	 * A mocked OpenRegister entity of the given class with only an id.
	 *
	 * @param string $class The entity class.
	 * @param int    $id    The id.
	 *
	 * @return MockObject
	 */
	private function typedEntity(string $class, int $id): MockObject {
		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if (method_exists($class, 'getId') === true) {
			$builder->onlyMethods(['getId']);
		} else {
			$builder->addMethods(['getId']);
		}

		$entity = $builder->getMock();
		$entity->method('getId')->willReturn($id);
		return $entity;
	}

	public function testEveryKeyIsWrittenInOrderFromTheImportResult(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no mappers'));

		$schemas = [
			$this->entity(['slug' => 'catalog', 'id' => 1]),
			$this->entity(['slug' => 'listing']),
			new \stdClass(),
			['slug' => 'publication', 'uuid' => 'pub-uuid'],
			['slug' => 'theme'],
			['id' => 99],
		];
		$slugs = [
			'glossary', 'course', 'program', 'offering', 'wooBatch', 'wooAssessment', 'withheldDocument',
			'serviceCatalogueEntry', 'caseTypeDefinition', 'knowledgeArticle', 'articleVerdict',
			'publicationRule', 'inspection', 'publicationProcess', 'zienswijzeAsk', 'depublication', 'obligationSource',
			'wooRequest', 'commentPeriod',
			'serviceStatus', 'statusSubscription', 'instanceBanner', 'noticeBoard', 'notice', 'recordVote',
		];
		foreach ($slugs as $index => $slug) {
			$schemas[] = ['slug' => $slug, 'id' => (100 + $index)];
		}

		$this->update([
			'schemas' => $schemas,
			'registers' => [
				['slug' => 'other', 'id' => 5],
				$this->entity(['slug' => 'publication', 'uuid' => 'reg-uuid']),
				['slug' => 'publication', 'id' => 6],
			],
		]);

		$this->assertSame(
			[
				'catalog_source=openregister', 'catalog_schema=1', 'catalog_register=reg-uuid',
				'listing_source=openregister', 'listing_register=reg-uuid',
				'publication_source=openregister', 'publication_schema=pub-uuid', 'publication_register=reg-uuid',
				'theme_source=openregister', 'theme_register=reg-uuid',
				'glossary_source=openregister', 'glossary_schema=100', 'glossary_register=reg-uuid',
				'ooapi_courses_source=openregister', 'ooapi_courses_schema=101', 'ooapi_courses_register=reg-uuid',
				'ooapi_programs_source=openregister', 'ooapi_programs_schema=102', 'ooapi_programs_register=reg-uuid',
				'ooapi_offerings_source=openregister', 'ooapi_offerings_schema=103', 'ooapi_offerings_register=reg-uuid',
				'woo_register=reg-uuid',
				'woo_batch_schema=104', 'woo_assessment_schema=105', 'withheld_document_schema=106',
				'service_catalogue_register=reg-uuid',
				'service_catalogue_entry_schema=107', 'case_type_definition_schema=108',
				'knowledge_article_schema=109', 'article_verdict_schema=110',
				'publication_rule_schema=111', 'inspection_schema=112', 'publication_process_schema=113',
				'zienswijze_ask_schema=114', 'depublication_schema=115', 'obligation_source_schema=116',
				'woo_request_schema=117', 'comment_period_schema=118',
				'service_status_schema=119', 'status_subscription_schema=120', 'instance_banner_schema=121',
				'notice_board_schema=122', 'notice_schema=123', 'record_vote_schema=124',
			],
			$this->writes
		);
	}

	public function testMissingSlugsAndTheRegisterAreLookedUpAndTheOrganisationIsResolved(): void {
		// The real SchemaMapper (CI) has findByApplicationAndSlug; the local stub does not.
		$builder = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor();
		if (method_exists(SchemaMapper::class, 'findByApplicationAndSlug') === true) {
			$builder->onlyMethods(['find', 'findByApplicationAndSlug']);
		} else {
			$builder->onlyMethods(['find'])->addMethods(['findByApplicationAndSlug']);
		}

		$schemaMapper = $builder->getMock();
		$looked = [];
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(
			function (string $slug, string $app) use (&$looked) {
				$looked[] = $slug . '@' . $app;
				return match ($slug) {
					'listing' => $this->typedEntity(class: Schema::class, id: 41),
					'notice' => $this->typedEntity(class: Schema::class, id: 42),
					default => null,
				};
			}
		);
		$schemaMapper->method('find')->willReturn($this->typedEntity(class: Schema::class, id: 8));
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			fn ($id) => $this->typedEntity(class: Register::class, id: ($id === 'publication' ? 77 : 9))
		);
		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				SchemaMapper::class, 'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
				default => $registerMapper,
			}
		);

		$this->update(['schemas' => [['slug' => 'catalog', 'id' => 3]]]);

		$this->assertCount(28, $looked);
		$this->assertSame('listing@opencatalogi', $looked[0]);
		$this->assertSame(
			[
				'catalog_source=openregister', 'catalog_schema=3', 'catalog_register=77',
				'listing_source=openregister', 'listing_schema=41', 'listing_register=77',
				'publication_source=openregister', 'publication_register=77',
				'theme_source=openregister', 'theme_register=77',
				'glossary_source=openregister', 'glossary_register=77',
				'ooapi_courses_source=openregister', 'ooapi_courses_register=77',
				'ooapi_programs_source=openregister', 'ooapi_programs_register=77',
				'ooapi_offerings_source=openregister', 'ooapi_offerings_register=77',
				'woo_register=77',
				'service_catalogue_register=77',
				'notice_schema=42',
				'organization_source=openregister', 'organization_register=9', 'organization_schema=8',
			],
			$this->writes
		);
	}

	public function testFailingFallbackLookupsAreLoggedAndLeaveTheKeysUnset(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('down'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->update([]);

		$this->assertSame(
			[
				'Schema slug fallback lookup failed: down',
				'Register slug fallback lookup failed: down',
				'OpenCatalogi: the shared organisation is unavailable, leaving organization_* unset',
			],
			$warnings
		);
		$this->assertSame(
			[
				'catalog_source=openregister', 'listing_source=openregister', 'publication_source=openregister',
				'theme_source=openregister', 'glossary_source=openregister', 'ooapi_courses_source=openregister',
				'ooapi_programs_source=openregister', 'ooapi_offerings_source=openregister',
			],
			$this->writes
		);
	}
}
