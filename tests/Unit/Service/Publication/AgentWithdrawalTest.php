<?php

/**
 * AgentWithdrawalTest
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

use OCA\OpenCatalogi\Contract\AgentWithdrawalCall;
use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Service\Publication\AgentWithdrawal;
use OCA\OpenCatalogi\Service\Publication\AgentWithdrawalRequest;
use OCA\OpenCatalogi\Service\Publication\ObjectProperties;
use OCA\OpenCatalogi\Service\Publication\OpenRegisterTasks;
use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The staging service over doubles of OpenRegister's object service and task manager, the rights and the states.
 *
 * Every test also asserts that nothing about a publication was saved: the
 * object double has no save method at all, so a write would fail the test.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
#[CoversClass(AgentWithdrawal::class)]
#[CoversClass(AgentWithdrawalRequest::class)]
#[CoversClass(OpenRegisterTasks::class)]
#[CoversClass(ObjectProperties::class)]
class AgentWithdrawalTest extends TestCase {

	/**
	 * The stored publications by id: `title`, `_state` and `_mayUpdate`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * The find calls, with their RBAC flag.
	 *
	 * @var list<array{id: string, rbac: bool}>
	 */
	public array $finds = [];

	/**
	 * The tasks created: the payload and the actor.
	 *
	 * @var list<array{data: array<string, mixed>, actor: ?string}>
	 */
	public array $created = [];

	/**
	 * Ids whose task creation throws.
	 *
	 * @var list<string>
	 */
	public array $failing = [];

	/**
	 * A stored publication as OpenRegister's find answers it, or an exception for an unknown or unreadable one.
	 *
	 * @param string $id   The id.
	 * @param bool   $rbac Whether RBAC applies.
	 *
	 * @return object The publication.
	 */
	public function found(string $id, bool $rbac): object {
		$this->finds[] = ['id' => $id, 'rbac' => $rbac];
		if (isset($this->stored[$id]) === false) {
			throw new RuntimeException(message: 'Object not found');
		}

		return (object)($this->stored[$id] + ['id' => $id]);
	}//end found()

	/**
	 * Record a task the service creates, and answer it with its uuid.
	 *
	 * @param array<string, mixed> $data  The task payload.
	 * @param string|null          $actor The creating identity.
	 *
	 * @return object The created task.
	 */
	public function createTask(array $data, ?string $actor): object {
		if (in_array($data['objectUuid'], $this->failing, true) === true) {
			throw new RuntimeException(message: 'refused');
		}

		$this->created[] = ['data' => $data, 'actor' => $actor];
		if ($data['objectUuid'] === 'pub-3') {
			return new \stdClass();
		}

		return new class('task-' . $data['objectUuid']) {
			/**
			 * Constructor.
			 *
			 * @param string $uuid The task uuid.
			 */
			public function __construct(private string $uuid) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => $this->uuid, 'id' => 7];
			}
		};
	}//end createTask()

	/**
	 * The service, with OpenRegister's tasks available or not, a signed-in user or not, and a register or not.
	 *
	 * @param bool   $tasks      Whether OpenRegister offers its task manager.
	 * @param bool   $signedIn   Whether the call has a user.
	 * @param string $configured The configured register and schema, or `throws`.
	 *
	 * @return AgentWithdrawal The service.
	 */
	private function service(bool $tasks = true, bool $signedIn = true, string $configured = 'publication'): AgentWithdrawal {
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param AgentWithdrawalTest $test The test holding the stored rows.
			 */
			public function __construct(private AgentWithdrawalTest $test) {
			}

			/**
			 * @param string $id      The id.
			 * @param mixed  $options The register and schema.
			 * @param bool   $_rbac   Whether RBAC applies.
			 *
			 * @return object
			 */
			public function find(string $id, mixed $options, bool $_rbac = false): object {
				return $this->test->found(id: $id, rbac: $_rbac);
			}
		};
		$openRegister = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$openRegister->method('objectService')->willReturn($objects);
		if ($configured === 'throws') {
			$openRegister->method('registerConfiguration')->willThrowException(new RuntimeException(message: 'not set'));
		} else {
			$openRegister->method('registerConfiguration')->willReturn(['register' => $configured, 'schema' => $configured]);
		}

		$rights = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$rights->method('mayUpdate')->willReturnCallback(static fn (object $publication): bool => (($publication->_mayUpdate ?? true) === true));

		$states = $this->createMock(originalClassName: PublicationStateServiceInterface::class);
		$states->method('stateOf')->willReturnCallback(static fn (array $publication): string => (string)$publication['_state']);

		$manager = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param AgentWithdrawalTest $test The test recording the tasks.
			 */
			public function __construct(private AgentWithdrawalTest $test) {
			}

			/**
			 * @param array<string, mixed> $data  The payload.
			 * @param string|null          $actor The actor.
			 *
			 * @return object
			 */
			public function create(array $data, ?string $actor): object {
				return $this->test->createTask(data: $data, actor: $actor);
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($tasks === true) {
			$container->method('get')->with(OpenRegisterTasks::TASKS)->willReturn($manager);
		} else {
			$container->method('get')->willThrowException(new RuntimeException(message: 'not installed'));
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createConfiguredStub(originalClassName: IUser::class, configuration: ['getUID' => 'redacteur']);
		}

		$session->method('getUser')->willReturn($user);

		return new AgentWithdrawal(
			openRegister: $openRegister,
			rights: $rights,
			states: $states,
			tasks: new OpenRegisterTasks(container: $container, session: $session)
		);
	}//end service()

	/**
	 * A public publication gets one task for the publishers group, on the publication, carrying the reason; a
	 * withdrawal of a publication that is not public, one the caller may not change, and an unknown one are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testAWithdrawalWaitsForAPublisher(): void {
		$this->stored = [
			'pub-1' => ['title' => 'Besluit parkeren', '_state' => PublicationStateServiceInterface::STATE_PUBLIC],
			'pub-2' => ['title' => 'Concept', '_state' => 'draft'],
			'pub-3' => ['title' => 'Van een ander', '_state' => PublicationStateServiceInterface::STATE_PUBLIC, '_mayUpdate' => false],
			'pub-4' => ['title' => '', '_state' => PublicationStateServiceInterface::STATE_SCHEDULED],
		];

		$answer = $this->service()->stage(call: new AgentWithdrawalCall(
			action: AgentWithdrawalInterface::DEPUBLISH,
			ids: ['pub-1', 'pub-2', 'pub-3', 'pub-4', 'pub-9'],
			reason: 'Rechterlijke uitspraak'
		));

		$this->assertSame(
			expected: [
				'staged'  => [['id' => 'pub-1', 'task' => 'task-pub-1'], ['id' => 'pub-4', 'task' => 'task-pub-4']],
				'refused' => [
					['id' => 'pub-2', 'code' => 'not_public'],
					['id' => 'pub-3', 'code' => 'forbidden'],
					['id' => 'pub-9', 'code' => 'not_found'],
				],
			],
			actual: $answer
		);
		$this->assertSame(
			expected: [
				'title'           => 'Withdraw publication "Besluit parkeren"',
				'description'     => 'Rechterlijke uitspraak',
				'kind'            => 'approval',
				'appId'           => 'opencatalogi',
				'objectUuid'      => 'pub-1',
				'candidateGroups' => [PublishRight::GROUP],
				'metadata'        => [
					'action'      => 'depublish',
					'publication' => 'pub-1',
					'reason'      => 'Rechterlijke uitspraak',
					'requestedBy' => 'redacteur',
					'tool'        => 'opencatalogi.depublishPublication',
				],
			],
			actual: $this->created[0]['data']
		);
		$this->assertSame(expected: 'redacteur', actual: $this->created[0]['actor']);
		$this->assertSame(expected: 'Withdraw publication "pub-4"', actual: $this->created[1]['data']['title']);
		$this->assertSame(expected: [true], actual: array_values(array_unique(array_column($this->finds, 'rbac'))));
	}//end testAWithdrawalWaitsForAPublisher()

	/**
	 * An archive request stages a task for any publication that is not archived yet, and refuses an archived one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testAnArchiveRequestWaitsForAPublisher(): void {
		$this->stored = [
			'pub-1' => ['title' => 'Jaarverslag 2019', '_state' => PublicationStateServiceInterface::STATE_WITHDRAWN],
			'pub-2' => ['title' => 'Oud', '_state' => PublicationStateServiceInterface::STATE_ARCHIVED],
		];

		$answer = $this->service()->stage(call: new AgentWithdrawalCall(
			action: AgentWithdrawalInterface::ARCHIVE,
			ids: ['pub-1', 'pub-2'],
			reason: 'Bewaartermijn verstreken'
		));

		$this->assertSame(expected: [['id' => 'pub-1', 'task' => 'task-pub-1']], actual: $answer['staged']);
		$this->assertSame(expected: [['id' => 'pub-2', 'code' => 'already_archived']], actual: $answer['refused']);
		$this->assertSame(expected: 'Archive publication "Jaarverslag 2019"', actual: $this->created[0]['data']['title']);
		$this->assertSame(expected: 'opencatalogi.archivePublication', actual: $this->created[0]['data']['metadata']['tool']);
	}//end testAnArchiveRequestWaitsForAPublisher()

	/**
	 * A task OpenRegister refuses, or answers without a uuid, is `not_staged` and the other items go on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testARefusedTaskIsNotStagedAndTheRestGoesOn(): void {
		$this->stored = [
			'pub-1' => ['title' => 'A', '_state' => PublicationStateServiceInterface::STATE_PUBLIC],
			'pub-2' => ['title' => 'B', '_state' => PublicationStateServiceInterface::STATE_PUBLIC],
			'pub-3' => ['title' => 'C', '_state' => PublicationStateServiceInterface::STATE_PUBLIC],
		];
		$this->failing = ['pub-1'];

		$answer = $this->service()->stage(call: new AgentWithdrawalCall(
			action: AgentWithdrawalInterface::DEPUBLISH,
			ids: ['pub-1', 'pub-2', 'pub-3'],
			reason: 'R'
		));

		$this->assertSame(expected: [['id' => 'pub-1', 'code' => 'not_staged'], ['id' => 'pub-3', 'code' => 'not_staged']], actual: $answer['refused']);
		$this->assertSame(expected: [['id' => 'pub-2', 'task' => 'task-pub-2']], actual: $answer['staged']);
	}//end testARefusedTaskIsNotStagedAndTheRestGoesOn()

	/**
	 * Without OpenRegister's tasks, without a user, with an unknown action or without a register, nothing is staged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testWithoutTasksAUserOrARegisterNothingIsStaged(): void {
		$this->stored = ['pub-1' => ['title' => 'A', '_state' => PublicationStateServiceInterface::STATE_PUBLIC]];

		$this->assertSame(
			expected: ['error' => 'tasks_unavailable'],
			actual: $this->service(tasks: false)->stage(call: new AgentWithdrawalCall(
				action: AgentWithdrawalInterface::DEPUBLISH,
				ids: ['pub-1'],
				reason: 'R'
			))
		);
		$this->assertSame(
			expected: ['error' => 'tasks_unavailable'],
			actual: $this->service(signedIn: false)->stage(call: new AgentWithdrawalCall(
				action: AgentWithdrawalInterface::DEPUBLISH,
				ids: ['pub-1'],
				reason: 'R'
			))
		);
		$this->assertSame(
			expected: ['error' => 'tasks_unavailable'],
			actual: $this->service()->stage(call: new AgentWithdrawalCall(action: 'publish', ids: ['pub-1'], reason: 'R'))
		);
		foreach (['', 'throws'] as $configured) {
			$this->assertSame(
				expected: ['error' => 'not_configured'],
				actual: $this->service(configured: $configured)->stage(call: new AgentWithdrawalCall(
					action: AgentWithdrawalInterface::ARCHIVE,
					ids: ['pub-1'],
					reason: 'R'
				))
			);
		}

		$this->assertSame(expected: [], actual: $this->created);
		$this->assertSame(expected: [], actual: $this->finds);
	}//end testWithoutTasksAUserOrARegisterNothingIsStaged()

	/**
	 * OpenRegister's own task builder accepts the payload: an available task, offered to the publishers group, on
	 * the publication, with the action in its metadata. Skipped when OpenRegister is not beside the app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testOpenRegistersTaskBuilderAcceptsThePayload(): void {
		$builder = 'OCA\OpenRegister\Service\Task\TaskBuilder';
		if (class_exists($builder) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed beside this app, so its task builder cannot run.');
		}

		$request = new AgentWithdrawalRequest(action: AgentWithdrawalInterface::DEPUBLISH, id: 'pub-1', reason: 'Uitspraak', actor: 'redacteur');
		$task    = (new $builder())->fromData($request->taskData(title: 'Besluit'), 'redacteur')->jsonSerialize();

		$this->assertSame(expected: 'available', actual: $task['state']);
		$this->assertSame(expected: [PublishRight::GROUP], actual: $task['candidateGroups']);
		$this->assertSame(expected: 'pub-1', actual: $task['objectUuid']);
		$this->assertSame(expected: 'depublish', actual: $task['metadata']['action']);
	}//end testOpenRegistersTaskBuilderAcceptsThePayload()
}//end class
