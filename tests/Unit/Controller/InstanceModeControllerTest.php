<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\InstanceModeController;
use OCA\OpenCatalogi\Service\InstanceMode;
use OCA\OpenCatalogi\Service\InstanceModeSwitch;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\OutsideReach;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InstanceModeController (instance-staging-mode REQ-STG-001).
 */
#[CoversClass(InstanceModeController::class)]
#[UsesClass(InstanceModeSwitch::class)]
#[UsesClass(InstanceMode::class)]
#[UsesClass(OutsideReach::class)]
#[UsesClass(OpenRegisterLookup::class)]
final class InstanceModeControllerTest extends TestCase {

	private IL10N|MockObject $l10n;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn (string $text, array $params = []) => $text);
	}

	/**
	 * A controller without the switch and with nobody logged in.
	 *
	 * @return InstanceModeController
	 */
	private function bare(): InstanceModeController {
		return new InstanceModeController('opencatalogi', $this->createMock(IRequest::class), $this->l10n, $this->createMock(IUserSession::class));
	}

	/**
	 * An instance mode switch over an in-memory app config, recording the audit events.
	 *
	 * @param array<string, string>              $store  The app config.
	 * @param array<int, \OCP\EventDispatcher\Event> $events The dispatched events.
	 *
	 * @return \OCA\OpenCatalogi\Service\InstanceModeSwitch
	 */
	private function instanceModes(array &$store, array &$events): \OCA\OpenCatalogi\Service\InstanceModeSwitch {
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return ($store[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (\OCP\EventDispatcher\Event $event) use (&$events): void {
				$events[] = $event;
			}
		);
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getInstalledApps')->willReturn([]);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$outside = new \OCA\OpenCatalogi\Service\OutsideReach($container, new \OCA\OpenCatalogi\Service\OpenRegisterLookup($container, $apps));

		return new \OCA\OpenCatalogi\Service\InstanceModeSwitch($config, $dispatcher, $outside);
	}//end instanceModes()

	/**
	 * REQ-STG-001: a confirmed change is stored and audited with the user, the old and the new value;
	 * an unconfirmed one changes nothing.
	 */
	public function testChangingTheInstanceModeIsAudited(): void {
		$store = [];
		$events = [];
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$params = ['mode' => 'staging', 'confirmed' => false];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null) use (&$params): mixed {
				return ($params[$key] ?? $default);
			}
		);
		$controller = new InstanceModeController('opencatalogi', $request, $this->l10n, $session, $this->instanceModes(store: $store, events: $events));

		$refused = $controller->updateInstanceMode();
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('Changing the instance mode needs a confirmation.', $refused->getData()['error']);
		$this->assertSame([], $store);
		$this->assertSame([], $events);

		$params['confirmed'] = 'true';
		$changed = $controller->updateInstanceMode();
		$this->assertSame(200, $changed->getStatus());
		$this->assertSame(['mode' => 'staging', 'previous' => 'production', 'changed' => true], $changed->getData());
		$this->assertSame(['instance_mode' => 'staging'], $store);
		$this->assertCount(1, $events);
		$this->assertInstanceOf(\OCP\Log\Audit\CriticalActionPerformedEvent::class, $events[0]);
		$this->assertSame('OpenCatalogi instance mode changed from %s to %s by %s', $events[0]->getLogMessage());
		$this->assertSame(['production', 'staging', 'beheerder'], $events[0]->getParameters());

		$this->assertSame(['mode' => 'staging', 'outside' => ['webhooks' => [], 'synchronisations' => []]], $controller->getInstanceMode()->getData());

		$params['mode'] = 'testing';
		$this->assertSame(400, $controller->updateInstanceMode()->getStatus());
		$this->assertCount(1, $events);
	}//end testChangingTheInstanceModeIsAudited()

	/**
	 * Without the switch (an old construction site) the endpoints refuse instead of failing.
	 */
	public function testTheInstanceModeEndpointsNeedTheSwitch(): void {
		$this->assertSame(503, $this->bare()->getInstanceMode()->getStatus());
		$this->assertSame(401, $this->bare()->updateInstanceMode()->getStatus());
	}//end testTheInstanceModeEndpointsNeedTheSwitch()
}
