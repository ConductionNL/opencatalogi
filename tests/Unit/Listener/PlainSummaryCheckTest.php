<?php

/**
 * Unit tests for PlainSummaryCheck: a plain language summary is saved with its
 * reading level, and its provenance is written by the server, never the caller.
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
 */

declare(strict_types=1);

namespace Unit\Listener;

use DateTime;
use OCA\OpenCatalogi\Listener\PlainSummaryCheck;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Listener\PlainSummaryCheck
 * @uses \OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance
 */
class PlainSummaryCheckTest extends TestCase {

	private const SUMMARY = 'U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij het besluit.';

	private PlainSummaryProvenance $provenance;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T10:00:00+00:00'));

		$this->provenance = new PlainSummaryProvenance($session, $time);
	}

	private function listener(): PlainSummaryCheck {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PlainSummaryCheck($this->provenance, $l10n);
	}

	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema('7');
		$entity->setObject($data);
		return $entity;
	}

	public function testASummaryWithoutALevelIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['title' => 'Besluit', 'plainSummary' => self::SUMMARY, 'plainSummaryLevel' => '']),
			$this->entity(['title' => 'Besluit'])
		);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('plainSummaryLevel', $event->getErrors()['property']);
		$this->assertStringContainsString('plainSummaryLevel', $event->getErrors()['message']);
		$this->assertSame([], $event->getModifiedData());
	}

	public function testAPersonsSummaryGetsAPersonProvenance(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(['title' => 'Besluit', 'plainSummary' => self::SUMMARY, 'plainSummaryLevel' => 'B1'])
		);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(
			[
				'plainSummaryProvenance' => [
					'generatedBy' => 'person',
					'at' => '2026-11-14T10:00:00+00:00',
					'acceptedBy' => 'officer',
				],
			],
			$event->getModifiedData()
		);
	}

	public function testASummaryWithoutTheLevelKeyGetsTheDefault(): void {
		$event = new ObjectCreatingEvent($this->entity(['plainSummary' => self::SUMMARY]));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('B1', $event->getModifiedData()['plainSummaryLevel']);
	}

	public function testACallerCannotWriteTheProvenance(): void {
		$stored = ['generatedBy' => 'person', 'at' => '2026-01-01T00:00:00+00:00', 'acceptedBy' => 'first'];
		$event = new ObjectUpdatingEvent(
			$this->entity(
				[
					'plainSummary' => self::SUMMARY,
					'plainSummaryLevel' => 'B1',
					'plainSummaryProvenance' => ['generatedBy' => 'ai', 'provider' => 'forged', 'at' => 'x', 'acceptedBy' => 'someone'],
				]
			),
			$this->entity(['plainSummary' => self::SUMMARY, 'plainSummaryLevel' => 'B1', 'plainSummaryProvenance' => $stored])
		);

		$this->listener()->handle($event);

		$this->assertSame($stored, $event->getModifiedData()['plainSummaryProvenance']);
	}

	public function testAnUnchangedSummaryKeepsItsProvenanceWithoutAWrite(): void {
		$stored = ['generatedBy' => 'ai', 'provider' => 'hermiq', 'at' => '2026-01-01T00:00:00+00:00', 'acceptedBy' => 'first'];
		$data = ['plainSummary' => self::SUMMARY, 'plainSummaryLevel' => 'B2', 'plainSummaryProvenance' => $stored];
		$event = new ObjectUpdatingEvent($this->entity($data), $this->entity($data));
		$event->setModifiedData(['other' => 'kept']);

		$this->listener()->handle($event);

		$this->assertSame(['other' => 'kept'], $event->getModifiedData());
	}

	public function testTheAcceptRoutesProvenanceIsTakenForItsText(): void {
		$accepted = ['generatedBy' => 'ai', 'provider' => 'hermiq', 'at' => '2026-11-14T10:00:00+00:00', 'acceptedBy' => 'officer'];
		$this->provenance->expect(self::SUMMARY, $accepted);
		$event = new ObjectUpdatingEvent(
			$this->entity(['plainSummary' => self::SUMMARY, 'plainSummaryLevel' => 'B1']),
			$this->entity([])
		);

		$this->listener()->handle($event);

		$this->assertSame($accepted, $event->getModifiedData()['plainSummaryProvenance']);
		$this->assertNull($this->provenance->take(self::SUMMARY), 'The held provenance serves one save only.');
	}

	public function testRemovingTheSummaryClearsTheProvenance(): void {
		$stored = ['generatedBy' => 'person', 'at' => '2026-01-01T00:00:00+00:00', 'acceptedBy' => 'first'];
		$event = new ObjectUpdatingEvent(
			$this->entity(['plainSummary' => '', 'plainSummaryLevel' => '', 'plainSummaryProvenance' => $stored]),
			$this->entity(['plainSummary' => self::SUMMARY, 'plainSummaryLevel' => 'B1', 'plainSummaryProvenance' => $stored])
		);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertNull($event->getModifiedData()['plainSummaryProvenance']);
	}

	public function testAnObjectWithoutAPlainSummaryIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['title' => 'Besluit']));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
	}
}
