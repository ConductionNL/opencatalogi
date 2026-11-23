<?php

/**
 * Unit tests for ThemeHotspotListener: only an administrator marks a subject as
 * an archive hotspot, with a reason; the marking sets the subject's appraisal
 * to permanent and is audited after the save.
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

use DateTime;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Listener\ThemeHotspotListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Listener\ThemeHotspotListener
 */
final class ThemeHotspotListenerTest extends TestCase {

	private const THEME_SCHEMA = '14';

	private const REASON = 'Aangewezen in archiefhotspotlijst 2026';

	/**
	 * Whether the saving user is an administrator.
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
	 */
	private bool $auditFails = false;

	/**
	 * The errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The listener over the fixtures.
	 *
	 * @return ThemeHotspotListener
	 */
	private function listener(): ThemeHotspotListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'theme_schema' ? self::THEME_SCHEMA : $default
		);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $this->admin);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-23T10:00:00+00:00'));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message): void {
			$this->logged[] = $message;
		});

		$test = $this;
		$mapper = new class($test) {

			public function __construct(private readonly ThemeHotspotListenerTest $test) {
			}

			public function createAuditTrail(?object $old = null, ?object $new = null, ?string $action = null, ?array $cascadeContext = null): void {
				$this->test->recordAudit(action: (string)$action, context: (array)$cascadeContext);
			}
		};
		$openRegister = $this->createMock(originalClassName: OpenRegisterServicesInterface::class);
		$openRegister->method('installedService')->willReturn($mapper);

		return new ThemeHotspotListener(
			config: $config,
			session: $session,
			groups: $groups,
			time: $time,
			openRegister: $openRegister,
			l10n: $l10n,
			logger: $logger
		);
	}//end listener()

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

		$this->listener()->handle(event: $event);

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

		$this->listener()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'archiveHotspotReason', actual: $event->getErrors()['property']);
	}//end testAMarkingWithoutAReasonIsRefused()

	/**
	 * Marking sets the appraisal to permanent, keeps the old one, and stamps the moment.
	 *
	 * @return void
	 */
	public function testMarkingSetsTheThemesAppraisalToRetainPermanently(): void {
		$written = $this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON], retention: ['archiefnominatie' => 'vernietigen']);
		$event = new ObjectUpdatingEvent($written, $this->subject(data: [], retention: ['archiefnominatie' => 'vernietigen']));

		$this->listener()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: ['archiefnominatie' => ThemeHotspotListener::PERMANENT, ThemeHotspotListener::PREVIOUS => 'vernietigen'],
			actual: $written->getRetention()
		);
		$this->assertSame(expected: ['archiveHotspotSince' => '2026-11-23T10:00:00+00:00'], actual: $event->getModifiedData());
	}//end testMarkingSetsTheThemesAppraisalToRetainPermanently()

	/**
	 * Unmarking puts the old appraisal back, or removes it when there was none.
	 *
	 * @return void
	 */
	public function testUnmarkingRestoresThePreviousAppraisal(): void {
		$stored = ['archiefnominatie' => ThemeHotspotListener::PERMANENT, ThemeHotspotListener::PREVIOUS => 'vernietigen'];
		$written = $this->subject(data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien'], retention: $stored);
		$event = new ObjectUpdatingEvent($written, $this->subject(data: ['archiveHotspot' => true], retention: $stored));

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: ['archiefnominatie' => 'vernietigen'], actual: $written->getRetention());
		$this->assertSame(expected: ['archiveHotspotSince' => null], actual: $event->getModifiedData());

		$none = $this->subject(data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien'], retention: ['archiefnominatie' => ThemeHotspotListener::PERMANENT, ThemeHotspotListener::PREVIOUS => null]);
		$this->listener()->handle(event: new ObjectUpdatingEvent($none, $this->subject(data: ['archiveHotspot' => true])));
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
			$this->listener()->handle(event: $event);
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
		$this->listener()->handle(event: new ObjectCreatedEvent($this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON])));
		$this->listener()->handle(
			event: new ObjectUpdatedEvent(
				$this->subject(data: ['archiveHotspot' => false, 'archiveHotspotReason' => 'Lijst herzien']),
				$this->subject(data: ['archiveHotspot' => true])
			)
		);
		$this->listener()->handle(event: new ObjectUpdatedEvent($this->subject(data: ['archiveHotspot' => false]), $this->subject(data: [])));

		$this->assertSame(
			expected: [
				[ThemeHotspotListener::MARKED, ['user' => 'beheerder', 'reason' => self::REASON]],
				[ThemeHotspotListener::UNMARKED, ['user' => 'beheerder', 'reason' => 'Lijst herzien']],
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

		$this->listener()->handle(event: new ObjectCreatedEvent($this->subject(data: ['archiveHotspot' => true, 'archiveHotspotReason' => self::REASON])));

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		$this->assertStringContainsString(needle: 'audit store down', haystack: $this->logged[0]);
	}//end testAFailedAuditIsLoggedNotThrown()
}//end class
