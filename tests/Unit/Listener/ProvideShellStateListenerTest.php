<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Controller\Setup\SetupStatusReport;
use OCA\OpenCatalogi\Listener\ProvideShellStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The SPA page carries the setup status and the walkthrough preferences, so the
 * app shell does not ask GET /api/setup/status and GET /api/preferences/{key}.
 */
class ProvideShellStateListenerTest extends TestCase {
	private SetupStatusReport&MockObject $setupStatus;

	private IConfig&MockObject $config;

	private IUserSession&MockObject $userSession;

	/**
	 * Captured key/value pairs passed to provideInitialState().
	 *
	 * @var array<string, mixed>
	 */
	private array $provided = [];

	private ProvideShellStateListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, $data): void {
				$this->provided[$key] = $data;
			}
		);
		$this->setupStatus = $this->createMock(SetupStatusReport::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->listener = new ProvideShellStateListener(
			$initialState,
			$this->setupStatus,
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testCarriesSetupStatusAndWalkthroughPreferences(): void {
		$this->setupStatus->method('payload')->willReturn(['version' => 3, 'completed' => true, 'steps' => []]);
		$this->config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, string $default): string => match ($key) {
				'pref_walkthrough_seen_version' => '2',
				default => '',
			}
		);

		$this->listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'index')));

		$this->assertSame(['version' => 3, 'completed' => true, 'steps' => []], $this->provided['setup_status']);
		// Shaped as GET /api/preferences/{key} answers: an unset value is null.
		$this->assertSame(['seen' => '2', 'progress' => null], $this->provided['walkthrough_preferences']);
	}

	public function testLeavesSetupStatusOutWhenTheReportFails(): void {
		$this->setupStatus->method('payload')->willThrowException(new RuntimeException('register gone'));
		$this->config->method('getUserValue')->willReturn('');

		$this->listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'index')));

		$this->assertArrayNotHasKey('setup_status', $this->provided);
		$this->assertSame(['seen' => null, 'progress' => null], $this->provided['walkthrough_preferences']);
	}

	public function testIgnoresOtherTemplatesAndAnonymousPages(): void {
		$this->setupStatus->expects($this->never())->method('payload');

		$this->listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'public')));
		$this->listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'index')));

		$anonymous = $this->createMock(IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$listener = new ProvideShellStateListener(
			$this->createMock(IInitialState::class),
			$this->setupStatus,
			$this->config,
			$anonymous,
			$this->createMock(LoggerInterface::class)
		);
		$listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'index')));

		$this->assertSame([], $this->provided);
	}

	public function testWalkthroughKeyIsTheManifests(): void {
		$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);

		$this->assertSame($manifest['walkthrough']['completionConfigKey'], ProvideShellStateListener::WALKTHROUGH_KEY);
	}
}
