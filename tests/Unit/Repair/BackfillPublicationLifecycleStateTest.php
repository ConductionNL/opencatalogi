<?php

/**
 * Unit tests for BackfillPublicationLifecycleState: a legacy publication with a
 * date becomes published, one without becomes a draft, a stored state is kept,
 * a second run changes nothing, the date-only read rule gets the published
 * condition, and the step is registered post-migration.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Repair
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\BackfillPublicationLifecycleState;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Repair\BackfillPublicationLifecycleState
 */
class BackfillPublicationLifecycleStateTest extends TestCase {

	/**
	 * The stored publications, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The saves the step made: register, schema and uuid.
	 *
	 * @var array<int, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private array $saves = [];

	/**
	 * The info lines the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * The schemas the schema mapper answers.
	 *
	 * @var array<int, MockObject>
	 */
	private array $schemas = [];

	/**
	 * The schemas the step updated.
	 *
	 * @var array<int, object>
	 */
	private array $updated = [];

	/**
	 * Builds a double of an OpenRegister class whose methods may be absent from the stub.
	 *
	 * Same helper as SitemapServiceTest::environmentAwareDouble().
	 *
	 * @param class-string       $class   The class to double.
	 * @param array<int, string> $methods The methods to configure.
	 *
	 * @return MockObject The double.
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = [];
		$absent   = [];
		foreach ($methods as $method) {
			if (method_exists($class, $method) === true) {
				$existing[] = $method;
			} else {
				$absent[] = $method;
			}
		}

		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if ($existing !== []) {
			$builder->onlyMethods($existing);
		}

		if ($absent !== []) {
			$builder->addMethods($absent);
		}

		return $builder->getMock();
	}//end environmentAwareDouble()

	/**
	 * A schema double with a slug, an id and an authorization block that the step may replace.
	 *
	 * @param string               $slug          The slug.
	 * @param array<string, mixed> $authorization The authorization block.
	 *
	 * @return MockObject The schema.
	 */
	private function schema(string $slug, array $authorization): MockObject {
		$schema = $this->environmentAwareDouble(\OCA\OpenRegister\Db\Schema::class, ['getSlug', 'getId', 'getAuthorization', 'setAuthorization']);
		$state  = new \ArrayObject(['authorization' => $authorization]);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getId')->willReturn(12);
		$schema->method('getAuthorization')->willReturnCallback(static fn (): array => $state['authorization']);
		$schema->method('setAuthorization')->willReturnCallback(
			static function (array $value) use ($state): void {
				$state['authorization'] = $value;
			}
		);

		return $schema;
	}//end schema()

	/**
	 * Run the step once over the store and the schemas.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$objects = $this->environmentAwareDouble(\OCA\OpenRegister\Service\ObjectService::class, ['searchObjectsPaginated', 'saveObject']);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			fn (array $query = []): array => ($query['@self'] === ['register' => '7', 'schema' => '12'] && $query['_page'] === 1)
				? ['results' => array_values($this->store)]
				: ['results' => []]
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions $options): \OCA\OpenRegister\Db\ObjectEntity {
				$this->saves[] = [$options->target->register, $options->target->schema, $options->uuid];
				$this->store[(string)$options->uuid] = $object;
				$entity = new \OCA\OpenRegister\Db\ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getSettings')->willReturn([
			'availableRegisters' => [
				['id' => '7', 'schemas' => [['id' => '12', 'slug' => 'publication'], ['id' => '13', 'slug' => 'catalog']]],
			],
		]);

		$mapper = $this->environmentAwareDouble(\OCA\OpenRegister\Db\SchemaMapper::class, ['findAll', 'update']);
		$mapper->method('findAll')->willReturnCallback(fn (): array => $this->schemas);
		$mapper->method('update')->willReturnCallback(
			function (object $schema): object {
				$this->updated[] = $schema;
				return $schema;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Db\\SchemaMapper')->willReturn($mapper);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $line): void {
				$this->info[] = $line;
			}
		);

		(new BackfillPublicationLifecycleState($settings, $container, $this->createMock(LoggerInterface::class)))->run($output);
	}//end runStep()

	/**
	 * REQ-PLC-007: a legacy publication with a publication date becomes published, released at that date.
	 *
	 * @return void
	 */
	public function testALegacyPublicationWithADateBecomesPublished(): void {
		$this->store = ['pub-1' => ['id' => 'pub-1', 'title' => 'Besluit', 'publicationDate' => '2025-03-01T00:00:00+00:00', '@self' => ['id' => 'pub-1']]];
		$this->runStep();

		$this->assertSame('published', $this->store['pub-1']['status']);
		$this->assertSame('2025-03-01T00:00:00+00:00', $this->store['pub-1']['firstReleasedAt']);
		$this->assertSame('2025-03-01T00:00:00+00:00', $this->store['pub-1']['publicationDate']);
		$this->assertSame('Besluit', $this->store['pub-1']['title']);
		$this->assertArrayNotHasKey('@self', $this->store['pub-1']);
		$this->assertSame([['7', '12', 'pub-1']], $this->saves);
		$this->assertStringContainsString('1 publication(s) set to published, 0 set to draft, 0 kept', $this->info[0]);
	}//end testALegacyPublicationWithADateBecomesPublished()

