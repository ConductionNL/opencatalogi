<?php

/**
 * Tests for MapStoredRefusalGrounds: earlier refusal ground codes mapped to dossiq's settled codes, once.
 *
 * @category Test
 * @package  Unit\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Repair\MapStoredRefusalGrounds;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister's object service is an in-memory store; the Woo service only
 * names the register and schema.
 *
 * @covers \OCA\OpenCatalogi\Repair\MapStoredRefusalGrounds
 */
class MapStoredRefusalGroundsTest extends TestCase {

	/**
	 * The store: uuid to assessment.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The lines the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * How many saves the store took.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Run the step over the store.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$test = $this;
		$objects = new class ($test) {
			/**
			 * @param MapStoredRefusalGroundsTest $test The test holding the store.
			 */
			public function __construct(private readonly MapStoredRefusalGroundsTest $test) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array{results: array<int, array<string, mixed>>}
			 */
			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->assertSame(['register' => 'reg-woo', 'schema' => 'schema-assessment'], $query['@self']);
				$this->test->assertFalse($_rbac);
				if ($query['_page'] > 1) {
					return ['results' => []];
				}

				return ['results' => array_values($this->test->rows())];
			}

			/**
			 * @param array<string, mixed> $object  The object.
			 * @param mixed                $options The options.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->assertSame('reg-woo', $options->target->register);
				$this->test->assertSame('schema-assessment', $options->target->schema);
				$this->test->saved(object: $object);
				return $object;
			}
		};

		$woo = $this->createMock(TransparencyWorkflowServiceInterface::class);
		$woo->method('getObjectService')->willReturn($objects);
		$woo->method('getRegister')->willReturn('reg-woo');
		$woo->method('getAssessmentSchema')->willReturn('schema-assessment');

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->lines[] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->lines[] = $line;
		});

		(new MapStoredRefusalGrounds($woo, $this->createMock(LoggerInterface::class)))->run($output);
	}//end run()

	/**
	 * The store's rows, for the double.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function rows(): array {
		return $this->store;
	}//end rows()

	/**
	 * Record a save, for the double.
	 *
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return void
	 */
	public function saved(array $object): void {
		$this->saves++;
		$this->store[(string)$object['id']] = $object;
	}//end saved()

	/**
	 * A known earlier code is mapped by meaning; an unchanged one stays; the marker is set.
	 *
	 * @return void
	 */
	public function testAKnownCodeIsMapped(): void {
		$this->store = ['a1' => ['id' => 'a1', 'assessment' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.f', '5.1.2.e', '5.2.a']]];

		$this->runStep();

		$this->assertSame(['5.1.2.g', '5.1.2.e', '5.2.1'], $this->store['a1']['weigeringsgronden']);
		$this->assertSame([], $this->store['a1']['unmappedGrounds']);
		$this->assertSame(RefusalGrounds::SETTLED_LIST_VERSION, $this->store['a1']['groundsListVersion']);
		$this->assertStringContainsString('1 assessment(s) mapped, 0 with a code that could not be mapped', $this->lines[0]);
	}//end testAKnownCodeIsMapped()

	/**
	 * A code without one meaning is kept, flagged on the assessment and listed in the output.
	 *
	 * @return void
	 */
	public function testAnUnmappableCodeIsFlaggedAndListed(): void {
		$this->store = ['a2' => ['id' => 'a2', 'assessment' => 'deels_openbaar', 'weigeringsgronden' => ['5.2.e', '5.1.2.i']]];

		$this->runStep();

		$this->assertSame(['5.2.e', '5.1.4'], $this->store['a2']['weigeringsgronden']);
		$this->assertSame(['5.2.e'], $this->store['a2']['unmappedGrounds']);
		$this->assertContains('MapStoredRefusalGrounds: assessment a2 keeps unmapped code(s) 5.2.e', $this->lines);
	}//end testAnUnmappableCodeIsFlaggedAndListed()

	/**
	 * A second run changes nothing, although 5.1.2.g is also an earlier code.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->store = [
			'a1' => ['id' => 'a1', 'weigeringsgronden' => ['5.1.2.f']],
			'a3' => ['id' => 'a3', 'weigeringsgronden' => []],
		];

		$this->runStep();
		$this->assertSame(1, $this->saves, 'an assessment without grounds is not rewritten');
		$this->assertSame(['5.1.2.g'], $this->store['a1']['weigeringsgronden']);

		$this->runStep();
		$this->assertSame(1, $this->saves);
		$this->assertSame(['5.1.2.g'], $this->store['a1']['weigeringsgronden']);
	}//end testASecondRunChangesNothing()

	/**
	 * The step is registered post-migration, after InitializeSettings.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredPostMigration(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$mine = array_search(MapStoredRefusalGrounds::class, $steps, true);
		$this->assertNotFalse($mine);
		$this->assertGreaterThan(array_search('OCA\\OpenCatalogi\\Repair\\InitializeSettings', $steps, true), $mine);
	}//end testTheStepIsRegisteredPostMigration()

	/**
	 * Without a configured assessment schema the step does nothing and says so.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemaNothingIsDone(): void {
		$woo = $this->createMock(TransparencyWorkflowServiceInterface::class);
		$woo->method('getObjectService')->willReturn(null);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('nothing to do'));

		(new MapStoredRefusalGrounds($woo, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutTheSchemaNothingIsDone()
}//end class
