<?php

/**
 * Unit tests for MoveDocumentsoortOutOfSummary: a summary that is exactly a
 * document type moves to `documentsoort`, a real summary stays, a second run
 * moves nothing, and the step is registered post-migration.
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
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 */
class MoveDocumentsoortOutOfSummaryTest extends TestCase {

	private const BESLUIT = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_465163c9';

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
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The info lines the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * Builds a double of an OpenRegister class whose methods may be absent from the stub.
	 *
	 * Same helper as SitemapServiceTest::environmentAwareDouble().
	 *
	 * @param class-string $class The class to double.
	 * @param array<int, string> $methods The methods to configure.
	 *
	 * @return MockObject The double.
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = [];
		$absent = [];
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
	 * Run the step once over the store.
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
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): \OCA\OpenRegister\Db\ObjectEntity {
				$this->saves[] = [$register, $schema, $uuid];
				$this->store[(string)$uuid] = $object;
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

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $line): void {
				$this->info[] = $line;
			}
		);

		(new MoveDocumentsoortOutOfSummary($settings, new TooiVocabularyService(), $config, $this->createMock(LoggerInterface::class)))->run($output);
	}//end runStep()

	/**
	 * REQ-DWP-005: a summary that is a document type moves to its field and the summary is emptied.
	 *
	 * @return void
	 */
	public function testASummaryThatIsADocumentTypeIsMoved(): void {
		$this->store = ['pub-1' => ['id' => 'pub-1', 'title' => 'Besluit', 'summary' => ' besluit ', '@self' => ['id' => 'pub-1']]];
		$this->runStep();

		$this->assertSame(self::BESLUIT, $this->store['pub-1']['documentsoort']);
		$this->assertSame('', $this->store['pub-1']['summary']);
		$this->assertSame('Besluit', $this->store['pub-1']['title']);
		$this->assertArrayNotHasKey('@self', $this->store['pub-1']);
		$this->assertSame([['7', '12', 'pub-1']], $this->saves);
		$this->assertStringContainsString('1 publication(s) moved, 0 summary(ies) left', $this->info[0]);
	}//end testASummaryThatIsADocumentTypeIsMoved()

	/**
	 * REQ-DWP-005: a real summary, and a publication that already has a type, stay as they are.
	 *
	 * @return void
	 */
	public function testARealSummaryIsLeftAlone(): void {
		$this->store = [
			'pub-2' => ['id' => 'pub-2', 'summary' => 'Het college besluit over parkeren in de binnenstad'],
			'pub-3' => ['id' => 'pub-3', 'summary' => 'besluit', 'documentsoort' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_2977c34f'],
		];
		$before = $this->store;
		$this->runStep();

		$this->assertSame($before, $this->store);
		$this->assertSame([], $this->saves);
		$this->assertStringContainsString('0 publication(s) moved, 2 summary(ies) left', $this->info[0]);
	}//end testARealSummaryIsLeftAlone()

	/**
	 * REQ-DWP-005: a second run moves nothing and keeps the moment the fields were introduced.
	 *
	 * @return void
	 */
	public function testASecondRunMovesNothing(): void {
		$this->store = ['pub-1' => ['id' => 'pub-1', 'summary' => 'besluit']];
		$this->runStep();
		$introduced = $this->config['diwoo_fields_introduced_at'];
		$this->assertNotFalse(strtotime($introduced));

		$this->config['diwoo_fields_introduced_at'] = '2026-10-01T00:00:00+00:00';
		$this->saves = [];
		$this->runStep();

		$this->assertSame([], $this->saves);
		$this->assertSame('2026-10-01T00:00:00+00:00', $this->config['diwoo_fields_introduced_at']);
		$this->assertStringContainsString('0 publication(s) moved', $this->info[1]);
	}//end testASecondRunMovesNothing()

	/**
	 * REQ-DWP-005: the step is a post-migration repair step after InitializeSettings.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredPostMigration(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$position = array_search('OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary', $steps, true);
		$this->assertIsInt($position);
		$this->assertGreaterThan(array_search('OCA\OpenCatalogi\Repair\InitializeSettings', $steps, true), $position);
	}//end testTheStepIsRegisteredPostMigration()
}//end class
