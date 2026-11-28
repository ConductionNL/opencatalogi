<?php

/**
 * OpenCatalogi app-shell initial-state listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Controller\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller\Setup;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserSession;
use Throwable;

/**
 * Puts the setup status and the two walkthrough preferences into every SPA
 * page. Without them the app shell (nextcloud-vue CnAppRoot) asks
 * GET /api/setup/status and GET /api/preferences/{key} twice on every page
 * open; it reads `setup_status` and `walkthrough_preferences` instead when the
 * page carries them, and asks as before when it does not.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
 */
class ProvideShellStateListener implements IEventListener
{

    /**
     * The manifest's `walkthrough.completionConfigKey`; the shell also reads `<key>-progress`.
     */
    public const WALKTHROUGH_KEY = 'walkthrough_seen_version';

    /**
     * Constructor.
     *
     * @param IInitialState     $initialState This app's initial state.
     * @param SetupStatusReport $setupStatus  The document GET /api/setup/status answers.
     * @param IConfig           $config       The user values the preferences live in.
     * @param IUserSession      $userSession  The signed-in user.
     */
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly SetupStatusReport $setupStatus,
        private readonly IConfig $config,
        private readonly IUserSession $userSession,
    ) {

    }//end __construct()

    /**
     * Provide the shell state for this app's SPA `index` page, for a signed-in user.
     *
     * A failed setup report leaves `setup_status` out, so the shell asks the
     * endpoint as before; the page itself never fails on it.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
     */
    public function handle(Event $event): void
    {
        if (($event instanceof BeforeTemplateRenderedEvent) === false) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getApp() !== AppIdentity::APP_ID || $response->getTemplateName() !== 'index') {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        try {
            $this->initialState->provideInitialState('setup_status', $this->setupStatus->payload());
        } catch (Throwable) {
            // The shell then asks GET /api/setup/status itself, which reports the error.
        }

        $this->initialState->provideInitialState(
            'walkthrough_preferences',
            [
                'seen'     => $this->preference(userId: $user->getUID(), key: self::WALKTHROUGH_KEY),
                'progress' => $this->preference(userId: $user->getUID(), key: self::WALKTHROUGH_KEY.'-progress'),
            ]
        );

    }//end handle()

    /**
     * A stored preference as GET /api/preferences/{key} answers it: null when unset.
     *
     * @param string $userId The user.
     * @param string $key    The preference key, without the `pref_` namespace.
     *
     * @return string|null The stored value.
     *
     * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
     */
    private function preference(string $userId, string $key): ?string
    {
        $value = $this->config->getUserValue($userId, AppIdentity::APP_ID, 'pref_'.$key, '');
        if ($value === '') {
            return null;
        }

        return $value;

    }//end preference()
}//end class
