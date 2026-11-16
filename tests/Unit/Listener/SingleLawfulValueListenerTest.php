<?php

/**
 * Unit tests for SingleLawfulValueListener on OpenRegister's real
 * ObjectCreatingEvent (woo-metadata-suggestions REQ-WMS-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\SingleLawfulValueListener;
use OCA\OpenCatalogi\Service\MetadataSuggestion\SingleLawfulValue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Unit\Service\MetadataSuggestion\SingleLawfulValueTest;

/**
 * Tests for SingleLawfulValueListener.
 */
#[CoversClass(SingleLawfulValueListener::class)]
#[UsesClass(SingleLawfulValue::class)]
final class SingleLawfulValueListenerTest extends TestCase {

	/**
	 * The listener over the real SingleLawfulValue, for an officer of the given organisations.
	 *
	 * @param array<int, string> $organisations The officer's organisations.
	 * @param bool $signedIn Whether an officer is signed in.
	 *
	 * @return SingleLawfulValueListener
	 */
	private function listener(array $organisations, bool $signedIn=true): SingleLawfulValueListener {
		require_once __DIR__ . '/../Service/MetadataSuggestion/SingleLawfulValueTest.php';
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('officer');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SingleLawfulValueListener(
			lawfulValue: SingleLawfulValueTest::service($this, $organisations, ['infocat001' => ['besluit']]),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A real publication entity being created in schema 12 of register 7.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('7');
		$entity->setSchema('12');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	public function testTheFillsGoBackAsModifiedDataOnTheRealEvent(): void {
		$event = new ObjectCreatingEvent(object: $this->entity(['title' => 'Besluit']));
		$event->setModifiedData(['summary' => 'kept']);

		$this->listener(['org-a'])->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('kept', $modified['summary']);
		$this->assertSame('org-a', $modified['organization']);
		$this->assertSame('infocat001', $modified['wooCategory']);
		$this->assertSame(['organization', 'wooCategory'], array_column($modified['filledByRule'], 'field'));
		$this->assertArrayNotHasKey('@self', $modified);
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testNothingFilledLeavesTheEventAlone(): void {
		$event = new ObjectCreatingEvent(object: $this->entity(['title' => 'Besluit', 'wooCategory' => 'infocat002']));

		$this->listener(['org-a', 'org-b'])->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}

	public function testWithoutAnOfficerOrForAnotherEventNothingHappens(): void {
		$creating = new ObjectCreatingEvent(object: $this->entity(['title' => 'Besluit']));
		$this->listener(['org-a'], signedIn: false)->handle($creating);
		$this->assertSame([], $creating->getModifiedData());

		$created = new ObjectCreatedEvent($this->entity(['title' => 'Besluit']));
		$this->listener(['org-a'])->handle($created);
		$this->assertArrayNotHasKey('organization', $created->getObject()->getObject());
		$this->assertArrayNotHasKey('wooCategory', $created->getObject()->getObject());
	}
}//end class
