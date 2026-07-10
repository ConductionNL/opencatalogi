<?php
/**
 * OpenCatalogi Setup Controller.
 *
 * Implements the ADR-042 first-time-setup server contract: the status, config
 * and privileged-action endpoints that the shared @conduction/nextcloud-vue
 * CnSetupWizard / useSetupStatus engine calls. Status is derived from real
 * app-config + object state (never a stored per-step flag); the actions run with
 * system privileges (_rbac:false) so seeding works on every install path.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller implementing the ADR-042 first-time-setup contract for OpenCatalogi.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/setup-wizard-server-contract/tasks.md#task-2
 */
class SetupController extends Controller
{
    /**
     * The setup contract version. Must match `setup.version` in src/manifest.json.
     * Bumping it re-gates the wizard for steps that key off completion.
     *
     * @var integer
     */
    private const SETUP_VERSION = 2;

    /**
     * App-config keys the config endpoint is allowed to write. A whitelist keeps
     * the generic setup-config POST from writing arbitrary configuration.
     *
     * @var string[]
     */
    private const WRITABLE_CONFIG_KEYS = [
        'default_catalog_scope',
        'default_directory_url',
    ];

    /**
     * Required register/schema keys whose presence means publishing is wired.
     *
     * @var string[]
     */
    private const REQUIRED_REGISTER_KEYS = [
        'catalog_register',
        'catalog_schema',
        'publication_register',
        'listing_register',
    ];

    /**
     * Constructor.
     *
     * @param string             $appName          The app id.
     * @param IRequest           $request          The request.
     * @param IAppConfig         $config           App configuration.
     * @param SettingsService    $settingsService  Settings service (register import).
     * @param DirectoryService   $directoryService Directory service (federation sync).
     * @param BroadcastService   $broadcastService Broadcast service (announce self to a directory).
     * @param ContainerInterface $container        Container, to resolve OpenRegister ObjectService.
     * @param IL10N              $l10n             Localization.
     * @param LoggerInterface    $logger           Logger.
     * @param IUserSession       $userSession      Current user session (login guard).
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SettingsService $settingsService,
        private readonly DirectoryService $directoryService,
        private readonly BroadcastService $broadcastService,
        private readonly ContainerInterface $container,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Report the first-time-setup status (ADR-042 §4).
     *
     * Returns `{ version, completed, steps }` where each step's `done` is computed
     * from real state. Readable by any signed-in user so the wizard engine can
     * decide gating during boot; it exposes only booleans, no sensitive values.
     *
     * Routed at /api/setup/status (registered before the /api/{catalogSlug}
     * wildcard so `setup` is never mistaken for a catalog slug).
     *
     * @return JSONResponse The setup status payload.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005
     */
    public function status(): JSONResponse
    {
        // The wizard-boot status is for signed-in users only; reject anonymous
        // callers explicitly (defence-in-depth alongside the @NoAdminRequired
        // login gate — ADR-005 / no-admin-idor).
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }

        $registersWired = $this->registersConfigured();
        $scopeChosen    = ($this->config->getValueString($this->appName, 'default_catalog_scope', '') !== '');
        $catalogReady   = $this->catalogExists();
        $federationDone = $this->defaultDirectorySubscribed();

        $steps = [
            'welcome'            => ['done' => true],
            'config-check'       => ['done' => $registersWired],
            'catalog-scope'      => ['done' => $scopeChosen],
            'create-catalog'     => ['done' => $catalogReady],
            'connect-federation' => ['done' => $federationDone],
            'done'               => ['done' => ($registersWired === true && $scopeChosen === true && $catalogReady === true)],
        ];

        // Completed = every gating (required) step is satisfied. The optional
        // federation step never blocks completion.
        $completed = ($registersWired === true && $scopeChosen === true && $catalogReady === true);

