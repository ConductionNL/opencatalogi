<?php

/**
 * BatchStatusRepairTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Repair;

use OCA\OpenCatalogi\Repair\BatchStatusRepair;
use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stored batch statuses are mapped onto the lifecycle states, once.
 *
 * @covers \OCA\OpenCatalogi\Repair\BatchStatusRepair
 */
final class BatchStatusRepairTest extends TestCase {

	/**
	 * A fake ObjectService over a list of batches, recording each save.
	 *
	 * @param array<int, array<string, mixed>> $batches The stored batches.
	 *
	 * @return object
	 */
	private static function store(array $batches): object {
		return new class($batches) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			public array $reads = [];

			public function __construct(
				public array $batches,
			) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return $this->batches;
			}

			public function saveObject(array $object, mixed ...$rest): array {
				$this->saved[] = $object;
				foreach ($this->batches as $i => $batch) {
					if ($batch['id'] === $object['id']) {
						$this->batches[$i] = $object;
					}
				}

				return $object;
			}
		};
	}//end store()

	/**
	 * The repair over the store, with the register and schema configured.
	 *
	 * @param object|null $store The fake ObjectService, or null for no OpenRegister.
	 *
	 * @return BatchStatusRepair
	 */
	private function repair(?object $store): BatchStatusRepair {
		$woo = $this->createMock(TransparencyWorkflowServiceInterface::class);
		$woo->method('getObjectService')->willReturn($store);
		$woo->method('getRegister')->willReturn('1');
		$woo->method('getBatchSchema')->willReturn('batch-sch');

		return new BatchStatusRepair($woo, $this->createMock(LoggerInterface::class));
	}//end repair()

	/**
	 * An unknown or missing status becomes in_progress; known states are left; a second run writes nothing.
	 *
	 * @return void
	 */
	public function testUnknownStatusesBecomeInProgressOnce(): void {
		$store = self::store(
			[
				['id' => 'b-1', 'status' => 'concept'],
				['id' => 'b-2'],
				['id' => 'b-3', 'status' => 'ready_for_review'],
				['id' => 'b-4', 'status' => 'approved'],
				['id' => 'b-5', 'status' => 'published'],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('info')->willReturnCallback(static function (string $message): void {
		});

		$this->repair($store)->run($output);
		$this->assertSame(['b-1', 'b-2'], array_column($store->saved, 'id'));
		$this->assertSame(['in_progress', 'in_progress'], array_column($store->saved, 'status'));
		$this->assertSame(['in_progress', 'in_progress', 'ready_for_review', 'approved', 'published'], array_column($store->batches, 'status'));

		$this->repair($store)->run($output);
		$this->assertCount(2, $store->saved, 'A second run writes nothing.');
		// OpenRegister main's API: the access flags are arguments, not config keys.
		$this->assertSame([['limit' => 10000], false, false], $store->reads[0]);
	}//end testUnknownStatusesBecomeInProgressOnce()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('nothing to map'));

		$this->repair(null)->run($output);
	}//end testWithoutOpenRegisterNothingHappens()

	/**
	 * The step is registered after InitializeSettings, which imports the lifecycle.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredAfterTheRegisterImport(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$init = strpos($info, '<step>OCA\OpenCatalogi\Repair\InitializeSettings</step>');
		$step = strpos($info, '<step>' . BatchStatusRepair::class . '</step>');

		$this->assertNotFalse($step);
		$this->assertGreaterThan($init, $step);
		$this->assertNotSame('', $this->repair(null)->getName());
	}//end testTheStepIsRegisteredAfterTheRegisterImport()
}//end class
