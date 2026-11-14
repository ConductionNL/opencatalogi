<?php

/**
 * Unit tests for PlainLanguageDrafter: a drafted plain summary is a suggestion
 * an officer accepts (REQ-PPL-002).
 *
 * The TaskProcessing manager is a double of the real OCP interface, and the
 * tasks it hands back are real OCP Task objects.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
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

namespace Unit\Service\Publication;

use DateTime;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter
 * @covers \OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal
 * @uses \OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance
 */
class PlainLanguageDrafterTest extends TestCase {

	private const ID = '7c1f2a8e-0000-4000-8000-000000000001';

	private IManager&MockObject $tasks;

	private ObjectService&MockObject $objects;

	private PublicationRights&MockObject $rights;

	private PlainSummaryProvenance $provenance;

	private ?string $uid = 'officer';

	/** @var array<int, Task> */
	private array $stored = [];

	/** @var array<int, array<string, mixed>> */
	private array $saves = [];

	private bool $providerInstalled = true;

	protected function setUp(): void {
		$this->tasks = $this->createMock(IManager::class);
		$this->tasks->method('getAvailableTaskTypes')->willReturnCallback(
			fn (): array => $this->providerInstalled ? [PlainLanguageDrafter::TASK_TYPE => ['name' => 'Simplify']] : ['core:text2text' => ['name' => 'Free prompt']]
		);
		$provider = $this->createMock(IProvider::class);
		$provider->method('getId')->willReturn('hermiq');
		$this->tasks->method('getPreferredProvider')->willReturn($provider);
		$this->tasks->method('getTask')->willReturnCallback(
			function (int $id): Task {
				if (isset($this->stored[$id]) === false) {
					throw new \OCP\TaskProcessing\Exception\NotFoundException('no task');
				}

				return $this->stored[$id];
			}
		);

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid(self::ID);
				$entity->setObject(
					[
						'title' => 'Besluit op bezwaar parkeervergunning',
						'summary' => 'Beslissing op bezwaar',
						'description' => '<p>Het bezwaar is <b>ongegrond</b> verklaard.</p>',
					]
				);
				return $entity;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac];
				return new ObjectEntity();
			}
		);

		$this->rights = $this->createMock(PublicationRights::class);
		$this->rights->method('mayUpdate')->willReturn(true);
	}

	private function drafter(): PlainLanguageDrafter {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->uid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T10:00:00+00:00'));
		$this->provenance = new PlainSummaryProvenance($session, $time);

		$openRegister = $this->createMock(OpenRegisterLookupInterface::class);
		$openRegister->method('objectService')->willReturn($this->objects);
		$openRegister->method('registerConfiguration')->willReturn(['register' => '19', 'schema' => '7']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PlainLanguageDrafter(
			$this->tasks,
			$openRegister,
			$this->rights,
			$this->provenance,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A finished, failed or running draft task of a publication for a user.
	 */
	private function task(int $id, int $status, ?string $output = null, string $publication = self::ID, string $user = 'officer'): Task {
		$task = new Task(PlainLanguageDrafter::TASK_TYPE, ['input' => 'x'], 'opencatalogi', $user, 'opencatalogi-plain-summary:' . $publication . ':hermiq');
		$task->setId($id);
		$task->setStatus($status);
		if ($output !== null) {
			$task->setOutput(['output' => $output]);
		}

		$this->stored[$id] = $task;
		return $task;
	}

	private function refusalOf(callable $call): PlainSummaryRefusal {
		try {
			$call();
		} catch (PlainSummaryRefusal $refusal) {
			return $refusal;
		}

		$this->fail('No refusal was raised.');
	}

	public function testWithoutAProviderTheDraftRouteAnswers409(): void {
		$this->providerInstalled = false;
		$this->tasks->expects($this->never())->method('scheduleTask');

		$refusal = $this->refusalOf(fn () => $this->drafter()->draft(self::ID));

		$this->assertSame('no-provider', $refusal->reason);
		$this->assertSame(409, $refusal->status);
		$this->assertFalse($this->drafter()->isAvailable());
	}

	public function testADraftIsScheduledWithTheTextFields(): void {
		$scheduled = null;
		$this->tasks->expects($this->once())->method('scheduleTask')->willReturnCallback(
			function (Task $task) use (&$scheduled): void {
				$task->setId(42);
				$scheduled = $task;
			}
		);

		$answer = $this->drafter()->draft(self::ID);

		$this->assertSame(['taskId' => 42], $answer);
		$this->assertSame(PlainLanguageDrafter::TASK_TYPE, $scheduled->getTaskTypeId());
		$this->assertSame(
			['input' => "Besluit op bezwaar parkeervergunning\n\nBeslissing op bezwaar\n\nHet bezwaar is ongegrond verklaard."],
			$scheduled->getInput()
		);
		$this->assertSame('opencatalogi', $scheduled->getAppId());
		$this->assertSame('officer', $scheduled->getUserId());
		$this->assertSame('opencatalogi-plain-summary:' . self::ID . ':hermiq', $scheduled->getCustomId());
		$this->assertSame([], $this->saves, 'Asking for a draft stores nothing.');
	}

	public function testADraftNeedsTheUpdateRight(): void {
		$this->rights = $this->createMock(PublicationRights::class);
		$this->rights->method('mayUpdate')->willReturn(false);
		$this->tasks->expects($this->never())->method('scheduleTask');

		$refusal = $this->refusalOf(fn () => $this->drafter()->draft(self::ID));

		$this->assertSame(403, $refusal->status);
		$this->assertSame('forbidden', $refusal->reason);
	}

	public function testADraftNeedsASession(): void {
		$this->uid = null;
		$this->tasks->expects($this->never())->method('scheduleTask');

		$this->assertSame(401, $this->refusalOf(fn () => $this->drafter()->draft(self::ID))->status);
	}

	public function testAnUnreadablePublicationAnswers404(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->tasks->expects($this->never())->method('scheduleTask');

		$this->assertSame(404, $this->refusalOf(fn () => $this->drafter()->draft(self::ID))->status);
	}

	public function testAProviderThatRefusesTheTaskAnswers502(): void {
		$this->tasks->method('scheduleTask')->willThrowException(new \OCP\TaskProcessing\Exception\ValidationException('bad input'));

		$refusal = $this->refusalOf(fn () => $this->drafter()->draft(self::ID));

		$this->assertSame(502, $refusal->status);
		$this->assertSame('provider-failed', $refusal->reason);
	}

	public function testAFinishedDraftIsASuggestionAndStoresNothing(): void {
		$this->task(42, Task::STATUS_SUCCESSFUL, '  U kreeg geen vergunning.  ');

		$this->assertSame(['status' => 'successful', 'text' => 'U kreeg geen vergunning.'], $this->drafter()->poll(self::ID, 42));
		$this->assertSame([], $this->saves);
	}

	public function testAFailedTaskStoresNothing(): void {
		$task = $this->task(43, Task::STATUS_FAILED);
		$task->setErrorMessage('The provider timed out');
		$this->task(49, Task::STATUS_CANCELLED);

		$answer = $this->drafter()->poll(self::ID, 43);

		$this->assertSame(['status' => 'failed', 'error' => 'The provider timed out'], $answer);
		$this->assertSame(['status' => 'failed', 'error' => 'The text assistant could not write a draft.'], $this->drafter()->poll(self::ID, 49));
		$this->assertSame([], $this->saves, 'A failed or cancelled draft stores nothing.');
	}

	public function testARunningAndAScheduledDraftSayWhereTheyAre(): void {
		$this->task(44, Task::STATUS_RUNNING);
		$this->task(45, Task::STATUS_SCHEDULED);
		$this->task(46, Task::STATUS_SUCCESSFUL, '   ');

		$this->assertSame(['status' => 'running'], $this->drafter()->poll(self::ID, 44));
		$this->assertSame(['status' => 'scheduled'], $this->drafter()->poll(self::ID, 45));
		$this->assertSame('failed', $this->drafter()->poll(self::ID, 46)['status']);
	}

	public function testAnotherPublicationsOrUsersTaskIsNotFound(): void {
		$this->task(47, Task::STATUS_SUCCESSFUL, 'Tekst', publication: 'another-publication');
		$this->task(48, Task::STATUS_SUCCESSFUL, 'Tekst', user: 'someone-else');

		$this->assertSame(404, $this->refusalOf(fn () => $this->drafter()->poll(self::ID, 47))->status);
		$this->assertSame(404, $this->refusalOf(fn () => $this->drafter()->poll(self::ID, 48))->status);
		$this->assertSame(404, $this->refusalOf(fn () => $this->drafter()->poll(self::ID, 999))->status);
	}

	public function testAcceptStoresProvenanceAndTheAcceptingUser(): void {
		$this->task(42, Task::STATUS_SUCCESSFUL, 'U kreeg geen vergunning.');

		$stored = $this->drafter()->accept(self::ID, ' U kreeg geen parkeervergunning. ', 'B1', 42);

		$provenance = ['generatedBy' => 'ai', 'provider' => 'hermiq', 'at' => '2026-11-14T10:00:00+00:00', 'acceptedBy' => 'officer'];
		$this->assertSame(
			['plainSummary' => 'U kreeg geen parkeervergunning.', 'plainSummaryLevel' => 'B1', 'plainSummaryProvenance' => $provenance],
			$stored
		);
		$this->assertCount(1, $this->saves);
		$this->assertSame('U kreeg geen parkeervergunning.', $this->saves[0]['object']['plainSummary']);
		$this->assertSame('B1', $this->saves[0]['object']['plainSummaryLevel']);
		$this->assertSame('Besluit op bezwaar parkeervergunning', $this->saves[0]['object']['title']);
		$this->assertSame(self::ID, $this->saves[0]['uuid']);
		$this->assertSame('19', $this->saves[0]['register']);
		$this->assertSame('7', $this->saves[0]['schema']);
		$this->assertTrue($this->saves[0]['_rbac']);
		$this->assertNull($this->provenance->take('U kreeg geen parkeervergunning.'), 'The held provenance does not outlive its save.');
	}

	public function testAcceptHoldsTheProvenanceForTheListenerDuringTheSave(): void {
		$held = null;
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn(new ObjectEntity());
		$this->objects->method('saveObject')->willReturnCallback(
			function () use (&$held): ObjectEntity {
				$held = $this->provenance->take('Kort en duidelijk.');
				return new ObjectEntity();
			}
		);
		$this->task(42, Task::STATUS_SUCCESSFUL, 'Kort.');

		$this->drafter()->accept(self::ID, 'Kort en duidelijk.', 'A2', 42);

		$this->assertSame('ai', $held['generatedBy']);
		$this->assertSame('officer', $held['acceptedBy']);
	}

	public function testAHandWrittenOrUnfinishedDraftIsAPersonsText(): void {
		$this->task(43, Task::STATUS_FAILED);
		$this->task(47, Task::STATUS_SUCCESSFUL, 'Tekst', publication: 'another-publication');

		$this->assertSame('person', $this->drafter()->accept(self::ID, 'Zelf geschreven.', 'B2')['plainSummaryProvenance']['generatedBy']);
		$this->assertSame('person', $this->drafter()->accept(self::ID, 'Zelf geschreven.', 'B2', 43)['plainSummaryProvenance']['generatedBy']);
		$this->assertSame('person', $this->drafter()->accept(self::ID, 'Zelf geschreven.', 'B2', 47)['plainSummaryProvenance']['generatedBy']);
		$this->assertArrayNotHasKey('provider', $this->drafter()->accept(self::ID, 'Zelf geschreven.', 'B2', 47)['plainSummaryProvenance']);
	}

	public function testAcceptRefusesAnEmptyTextOrAnUnknownLevel(): void {
		$this->assertSame('invalid-text', $this->refusalOf(fn () => $this->drafter()->accept(self::ID, '   ', 'B1'))->reason);
		$this->assertSame('invalid-text', $this->refusalOf(fn () => $this->drafter()->accept(self::ID, str_repeat('a', 1501), 'B1'))->reason);
		$this->assertSame('invalid-level', $this->refusalOf(fn () => $this->drafter()->accept(self::ID, 'Kort.', ''))->reason);
		$this->assertSame(400, $this->refusalOf(fn () => $this->drafter()->accept(self::ID, 'Kort.', 'C1'))->status);
		$this->assertSame([], $this->saves);
	}

	public function testAFailedSaveAnswers503AndHoldsNothing(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn(new ObjectEntity());
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('validation failed'));

		$refusal = $this->refusalOf(fn () => $this->drafter()->accept(self::ID, 'Kort.', 'B1'));

		$this->assertSame(503, $refusal->status);
		$this->assertNull($this->provenance->take('Kort.'));
	}
}
