<?php

/**
 * Unit tests for ThemeHotspotListener: only an administrator marks a subject as
 * an archive hotspot, with a reason; the guard sets the subject's appraisal
 * to permanent before the save, and the audit records it after.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
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
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Listener\ThemeHotspotAudit;
use OCA\OpenCatalogi\Listener\ThemeHotspotChange;
use OCA\OpenCatalogi\Listener\ThemeHotspotGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Listener\ThemeHotspotGuard
 * @covers \OCA\OpenCatalogi\Listener\ThemeHotspotAudit
 * @covers \OCA\OpenCatalogi\Listener\ThemeHotspotChange
 */
final class ThemeHotspotListenerTest extends TestCase {

	private const THEME_SCHEMA = '14';

	private const REASON = 'Aangewezen in archiefhotspotlijst 2026';

	/**
	 * Whether the saving user is an administrator.
	 *
	 * @var bool
	 */
	private bool $admin = true;

	/**
	 * The audit entries written: action and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $audited = [];

	/**
	 * Whether writing the audit entry fails.
	 *
	 * @var bool
	 */
	private bool $auditFails = false;

	/**
	 * The errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The change detector, for theme schema 14.
	 *
	 * @return ThemeHotspotChange
	 */
	private function change(): ThemeHotspotChange {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'theme_schema' => self::THEME_SCHEMA,
				default => $default,
			}
		);

		return new ThemeHotspotChange(config: $config);
	}//end change()

	/**
	 * The signed-in user, an administrator or not.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The guard before the save.
	 *
	 * @return ThemeHotspotGuard
	 */
	private function guard(): ThemeHotspotGuard {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $this->admin);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ThemeHotspotGuard(change: $this->change(), session: $this->session(), groups: $groups, l10n: $l10n);
	}//end guard()

	/**
	 * The audit after the save, over a recording audit trail mapper.
	 *
	 * @return ThemeHotspotAudit
	 */
	private function audit(): ThemeHotspotAudit {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message): void {
			$this->logged[] = $message;
		});

		$test = $this;
		$mapper = new class($test) {

			/**
			 * Constructor.
			 *
			 * @param ThemeHotspotListenerTest $test The test that records the entries.
			 */
			public function __construct(private readonly ThemeHotspotListenerTest $test) {
			}

			/**
			 * OpenRegister's AuditTrailMapper::createAuditTrail(), called with named arguments.
			 *
			 * @param object|null $old The object before.
			 * @param object|null $new The object after.
			 * @param string|null $action The action.
			 * @param array<string, mixed>|null $cascadeContext The context.
			 *
			 * @return void
			 */
			public function createAuditTrail(?object $old = null, ?object $new = null, ?string $action = null, ?array $cascadeContext = null): void {
				$this->test->recordAudit(action: (string)$action, context: (array)$cascadeContext);
			}
		};
		$openRegister = $this->createMock(originalClassName: OpenRegisterServicesInterface::class);
		$openRegister->method('installedService')->willReturn($mapper);

		return new ThemeHotspotAudit(change: $this->change(), session: $this->session(), openRegister: $openRegister, logger: $logger);
	}//end audit()

	/**
	 * Record an audit entry, or fail like a store that is down.
	 *
	 * @param string $action The action.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	public function recordAudit(string $action, array $context): void {
		if ($this->auditFails === true) {
			throw new RuntimeException('audit store down');
		}

		$this->audited[] = [$action, $context];
	}//end recordAudit()

	/**
	 * A subject, or an object of another schema.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param array<string, mixed> $retention Its retention.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function subject(array $data, array $retention = [], string $schema = self::THEME_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('theme-1');
		$entity->setSchema($schema);
		$entity->setObject(['title' => 'Herindeling gemeente'] + $data);
		$entity->setRetention($retention);
		return $entity;
	}//end subject()

	/**
	 * An editor who is not an administrator cannot mark a subject.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotMarkAHotspot(): void {
		$this->admin = false;
		$event = new ObjectUpdatingEvent(
			$this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON]),
			$this->subject(data: [])
		);

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'archiveHotspot', actual: $event->getErrors()['property']);
		$this->assertSame(expected: [], actual: $event->getModifiedData());
	}//end testANonAdministratorCannotMarkAHotspot()

	/**
	 * A marking without a reason is refused, naming the reason.
	 *
	 * @return void
	 */
	public function testAMarkingWithoutAReasonIsRefused(): void {
		$event = new ObjectCreatingEvent($this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => '  ']));

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'archiveHotspotReason', actual: $event->getErrors()['property']);
	}//end testAMarkingWithoutAReasonIsRefused()

	/**
	 * Marking sets the appraisal to permanent, keeps the old one, and stamps the moment.
	 *
	 * @return void
	 */
	public function testMarkingSetsTheThemesAppraisalToRetainPermanently(): void {
		$written = $this->subject(
			data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON],
			retention: ['archiefnominatie' => 'vernietigen']
		);
		$event = new ObjectUpdatingEvent($written, $this->subject(data: [], retention: ['archiefnominatie' => 'vernietigen']));

		$this->guard()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: ['archiefnominatie' => ThemeHotspotGuard::PERMANENT, ThemeHotspotGuard::PREVIOUS => 'vernietigen'],
			actual: $written->getRetention()
		);
		$since = (string)$event->getModifiedData()['archiveHotspotSince'];
		$this->assertLessThan(expected: 60, actual: abs(time() - (int)strtotime($since)), message: $since);
	}//end testMarkingSetsTheThemesAppraisalToRetainPermanently()

	/**
	 * Unmarking puts the old appraisal back, or removes it when there was none.
	 *
	 * @return void
	 */
	public function testUnmarkingRestoresThePreviousAppraisal(): void {
		$stored = ['archiefnominatie' => ThemeHotspotGuard::PERMANENT, ThemeHotspotGuard::PREVIOUS => 'vernietigen'];
		$written = $this->subject(data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien'], retention: $stored);
		$event = new ObjectUpdatingEvent($written, $this->subject(data: ['archiveHotspot' => true], retention: $stored));

		$this->guard()->handle(event: $event);

		$this->assertSame(expected: ['archiefnominatie' => 'vernietigen'], actual: $written->getRetention());
		$this->assertSame(expected: ['archiveHotspotSince' => null], actual: $event->getModifiedData());

		$none = $this->subject(
			data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien'],
			retention: ['archiefnominatie' => ThemeHotspotGuard::PERMANENT, ThemeHotspotGuard::PREVIOUS => null]
		);
		$this->guard()->handle(event: new ObjectUpdatingEvent($none, $this->subject(data: ['archiveHotspot' => true])));
		$this->assertSame(expected: [], actual: $none->getRetention());
	}//end testUnmarkingRestoresThePreviousAppraisal()

	/**
	 * A save that does not change the marking, or an object of another schema, is left alone.
	 *
	 * @return void
	 */
	public function testNoChangeOrAnotherSchemaIsLeftAlone(): void {
		$this->admin = false;
		$events = [
			new ObjectUpdatingEvent($this->subject(data: ['archiveHotspot' => true, 'title' => 'Nieuw']), $this->subject(data: ['archiveHotspot' => true])),
			new ObjectCreatingEvent($this->subject(data: ['title' => 'Zonder markering'])),
			new ObjectCreatingEvent($this->subject(data: ['archiveHotspot' => true], schema: '7')),
		];

		foreach ($events as $index => $event) {
			$this->guard()->handle(event: $event);
			$this->assertFalse(condition: $event->isPropagationStopped(), message: (string)$index);
			$this->assertSame(expected: [], actual: $event->getModifiedData(), message: (string)$index);
		}
	}//end testNoChangeOrAnotherSchemaIsLeftAlone()

	/**
	 * After the save the marking and the unmarking are audited with the user and the reason.
	 *
	 * @return void
	 */
	public function testTheSavedChangeIsAudited(): void {
		$this->audit()->handle(event: new ObjectCreatedEvent($this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON])));
		$this->audit()->handle(
			event: new ObjectUpdatedEvent(
				$this->subject(data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien']),
				$this->subject(data: ['archiveHotspot' => true])
			)
		);
		$this->audit()->handle(event: new ObjectUpdatedEvent($this->subject(data: ['archiveHotspot' => false]), $this->subject(data: [])));

		$this->assertSame(
			expected: [
				[ThemeHotspotAudit::MARKED, ['user' => 'beheerder', 'reason' => self::REASON]],
				[ThemeHotspotAudit::UNMARKED, ['user' => 'beheerder', 'reason' => 'Lijst herzien']],
			],
			actual: $this->audited
		);
	}//end testTheSavedChangeIsAudited()

	/**
	 * An audit entry that cannot be written is logged; the saved marking stands.
	 *
	 * @return void
	 */
	public function testAFailedAuditIsLoggedNotThrown(): void {
		$this->auditFails = true;

		$this->audit()->handle(event: new ObjectCreatedEvent($this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON])));

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		$this->assertStringContainsString(needle: 'audit store down', haystack: $this->logged[0]);
	}//end testAFailedAuditIsLoggedNotThrown()
}//end class
