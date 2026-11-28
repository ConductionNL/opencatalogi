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
use OCA\OpenCatalogi\Controller\Setup\ProvideShellStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The SPA page carries the setup status and the walkthrough preferences, so the
 * app shell does not ask GET /api/setup/status and GET /api/preferences/{key}.
 */
class ProvideShellStateListenerTest extends TestCase
{

    /**
     * The setup report the listener reads.
     *
     * @var SetupStatusReport&MockObject
     */
    private SetupStatusReport&MockObject $setupStatus;

    /**
     * The user values the preferences live in.
     *
     * @var IConfig&MockObject
     */
    private IConfig&MockObject $config;

    /**
     * Key/value pairs passed to provideInitialState().
     *
     * @var array<string, mixed>
     */
    private array $provided = [];

    /**
     * The listener under test, for a signed-in user.
     *
     * @var ProvideShellStateListener
     */
    private ProvideShellStateListener $listener;

    /**
     * Build the listener with a recording initial state and a signed-in user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupStatus = $this->createMock(originalClassName: SetupStatusReport::class);
        $this->config      = $this->createMock(originalClassName: IConfig::class);
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(originalClassName: IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $this->listener = new ProvideShellStateListener(
            initialState: $this->recordingState(),
            setupStatus: $this->setupStatus,
            config: $this->config,
            userSession: $session
        );

    }//end setUp()

    /**
     * An initial state that records what it is given into $this->provided.
     *
     * @return IInitialState
     */
    private function recordingState(): IInitialState
    {
        $state = $this->createMock(originalClassName: IInitialState::class);
        $state->method('provideInitialState')->willReturnCallback(
            function (string $key, $data): void {
                $this->provided[$key] = $data;
            }
        );
        return $state;

    }//end recordingState()

    /**
     * The render event of a template.
     *
     * @param string $app      The app.
     * @param string $template The template name.
     *
     * @return BeforeTemplateRenderedEvent
     */
    private static function render(string $app, string $template): BeforeTemplateRenderedEvent
    {
        return new BeforeTemplateRenderedEvent(true, new TemplateResponse($app, $template));

    }//end render()

    /**
     * The page carries the setup report and both preferences, unset ones as null.
     *
     * @return void
     */
    public function testCarriesSetupStatusAndWalkthroughPreferences(): void
    {
        $this->setupStatus->method('payload')->willReturn(['version' => 3, 'completed' => true, 'steps' => []]);
        $this->config->method('getUserValue')->willReturnCallback(
            static fn (string $user, string $app, string $key, string $default): string => match ($key) {
                'pref_walkthrough_seen_version' => '2',
                default => '',
            }
        );

        $this->listener->handle(event: self::render(app: 'opencatalogi', template: 'index'));

        $this->assertSame(expected: ['version' => 3, 'completed' => true, 'steps' => []], actual: $this->provided['setup_status']);
        $this->assertSame(expected: ['seen' => '2', 'progress' => null], actual: $this->provided['walkthrough_preferences']);

    }//end testCarriesSetupStatusAndWalkthroughPreferences()

    /**
     * A failing setup report leaves the status out, so the shell asks for it.
     *
     * @return void
     */
    public function testLeavesSetupStatusOutWhenTheReportFails(): void
    {
        $this->setupStatus->method('payload')->willThrowException(new RuntimeException('register gone'));
        $this->config->method('getUserValue')->willReturn('');

        $this->listener->handle(event: self::render(app: 'opencatalogi', template: 'index'));

        $this->assertArrayNotHasKey(key: 'setup_status', array: $this->provided);
        $this->assertSame(expected: ['seen' => null, 'progress' => null], actual: $this->provided['walkthrough_preferences']);

    }//end testLeavesSetupStatusOutWhenTheReportFails()

    /**
     * Other templates, other apps and anonymous renders get nothing.
     *
     * @return void
     */
    public function testIgnoresOtherTemplatesAndAnonymousPages(): void
    {
        $this->setupStatus->expects($this->never())->method('payload');

        $this->listener->handle(event: self::render(app: 'opencatalogi', template: 'public'));
        $this->listener->handle(event: self::render(app: 'files', template: 'index'));

        $anonymous = $this->createMock(originalClassName: IUserSession::class);
        $anonymous->method('getUser')->willReturn(null);
        $listener = new ProvideShellStateListener(
            initialState: $this->recordingState(),
            setupStatus: $this->setupStatus,
            config: $this->config,
            userSession: $anonymous
        );
        $listener->handle(event: self::render(app: 'opencatalogi', template: 'index'));

        $this->assertSame(expected: [], actual: $this->provided);

    }//end testIgnoresOtherTemplatesAndAnonymousPages()

    /**
     * The walkthrough key is the manifest's completionConfigKey.
     *
     * @return void
     */
    public function testWalkthroughKeyIsTheManifests(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../../src/manifest.json'), true);

        $this->assertSame(
            expected: $manifest['walkthrough']['completionConfigKey'],
            actual: ProvideShellStateListener::WALKTHROUGH_KEY
        );

    }//end testWalkthroughKeyIsTheManifests()
}//end class
