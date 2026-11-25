<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\FinishUpgradeAfterOpenRegister;
use OCA\OpenCatalogi\Repair\LoggedRepairOutput;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The job that finishes this app's upgrade once OpenRegister is current (REQ-PLC-007).
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */
class FinishUpgradeAfterOpenRegisterTest extends TestCase {

	/**
	 * A repair step that records its run in `$order` and may throw.
	 *
	 * @param list<string> $order  The runs, in order.
	 * @param string       $name   The step's name.
	 * @param boolean      $throws Whether the step fails.
	 *
	 * @return IRepairStep The step.
	 */
	private static function step(array &$order, string $name, bool $throws=false): IRepairStep {
		return new class($order, $name, $throws) implements IRepairStep {

			/**
			 * Constructor.
			 *
			 * @param list<string> $order  The runs.
			 * @param string       $name   The name.
			 * @param boolean      $throws Whether to fail.
			 */
			public function __construct(private array &$order, private readonly string $name, private readonly bool $throws) {
			}

			/**
			 * The name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * Record the run, or fail.
			 *
			 * @param IOutput $output Where it reports.
			 *
			 * @return void
			 */
			public function run(IOutput $output): void {
				if ($this->throws === true) {
					throw new \RuntimeException($this->name . ' failed');
				}

				$this->order[] = $this->name;
			}
		};
	}//end step()

	/**
	 * Run the job with the given OpenRegister state and steps.
	 *
	 * @param boolean                     $orWaits Whether OpenRegister still waits for its upgrade.
	 * @param array<string, IRepairStep>  $steps   The declared post-migration steps.
	 * @param IJobList                    $jobs    The job list.
	 * @param LoggerInterface|null        $logger  The logger.
	 *
	 * @return void
	 */
	private function runJob(bool $orWaits, array $steps, IJobList $jobs, ?LoggerInterface $logger=null): void {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isUpgradeRequired')->with('openregister')->willReturn($orWaits);
		$apps->method('getAppInfo')->willReturn(['repair-steps' => ['post-migration' => array_keys($steps)]]);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				IJobList::class => $jobs,
				default => $steps[$id],
			}
		);

		$job = new FinishUpgradeAfterOpenRegister(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			appManager: $apps,
			container: $container,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
		$run = new \ReflectionMethod(objectOrMethod: $job, method: 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * Once OpenRegister is current, the post-migration steps run in their declared order.
	 *
	 * @return void
	 */
	public function testTheStepsRunInTheirDeclaredOrderOnceOpenRegisterIsCurrent(): void {
		$order = [];
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->never())->method('add');

		$this->runJob(
			orWaits: false,
			steps: ['Initialize' => self::step(order: $order, name: 'Initialize'), 'Backfill' => self::step(order: $order, name: 'Backfill')],
			jobs: $jobs
		);

		$this->assertSame(expected: ['Initialize', 'Backfill'], actual: $order);
	}//end testTheStepsRunInTheirDeclaredOrderOnceOpenRegisterIsCurrent()

	/**
	 * While OpenRegister still waits, nothing runs and the job queues itself again.
	 *
	 * @return void
	 */
	public function testWhileOpenRegisterWaitsTheJobQueuesItselfAgain(): void {
		$order = [];
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->once())->method('add')->with(FinishUpgradeAfterOpenRegister::class);

		$this->runJob(orWaits: true, steps: ['Initialize' => self::step(order: $order, name: 'Initialize')], jobs: $jobs);

		$this->assertSame(expected: [], actual: $order);
	}//end testWhileOpenRegisterWaitsTheJobQueuesItselfAgain()

	/**
	 * A failing step is logged and the next one still runs.
	 *
	 * @return void
	 */
	public function testAFailingStepIsLoggedAndTheNextStillRuns(): void {
		$order = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains(string: 'repair step Initialize failed'));

		$this->runJob(
			orWaits: false,
			steps: ['Initialize' => self::step(order: $order, name: 'Initialize', throws: true), 'Backfill' => self::step(order: $order, name: 'Backfill')],
			jobs: $this->createMock(originalClassName: IJobList::class),
			logger: $logger
		);

		$this->assertSame(expected: ['Backfill'], actual: $order);
	}//end testAFailingStepIsLoggedAndTheNextStillRuns()

	/**
	 * A step's lines go to the log.
	 *
	 * @return void
	 */
	public function testTheOutputWritesTheStepsLinesToTheLog(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('2 publication(s) could not be saved');
		$logger->expects($this->once())->method('info')->with('Configuration loaded');

		$output = new LoggedRepairOutput(logger: $logger);
		$output->startProgress(max: 2);
		$output->info(message: 'Configuration loaded');
		$output->warning(message: '2 publication(s) could not be saved');
		$output->advance(step: 2);
		$output->finishProgress();
	}//end testTheOutputWritesTheStepsLinesToTheLog()
}//end class
