<?php

/**
 * AgentTaskCompletionListenerTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\AgentTaskCompletionListener;
use OCA\OpenCatalogi\Service\Publication\AgentTaskCompleted;
use OCA\OpenCatalogi\Service\Publication\AgentTaskCompletion;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener on OpenRegister's real TaskTerminalEvent and Task: which ends it acts on, and what it logs.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
#[CoversClass(AgentTaskCompletionListener::class)]
class AgentTaskCompletionListenerTest extends TestCase {

	/**
	 * The log lines by level.
	 *
	 * @var array<string, list<string>>
	 */
	private array $log = ['info' => [], 'warning' => []];

	/**
	 * Skip without OpenRegister's task entity and event.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(Task::class) === false || class_exists(TaskTerminalEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed beside this app, so its task event cannot be built.');
		}
	}//end setUp()

	/**
	 * A completed task this app staged.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return Task The task.
	 */
	private static function task(string $outcome = 'done'): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setAppId('opencatalogi');
		$task->setKind('approval');
		$task->setState('completed');
		$task->setOutcome($outcome);
		$task->setCompletedBy('publisher1');
		$task->setMetadata(['action' => 'depublish', 'publication' => 'pub-1', 'reason' => 'Uitspraak']);

		return $task;
	}//end task()

	/**
	 * The listener over a completion that answers the given outcome, counting its calls.
	 *
	 * @param string $outcome The completion's answer.
	 * @param int    $calls   How often the completion must be asked.
	 *
	 * @return AgentTaskCompletionListener The listener.
	 */
	private function listener(string $outcome, int $calls): AgentTaskCompletionListener {
		$completion = $this->createMock(originalClassName: AgentTaskCompletion::class);
		$completion->expects($this->exactly(count: $calls))->method('complete')->willReturnCallback(
			static fn (AgentTaskCompleted $done): string => ($done->publication === 'pub-1' ? $outcome : 'wrong publication')
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $line): void {
			$this->log['info'][] = $line;
		});
		$logger->method('warning')->willReturnCallback(function (string $line): void {
			$this->log['warning'][] = $line;
		});

		return new AgentTaskCompletionListener(completion: $completion, logger: $logger);
	}//end listener()

	/**
	 * A committed completion of a staged task is taken and logged as info.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testACommittedCompletionIsTaken(): void {
		$this->listener(outcome: 'withdrawn', calls: 1)->handle(new TaskTerminalEvent(self::task()));

		$this->assertSame(expected: ['[AgentTaskCompletionListener] task task-1 (depublish) on publication pub-1: withdrawn'], actual: $this->log['info']);
		$this->assertSame(expected: [], actual: $this->log['warning']);
	}//end testACommittedCompletionIsTaken()

	/**
	 * A completion the service could not take is logged as a warning naming why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testAnUntakenCompletionIsAWarning(): void {
		$this->listener(outcome: 'not_a_publisher', calls: 1)->handle(new TaskTerminalEvent(self::task()));

		$this->assertStringEndsWith(suffix: ': not_a_publisher', string: $this->log['warning'][0]);
	}//end testAnUntakenCompletionIsAWarning()

	/**
	 * The in-transaction dispatch, a rejected task, and any other event are ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testOtherEndsAndEventsAreIgnored(): void {
		$listener = $this->listener(outcome: 'withdrawn', calls: 0);
		$listener->handle(new TaskTerminalEvent(self::task(), false));
		$listener->handle(new TaskTerminalEvent(self::task(outcome: 'rejected')));
		$listener->handle(new Event());

		$this->assertSame(expected: ['info' => [], 'warning' => []], actual: $this->log);
	}//end testOtherEndsAndEventsAreIgnored()
}//end class
