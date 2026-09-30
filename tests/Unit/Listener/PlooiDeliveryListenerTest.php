<?php

/**
 * Tests for the listener that queues a PLOOI delivery when a publication turns public.
 *
 * Built on the real ObjectUpdatedEvent with an old and a new object, so a
 * wrong accessor fails here and not on every object update in production.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\BackgroundJob\PlooiDelivery;
use OCA\OpenCatalogi\Listener\PlooiDeliveryListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Listener\PlooiDeliveryListener
 */
class PlooiDeliveryListenerTest extends TestCase {

	private MockObject&IJobList $jobList;

	private PlooiDeliveryListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->jobList = $this->createMock(IJobList::class);
		$this->listener = new PlooiDeliveryListener(jobList: $this->jobList);
	}

	private function publication(?string $publicationDate, ?string $depublicationDate = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('pub-1');
		$entity->setRegister('7');
		$entity->setSchema('12');
		$entity->setObject(['title' => 'Besluit', 'publicationDate' => $publicationDate, 'depublicationDate' => $depublicationDate]);
		return $entity;
	}

	/** REQ-WND-003: a publication that just became public is queued for delivery. */
	public function testAPublicationThatTurnsPublicIsQueued(): void {
		$this->jobList->expects($this->once())->method('add')->with(
			PlooiDelivery::class,
			['uuid' => 'pub-1', 'register' => '7', 'schema' => '12']
		);

		$this->listener->handle(new ObjectUpdatedEvent($this->publication('2020-01-01T00:00:00+00:00'), $this->publication(null)));
	}

	/** An update to a publication that was already public is not delivered again. */
	public function testAnAlreadyPublicPublicationIsNotQueuedAgain(): void {
		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle(
			new ObjectUpdatedEvent($this->publication('2020-01-01T00:00:00+00:00'), $this->publication('2020-01-01T00:00:00+00:00'))
		);
	}

	/** A publication dated in the future is not public yet. */
	public function testAFuturePublicationIsNotQueued(): void {
		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle(new ObjectUpdatedEvent($this->publication('2999-01-01T00:00:00+00:00'), $this->publication(null)));
	}
}
