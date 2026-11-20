<?php

/**
 * Unit tests for CapabilityDeclarationListener.
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

use OCA\OpenCatalogi\Listener\CapabilityDeclarationListener;
use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The eight capability verbs are declared on OpenRegister's real event (REQ-WAB-004).
 */
#[CoversClass(CapabilityDeclarationListener::class)]
#[UsesClass(CapabilityRights::class)]
class CapabilityDeclarationListenerTest extends TestCase {

	/**
	 * Every verb is declared for opencatalogi at the schema level, with a description.
	 *
	 * @return void
	 */
	public function testTheEightVerbsAreDeclared(): void {
		$event = new PermissionsDeclaringEvent();

		(new CapabilityDeclarationListener())->handle($event);

		$declared = [];
		foreach ($event->getDeclared() as $declaration) {
			$this->assertSame('opencatalogi', $declaration['app']);
			$this->assertSame(['schema'], $declaration['levels']);
			$this->assertNotSame('', $declaration['description']);
			$declared[] = $declaration['verb'];
		}

		$this->assertSame(
			[
				'openInspection',
				'openCommentPeriod',
				'depublish',
				'announceNationally',
				'decideRetention',
				'processWooBatch',
				'publishWooBatch',
				'requestWooRegistration',
			],
			$declared
		);
		$this->assertSame([], $event->getRejected());

	}//end testTheEightVerbsAreDeclared()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$event = new Event();

		(new CapabilityDeclarationListener())->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnotherEventIsIgnored()
}//end class