	/**
	 * REQ-PLC-007: a legacy publication without a date becomes a draft and gets no release moment.
	 *
	 * @return void
	 */
	public function testALegacyPublicationWithoutADateBecomesADraft(): void {
		$this->store = ['pub-2' => ['id' => 'pub-2', 'title' => 'Concept', 'status' => '', 'publicationDate' => null]];
		$this->runStep();

		$this->assertSame('draft', $this->store['pub-2']['status']);
		$this->assertArrayNotHasKey('firstReleasedAt', $this->store['pub-2']);
		$this->assertStringContainsString('0 publication(s) set to published, 1 set to draft', $this->info[0]);
	}//end testALegacyPublicationWithoutADateBecomesADraft()

	/**
	 * REQ-PLC-007: a stored state, archived included, is left as it is.
	 *
	 * @return void
	 */
	public function testAStoredStateIsKept(): void {
		$this->store = [
			'pub-3' => ['id' => 'pub-3', 'status' => 'archived', 'publicationDate' => '2024-01-01T00:00:00+00:00'],
			'pub-4' => ['id' => 'pub-4', 'status' => 'in_review'],
		];
		$before = $this->store;
		$this->runStep();

		$this->assertSame($before, $this->store);
		$this->assertSame([], $this->saves);
		$this->assertStringContainsString('2 kept their state', $this->info[0]);
	}//end testAStoredStateIsKept()

	/**
	 * REQ-PLC-007: a second run changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->store   = ['pub-1' => ['id' => 'pub-1', 'publicationDate' => '2025-03-01T00:00:00+00:00'], 'pub-2' => ['id' => 'pub-2']];
		$this->schemas = [$this->schema(slug: 'publication', authorization: ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]]]])];
		$this->runStep();
		$afterFirst    = $this->store;
		$this->saves   = [];
		$this->updated = [];
		$this->runStep();

		$this->assertSame($afterFirst, $this->store);
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->updated);
		$this->assertStringContainsString('0 publication(s) set to published, 0 set to draft, 2 kept', $this->info[count($this->info) - 1]);
	}//end testASecondRunChangesNothing()

	/**
	 * REQ-PLC-007: a date-only public read rule of the publication schema gets the published condition; other rules stay.
	 *
	 * @return void
	 */
	public function testADateOnlyReadRuleGetsThePublishedCondition(): void {
		$dateOnly = ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]], 'crossOrganisation' => true];
		$custom   = ['group' => 'public', 'match' => ['status' => 'published', 'publicationDate' => ['$lte' => '$now']]];
		$this->schemas = [
			$this->schema(slug: 'publication', authorization: ['read' => [$dateOnly, $custom, 'authenticated'], 'update' => ['editors']]),
			$this->schema(slug: 'catalog', authorization: ['read' => [$dateOnly]]),
		];
		$this->runStep();

		$this->assertCount(1, $this->updated);
		$this->assertSame(
			[
				'read' => [
					['group' => 'public', 'match' => ['status' => 'published', 'publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]], 'crossOrganisation' => true],
					$custom,
					'authenticated',
				],
				'update' => ['editors'],
			],
			$this->schemas[0]->getAuthorization()
		);
		$this->assertSame(['read' => [$dateOnly]], $this->schemas[1]->getAuthorization());
	}//end testADateOnlyReadRuleGetsThePublishedCondition()

	/**
	 * REQ-PLC-007: without OpenRegister the step reports it and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsWritten(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('BackfillPublicationLifecycleState: OpenRegister is not available; nothing to do.');

		(new BackfillPublicationLifecycleState($settings, $container, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterNothingIsWritten()

	/**
	 * REQ-PLC-007: the step is a post-migration repair step right after InitializeSettings.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredPostMigration(): void {
		$info  = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$position = array_search('OCA\OpenCatalogi\Repair\BackfillPublicationLifecycleState', $steps, true);
		$this->assertIsInt($position);
		$this->assertSame(array_search('OCA\OpenCatalogi\Repair\InitializeSettings', $steps, true) + 1, $position);
	}//end testTheStepIsRegisteredPostMigration()
}//end class
