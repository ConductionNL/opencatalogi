<?php
/**
 * EmbargoGuardListenerTest
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
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\OpenCatalogi\Listener\EmbargoGuardListener;
use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The embargo guard on OpenRegister's REAL update event (REQ-PSG-001).
 *
 * @covers \OCA\OpenCatalogi\Listener\EmbargoGuardListener
 * @uses   \OCA\OpenCatalogi\Service\Publication\EmbargoGuard
 */
final class EmbargoGuardListenerTest extends TestCase {

	/**
	 * A date a week ahead, so the stored embargo holds whenever the test runs.
	 *
	 * @var string
	 */
	private string $embargo;

	protected function setUp(): void {
		$this->embargo = (new DateTimeImmutable('+7 days'))->format(DATE_ATOM);
	}//end setUp()

	/**
	 * The listener for an editor outside the lift groups, with publication schema 7.
	 *
	 * @return EmbargoGuardListener
	 */
	private function listener(): EmbargoGuardListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'publication_schema' ? '7' : $default)
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['editors']);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new EmbargoGuardListener(new EmbargoGuard($config, $groups, new NullLogger()), $session, $config, $l10n);
	}//end listener()

	/**
	 * A stored object of $schema with $data.
	 *
	 * @param array<string, mixed> $data   The object data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = '7'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A save through the object API that moves the date earlier is stopped with the code and the moment.
	 *
	 * @return void
	 */
	public function testAnEarlierDateThroughTheObjectApiIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(data: ['title' => 'Besluit', 'publicationDate' => (new DateTimeImmutable())->format(DATE_ATOM)]),
			$this->entity(data: ['title' => 'Besluit', 'publicationDate' => $this->embargo])
		);

		$this->listener()->handle($event);

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertSame(expected: 'embargo-holds', actual: $event->getErrors()['code']);
		self::assertSame(expected: 'publicationDate', actual: $event->getErrors()['property']);
		self::assertSame(expected: $this->embargo, actual: $event->getErrors()['embargoUntil']);
		self::assertStringContainsString(needle: $this->embargo, haystack: $event->getErrors()['message']);
	}//end testAnEarlierDateThroughTheObjectApiIsRefused()

	/**
	 * A later date passes.
	 *
	 * @return void
	 */
	public function testALaterDateIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(data: ['title' => 'Besluit', 'publicationDate' => (new DateTimeImmutable('+14 days'))->format(DATE_ATOM)]),
			$this->entity(data: ['title' => 'Besluit', 'publicationDate' => $this->embargo])
		);

		$this->listener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
		self::assertSame(expected: [], actual: $event->getErrors());
	}//end testALaterDateIsAllowed()

	/**
	 * Another schema, an update without the stored object, and a create are left alone.
	 *
	 * @return void
	 */
	public function testOnlyAnUpdateOfAPublicationIsGuarded(): void {
		$now = (new DateTimeImmutable())->format(DATE_ATOM);

		$other = new ObjectUpdatingEvent(
			$this->entity(data: ['publicationDate' => $now], schema: '8'),
			$this->entity(data: ['publicationDate' => $this->embargo], schema: '8')
		);
		$this->listener()->handle($other);
		self::assertFalse(condition: $other->isPropagationStopped());

		$withoutOld = new ObjectUpdatingEvent($this->entity(data: ['publicationDate' => $now]));
		$this->listener()->handle($withoutOld);
		self::assertFalse(condition: $withoutOld->isPropagationStopped());

		$create = new ObjectCreatingEvent($this->entity(data: ['publicationDate' => $now]));
		$this->listener()->handle($create);
		self::assertFalse(condition: $create->isPropagationStopped());
	}//end testOnlyAnUpdateOfAPublicationIsGuarded()
}//end class
