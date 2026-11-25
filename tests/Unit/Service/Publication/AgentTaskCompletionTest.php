<?php

/**
 * AgentTaskCompletionTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
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

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Service\Publication\AgentTaskActions;
use OCA\OpenCatalogi\Service\Publication\AgentTaskCompleted;
use OCA\OpenCatalogi\Service\Publication\AgentTaskCompletion;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDown;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDownRefused;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDownRequest;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The completion of a staged agent task: who may complete it, what it reads, and which action it takes.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
#[CoversClass(AgentTaskCompletion::class)]
#[CoversClass(AgentTaskActions::class)]
#[CoversClass(AgentTaskCompleted::class)]
class AgentTaskCompletionTest extends TestCase {

	/**
	 * The withdrawals the take-down was asked for.
	 *
	 * @var list<PublicationTakeDownRequest>
	 */
	private array $withdrawals = [];

	/**
	 * The archive transitions run.
	 *
	 * @var list<string>
	 */
	private array $archived = [];

	/**
	 * A task as OpenRegister serialises it, staged by AgentWithdrawal and completed by `publisher1`.
	 *
	 * @param array<string, mixed> $override Fields to replace.
	 *
	 * @return array<string, mixed> The task.
	 */
	private static function task(array $override = []): array {
		return array_replace(
			[
				'uuid'        => 'task-1',
				'appId'       => 'opencatalogi',
				'kind'        => 'approval',
				'state'       => 'completed',
				'outcome'     => null,
				'completedBy' => 'publisher1',
				'metadata'    => ['action' => 'depublish', 'publication' => 'pub-1', 'reason' => 'Rechterlijke uitspraak'],
			],
			$override
		);
	}//end task()

	/**
	 * The service for a session user, rights, a readable publication or not, and a take-down that may refuse.
	 *
	 * @param string|null $user       The signed-in user, or null.
	 * @param array       $rights     `publish` and `update`.
	 * @param bool        $readable   Whether the publication reads.
	 * @param string|null $takeDownNo The take-down's refusal, or null.
	 *
	 * @return AgentTaskCompletion The service.
	 */
	private function service(
		?string $user = 'publisher1',
		array $rights = ['publish' => true, 'update' => true],
		bool $readable = true,
		?string $takeDownNo = null
	): AgentTaskCompletion {
		$objects = new class($readable) {
			/**
			 * Constructor.
			 *
			 * @param bool $readable Whether the publication reads.
			 */
			public function __construct(private bool $readable) {
			}

			/**
			 * @param string $id      The id.
			 * @param mixed  $options The register and schema.
			 * @param bool   $_rbac   Whether RBAC applies.
			 *
			 * @return object
			 */
			public function find(string $id, mixed $options, bool $_rbac = false): object {
				if ($this->readable === false || $_rbac === false) {
					throw new RuntimeException(message: 'Object not found');
				}

				return new class($id) {
					/**
					 * Constructor.
					 *
					 * @param string $id The id.
					 */
					public function __construct(private string $id) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => $this->id, 'title' => 'Besluit', 'status' => 'published'];
					}
				};
			}
		};
		$openRegister = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$openRegister->method('objectService')->willReturn($objects);
		$openRegister->method('registerConfiguration')->willReturn(['register' => '7', 'schema' => '12']);

		$may = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$may->method('mayPublish')->willReturn($rights['publish']);
		$may->method('mayUpdate')->willReturn($rights['update']);

		$takeDown = $this->createMock(originalClassName: PublicationTakeDown::class);
		$takeDown->method('withdraw')->willReturnCallback(
			function (PublicationTakeDownRequest $request) use ($takeDownNo): array {
				if ($takeDownNo !== null) {
					throw new PublicationTakeDownRefused(reason: $takeDownNo, state: 'withdrawn');
				}

				$this->withdrawals[] = $request;
				return ['state' => 'withdrawn'];
			}
		);
		$transition = $this->createMock(originalClassName: PublishTransition::class);
		$transition->method('archive')->willReturnCallback(
			function (string $id): object {
				if ($id === 'pub-locked') {
					throw new RuntimeException(message: 'transition not allowed');
				}

				$this->archived[] = $id;
				return (object)['id' => $id];
			}
		);

		$signedIn = null;
		if ($user !== null) {
			$signedIn = $this->createConfiguredStub(originalClassName: IUser::class, configuration: ['getUID' => $user]);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($signedIn);

		return new AgentTaskCompletion(
			openRegister: $openRegister,
			rights: $may,
			actions: new AgentTaskActions(takeDown: $takeDown, transition: $transition),
			session: $session
		);
	}//end service()

	/**
	 * Only a task this app staged, completed with an approving outcome, with a known action and a publication, reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testOnlyACompletedStagedTaskReads(): void {
		$done = AgentTaskCompleted::fromTask(task: self::task());
		$this->assertSame(
			expected: ['depublish', 'pub-1', 'Rechterlijke uitspraak', 'publisher1'],
			actual: [$done?->action, $done?->publication, $done?->reason, $done?->completedBy]
		);
		$this->assertNotNull(actual: AgentTaskCompleted::fromTask(task: self::task(override: ['outcome' => 'approved'])));

		$others = [
			['appId' => 'dossiq'],
			['kind' => 'review'],
			['state' => 'terminated'],
			['outcome' => 'rejected'],
			['metadata' => ['action' => 'publish', 'publication' => 'pub-1']],
			['metadata' => ['action' => 'archive', 'publication' => '']],
			['metadata' => 'not a map'],
		];
		foreach ($others as $override) {
			$this->assertNull(actual: AgentTaskCompleted::fromTask(task: self::task(override: $override)), message: json_encode($override));
		}
	}//end testOnlyACompletedStagedTaskReads()

	/**
	 * The completing publisher's session withdraws the publication with the task's reason, through the take-down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testThePublishersCompletionWithdraws(): void {
		$outcome = $this->service()->complete(done: AgentTaskCompleted::fromTask(task: self::task()));

		$this->assertSame(expected: 'withdrawn', actual: $outcome);
		$this->assertCount(expectedCount: 1, haystack: $this->withdrawals);
		$this->assertSame(expected: 'pub-1', actual: $this->withdrawals[0]->id);
		$this->assertSame(expected: 'Rechterlijke uitspraak', actual: $this->withdrawals[0]->why->reason);
		$this->assertSame(expected: 'publisher1', actual: $this->withdrawals[0]->why->actor);
		$this->assertSame(expected: 'published', actual: $this->withdrawals[0]->properties['status']);
	}//end testThePublishersCompletionWithdraws()

	/**
	 * An archive task runs the lifecycle's archive transition; one the lifecycle refuses is `not_archived`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testAnArchiveTaskArchives(): void {
		$archive = static fn (string $id): AgentTaskCompleted => AgentTaskCompleted::fromTask(
			task: self::task(override: ['metadata' => ['action' => 'archive', 'publication' => $id, 'reason' => 'R']])
		);

		$this->assertSame(expected: 'archived', actual: $this->service()->complete(done: $archive('pub-2')));
		$this->assertSame(expected: 'not_archived', actual: $this->service()->complete(done: $archive('pub-locked')));
		$this->assertSame(expected: ['pub-2'], actual: $this->archived);
		$this->assertSame(expected: [], actual: $this->withdrawals);
	}//end testAnArchiveTaskArchives()

	/**
	 * Another session, no session, a publication the completer cannot read, no publish right or no update right:
	 * nothing is taken, and the reason is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testNothingIsTakenWithoutTheCompletersRights(): void {
		$done = AgentTaskCompleted::fromTask(task: self::task());

		$this->assertSame(expected: 'not_the_completer', actual: $this->service(user: 'someone-else')->complete(done: $done));
		$this->assertSame(expected: 'not_the_completer', actual: $this->service(user: null)->complete(done: $done));
		$this->assertSame(expected: 'not_found', actual: $this->service(readable: false)->complete(done: $done));
		$this->assertSame(
			expected: 'not_a_publisher',
			actual: $this->service(rights: ['publish' => false, 'update' => true])->complete(done: $done)
		);
		$this->assertSame(
			expected: 'forbidden',
			actual: $this->service(rights: ['publish' => true, 'update' => false])->complete(done: $done)
		);
		$this->assertSame(expected: [], actual: $this->withdrawals);
	}//end testNothingIsTakenWithoutTheCompletersRights()

	/**
	 * A take-down that stops (no longer public, refused save, unstored record) answers its own reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testAStoppedTakeDownAnswersItsReason(): void {
		$done = AgentTaskCompleted::fromTask(task: self::task());
		foreach ([PublicationTakeDownRefused::WRONG_STATE, PublicationTakeDownRefused::NOT_SAVED, PublicationTakeDownRefused::UNSTORED] as $reason) {
			$this->assertSame(expected: $reason, actual: $this->service(takeDownNo: $reason)->complete(done: $done));
		}
	}//end testAStoppedTakeDownAnswersItsReason()
}//end class
