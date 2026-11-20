<?php

/**
 * Unit tests for CapabilityScopeListener.
 *
 * @category Test
 * @package  Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CapabilityScopeListener;
use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The capability verbs are decided from the catalog schema's grants, fail closed (REQ-WAB-004).
 */
#[CoversClass(CapabilityScopeListener::class)]
#[UsesClass(CapabilityRights::class)]
class CapabilityScopeListenerTest extends TestCase {

	/**
	 * The listener, with the given administrators.
	 *
	 * @param array $admins User ids that are administrators.
	 *
	 * @return CapabilityScopeListener The listener.
	 */
	private function listener(array $admins = []): CapabilityScopeListener {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));

		return new CapabilityScopeListener($groups, $this->createMock(LoggerInterface::class));

	}//end listener()

	/**
	 * A schema with the given slug and authorization block.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $slug          The slug.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?array $authorization, string $slug = 'catalog'): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setAuthorization($authorization);

		return $schema;

	}//end schema()

	/**
	 * A user in a granted group is allowed.
	 *
	 * @return void
	 */
	public function testAGrantedGroupIsAllowed(): void {
		$event = new CustomScopeEvaluatingEvent(
			schema: $this->schema(['openInspection' => ['ter-inzage']]),
			action: 'openInspection',
			userId: 'anna',
			userGroups: ['staff', 'ter-inzage']
		);

		$this->listener()->handle($event);

		$this->assertTrue($event->getVerdict());

	}//end testAGrantedGroupIsAllowed()

	/**
	 * A user in no granted group is denied, also for a verb granted to nobody.
	 *
	 * @return void
	 */
	public function testAnUngrantedUserIsDenied(): void {
		$grants = ['openInspection' => ['ter-inzage']];

		$other = new CustomScopeEvaluatingEvent(
			schema: $this->schema($grants),
			action: 'openInspection',
			userId: 'bram',
			userGroups: ['staff']
		);
		$this->listener()->handle($other);
		$this->assertFalse($other->getVerdict());

		$ungranted = new CustomScopeEvaluatingEvent(
			schema: $this->schema($grants),
			action: 'publishWooBatch',
			userId: 'anna',
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($ungranted);
		$this->assertFalse($ungranted->getVerdict());

		$anonymous = new CustomScopeEvaluatingEvent(
			schema: $this->schema($grants),
			action: 'openInspection',
			userId: null,
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($anonymous);
		$this->assertFalse($anonymous->getVerdict());

	}//end testAnUngrantedUserIsDenied()

	/**
	 * An administrator is allowed without any grant.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsAllowed(): void {
		$event = new CustomScopeEvaluatingEvent(
			schema: $this->schema([]),
			action: 'publishWooBatch',
			userId: 'admin',
			userGroups: ['admin']
		);

		$this->listener(['admin'])->handle($event);

		$this->assertTrue($event->getVerdict());

	}//end testAnAdministratorIsAllowed()

	/**
	 * A block that cannot be read, or a grant that is not a list, denies.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantListDenies(): void {
		$broken = new class extends Schema {
			/**
			 * The block cannot be read.
			 *
			 * @return array|null Never.
			 */
			public function getAuthorization(): ?array {
				throw new \RuntimeException('unreadable');
			}
		};
		$broken->setSlug('catalog');

		$event = new CustomScopeEvaluatingEvent(
			schema: $broken,
			action: 'openInspection',
			userId: 'anna',
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($event);
		$this->assertFalse($event->getVerdict());

		$notAList = new CustomScopeEvaluatingEvent(
			schema: $this->schema(['openInspection' => 'ter-inzage']),
			action: 'openInspection',
			userId: 'anna',
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($notAList);
		$this->assertFalse($notAList->getVerdict());

		$noBlock = new CustomScopeEvaluatingEvent(
			schema: $this->schema(null),
			action: 'openInspection',
			userId: 'anna',
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($noBlock);
		$this->assertFalse($noBlock->getVerdict());

	}//end testAnUnreadableGrantListDenies()

	/**
	 * Other verbs and other schemas get no vote.
	 *
	 * @return void
	 */
	public function testOtherChecksAreLeftAlone(): void {
		$read = new CustomScopeEvaluatingEvent(
			schema: $this->schema(['read' => ['staff']]),
			action: 'read',
			userId: 'anna',
			userGroups: ['staff']
		);
		$this->listener()->handle($read);
		$this->assertFalse($read->hasVerdict());

		$elsewhere = new CustomScopeEvaluatingEvent(
			schema: $this->schema(['openInspection' => ['ter-inzage']], 'publication'),
			action: 'openInspection',
			userId: 'anna',
			userGroups: ['ter-inzage']
		);
		$this->listener()->handle($elsewhere);
		$this->assertFalse($elsewhere->hasVerdict());

	}//end testOtherChecksAreLeftAlone()
}//end class