        return new JSONResponse(
            [
                'version'   => self::SETUP_VERSION,
                'completed' => $completed,
                'steps'     => $steps,
            ]
        );

    }//end status()

    /**
     * Persist posted setup config keys (ADR-042 §4).
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware enforces the admin
     * gate) and CSRF-protected (no @NoCSRFRequired). #[AuthorizedAdminSetting]
     * makes it auditable via NC delegated-admin. Only whitelisted keys are written.
     *
     * @return JSONResponse The saved keys.
     *
     * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function config(): JSONResponse
    {
        $params = $this->request->getParams();
        $saved  = [];

        foreach (self::WRITABLE_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $params) === false) {
                continue;
            }

            $this->config->setValueString($this->appName, $key, (string) $params[$key]);
            $saved[] = $key;
        }

        return new JSONResponse(['saved' => $saved]);

    }//end config()

    /**
     * Run a privileged first-time-setup action (ADR-042 §4).
     *
     * Admin-only + CSRF-protected. Each action orchestrates an existing service
     * with system privileges. Returns `{ success, message }` — the wizard treats
     * `success: false` as a non-fatal step error (HTTP 200) so optional steps stay
     * skippable.
     *
     * @param string $actionId The action to run.
     *
     * @return JSONResponse The action result.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-create-first-catalog-privileged-action-onb-006
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function action(string $actionId): JSONResponse
    {
        switch ($actionId) {
            case 'reload-settings':
                return $this->reloadSettings();

            case 'create-first-catalog':
                return $this->createFirstCatalog();

            case 'connect-federation':
                return $this->connectFederation();

            case 'complete':
                $this->config->setValueString($this->appName, 'onboarding_completed_version', (string) self::SETUP_VERSION);
                return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Setup complete.')]);

            default:
                return new JSONResponse(
                    ['success' => false, 'message' => $this->l10n->t('Unknown setup action.')],
                    Http::STATUS_BAD_REQUEST
                );
        }//end switch

    }//end action()

    /**
     * Re-import the register configuration (config-check remediation).
     *
     * @return JSONResponse The result.
     */
    private function reloadSettings(): JSONResponse
    {
        try {
            $this->settingsService->loadSettings(force: false);
        } catch (\Exception $e) {
            $this->logger->error('Setup reload-settings failed: '.$e->getMessage(), ['app' => $this->appName]);
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
        }

        if ($this->registersConfigured() === false) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => $this->l10n->t('Publishing registers are still not configured. Is OpenRegister installed and enabled?'),
                ]
            );
        }

        return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Publishing prerequisites are configured.')]);

    }//end reloadSettings()

    /**
     * Create the user's first catalog (idempotent — no-op when one exists).
     *
     * @return JSONResponse The result.
     */
    private function createFirstCatalog(): JSONResponse
    {
        if ($this->catalogExists() === true) {
            // Mark onboarding seen so a later empty state does not re-gate.
            $this->config->setValueString($this->appName, 'onboarding_completed_version', (string) self::SETUP_VERSION);
            return new JSONResponse(['success' => true, 'message' => $this->l10n->t('You already have a catalog.')]);
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['success' => false, 'message' => $this->l10n->t('OpenRegister is not available.')]);
        }

        $register = $this->config->getValueString($this->appName, 'catalog_register', '');
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        if ($register === '' || $schema === '') {
            return new JSONResponse(['success' => false, 'message' => $this->l10n->t('Configure the catalog register and schema first.')]);
        }

        $scope = $this->config->getValueString($this->appName, 'default_catalog_scope', 'public');

        try {
            $objectService->saveObject(
                object: [
                    'title'       => $this->l10n->t('My first catalog'),
                    'summary'     => $this->l10n->t('Your first OpenCatalogi catalog — add publications to start sharing them openly.'),
                    'description' => $this->l10n->t('Created during first-time setup. Rename it and adjust its access to suit your organisation.'),
                    // A public default scope makes the catalog discoverable in the federated directory.
                    'listed'      => ($scope === 'public'),
                    'status'      => 'development',
                ],
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false,
            );
        } catch (\Exception $e) {
            $this->logger->error('Setup create-first-catalog failed: '.$e->getMessage(), ['app' => $this->appName]);
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
        }

        // Onboarding has produced a catalog; record it so the gate clears for good.
        $this->config->setValueString($this->appName, 'onboarding_completed_version', (string) self::SETUP_VERSION);

        return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Your first catalog was created.')]);

    }//end createFirstCatalog()

    /**
     * Connect to the federated network — pull peer listings AND announce this instance.
     *
     * Federation is pull-based: a fresh instance only sees peers whose URLs are
     * already stored on the remote directory. Without a matching push, a brand-
     * new install always reports "0 new, 0 updated" and admins have no signal
     * whether their instance is discoverable to others. This step therefore does
     * two things in sequence and reports both outcomes in the response:
     *
     * 1. Pull: sync listings FROM the directory into this instance. Existing
     *    behaviour.
     * 2. Push: broadcast this instance's directory URL to the remote directory
     *    so it can sync back and add us to its catalog. `syncDirectory` already
     *    triggers an internal broadcast when the remote does not yet know us,
     *    but the outcome is silently swallowed — this explicit call captures it
     *    and lets the admin see whether self-registration succeeded.
     *
     * Both sub-steps are non-fatal: the wizard reports success as long as the
     * pull HTTP call itself did not throw. `doCronSync` will keep the state
     * fresh over time regardless of this step's outcome.
     *
     * @return JSONResponse The result envelope (success, message, details).
     */
    private function connectFederation(): JSONResponse
    {
        $directoryUrl = $this->directoryService->getDefaultDirectoryUrl();

        try {
            $result = $this->directoryService->syncDirectory($directoryUrl);
        } catch (\Exception $e) {
            $this->logger->warning('Setup connect-federation sync failed: '.$e->getMessage(), ['app' => $this->appName]);
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => $this->l10n->t('Could not reach the directory right now. You can skip this — it will sync automatically later.'),
                ]
            );
        }

        $created = (int) ($result['listings_created'] ?? 0);
        $updated = (int) ($result['listings_updated'] ?? 0);

        $advertised = false;
        try {
            $broadcastResults = $this->broadcastService->broadcast($directoryUrl);
            $advertised       = (isset($broadcastResults[$directoryUrl]) === true && $broadcastResults[$directoryUrl] === true);
        } catch (\Exception $e) {
            $this->logger->warning('Setup connect-federation advertise failed: '.$e->getMessage(), ['app' => $this->appName]);
        }

        return new JSONResponse(
            [
                'success' => true,
                'message' => $this->buildFederationMessage($created, $updated, $advertised),
                'details' => [
                    'listings_created' => $created,
                    'listings_updated' => $updated,
                    'advertised'       => $advertised,
                    'directory_url'    => $directoryUrl,
                ],
            ]
        );

    }//end connectFederation()

    /**
     * Compose the admin-facing message describing the pull + push outcome.
     *
     * Kept separate so message-shape unit tests can exercise every combination
     * (zero-listings + advertise-ok / zero-listings + advertise-fail / non-zero +
     * both) without wiring up the full DirectoryService + BroadcastService flow.
     *
     * @param integer $created    New listings pulled from the directory.
     * @param integer $updated    Existing listings updated during the pull.
     * @param boolean $advertised True when the broadcast to the directory succeeded.
     *
     * @return string The composed message.
     */
    private function buildFederationMessage(int $created, int $updated, bool $advertised): string
    {
        $parts = [];

        if ($created > 0 || $updated > 0) {
            $parts[] = $this->l10n->t(
                text: 'Fetched %1$d new and %2$d updated listing(s) from the directory.',
                parameters: [$created, $updated]
            );
        } else {
            $parts[] = $this->l10n->t(
                'The directory has no other peer instances registered yet — federation activates automatically once other instances join.'
            );
        }

        if ($advertised === true) {
            $parts[] = $this->l10n->t(
                'This instance was announced to the directory and is now discoverable to other peers.'
            );
        } else {
            $parts[] = $this->l10n->t(
                'This instance could not be announced to the directory (it may not be reachable from the directory host). Federation still works one-way; retry later or announce it manually.'
            );
        }

        return implode(' ', $parts);

    }//end buildFederationMessage()

    /**
     * Whether all required publishing registers/schemas are configured.
     *
     * @return boolean True when every required key is non-empty.
     */
    private function registersConfigured(): bool
    {
        foreach (self::REQUIRED_REGISTER_KEYS as $key) {
            if ($this->config->getValueString($this->appName, $key, '') === '') {
                return false;
            }
        }

        return true;

    }//end registersConfigured()

    /**
     * Whether at least one catalog exists, or onboarding has already been completed.
     *
     * The onboarding-completed fallback keeps the create-catalog gate from
     * re-triggering on an instance whose catalogs were later removed.
     *
     * @return boolean True when a catalog is present or onboarding is done.
     */
    private function catalogExists(): bool
    {
        if ($this->config->getValueString($this->appName, 'onboarding_completed_version', '') === (string) self::SETUP_VERSION) {
            return true;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register = $this->config->getValueString($this->appName, 'catalog_register', '');
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        if ($register === '' || $schema === '') {
            return false;
        }

        try {
            $catalogs = $objectService->searchObjects(
                ['@self' => ['register' => $register, 'schema' => $schema]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Exception $e) {
            return false;
        }

        return is_array($catalogs) === true && count($catalogs) > 0;

    }//end catalogExists()

    /**
     * Whether the default national directory is already a known listing.
     *
     * @return boolean True when a listing references the default directory URL.
     */
    private function defaultDirectorySubscribed(): bool
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register = $this->config->getValueString($this->appName, 'listing_register', '');
        $schema   = $this->config->getValueString($this->appName, 'listing_schema', '');
        if ($register === '' || $schema === '') {
            return false;
        }

        $defaultUrl = $this->directoryService->getDefaultDirectoryUrl();

        try {
            $listings = $objectService->searchObjects(
                ['@self' => ['register' => $register, 'schema' => $schema]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Exception $e) {
            return false;
        }

        if (is_array($listings) === false) {
            return false;
        }

        foreach ($listings as $listing) {
            $data = $listing->jsonSerialize();
            $obj  = ($data['object'] ?? $data);
            if (($obj['directory'] ?? null) === $defaultUrl) {
                return true;
            }
        }

        return false;

    }//end defaultDirectorySubscribed()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The service, or null when unavailable.
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            return null;
        }

    }//end getObjectService()
}//end class
