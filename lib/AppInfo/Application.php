<?php

/**
 * Main Application class for OpenCatalogi.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
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
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\AppInfo;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Controller\OpenRegisterRequiredController;
use OCA\OpenCatalogi\Controller\OpenRegisterRequiredHealthController;
use OCA\OpenCatalogi\Dashboard\DashboardRegistrar;
use OCA\OpenCatalogi\Listener\ListenerRegistrar;
use OCA\OpenCatalogi\Mcp\McpRegistrar;
use OCA\OpenCatalogi\Middleware\ApiContractMiddleware;
use OCA\OpenCatalogi\Observability\ObservabilityRegistrar;
use OCA\OpenCatalogi\Portal\PortalRegistrar;
use OCA\OpenCatalogi\Service\ServiceRegistrar;
use OCA\OpenCatalogi\SetupCheck\OpenRegisterVersionCheck;
use OCA\OpenCatalogi\Tool\ToolRegistrar;
use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Controller;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main Application class for OpenCatalogi.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Canonical national OpenCatalogi directory URL, see AppIdentity.
	 *
	 * @var string
	 */
	public const DEFAULT_DIRECTORY_URL = AppIdentity::DEFAULT_DIRECTORY_URL;

	/**
	 * Constructor.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);

	}//end __construct()

	/**
	 * Register app services and event listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Each component registers its own classes.
		(new DashboardRegistrar())->register($context);
		(new ListenerRegistrar())->register($context);
		(new McpRegistrar())->register($context);
		(new ObservabilityRegistrar())->register($context);
		(new PortalRegistrar())->register($context);
		(new ServiceRegistrar(appName: self::APP_ID))->register($context);
		(new ToolRegistrar())->register($context);

		// API-Version header and problem details on the JSON API (spec api-documentation).
		$context->registerMiddleware(ApiContractMiddleware::class);

		// ONB-009: tells an administrator when OpenRegister is too old for the
		// AppHost bindings below. Registering the class probes nothing.
		$context->registerSetupCheck(OpenRegisterVersionCheck::class);

		// ONB-009: every AppHost binding below asks standIn() first. On an
		// OpenRegister older than 2.0 the generics do not exist, and building
		// one was a fatal "Class not found" 500 on every page. standIn() answers
		// the route with a notice instead. The question is asked when a
		// controller is built for a request, never here: at register() time
		// OpenRegister is not on the autoloader yet (see OpenRegisterCompatibility).

		// AppHost observability adoption (ADR-040). The /api/health and
		// /api/metrics routes resolve to leaf-namespaced controller class names
		// (OCA\OpenCatalogi\AppHost\Controller\Generic{Health,Metrics}Controller)
		// that do not physically exist in this app; bind them to OpenRegister's
		// shared AppHost generics so the engine serves both endpoints from the
		// `observability` block of src/manifest.json. URL + contract unchanged.
		//
		// These MUST be registerService closures (not registerServiceAlias) so
		// this app's id is injected as the controllers' $appName — exactly like
		// the Dashboard/Preferences registrations below. A bare alias leaves
		// $appName to be autowired, and because the target class lives in the
		// OCA\OpenRegister namespace the cross-container fallback resolves it to
		// `openregister`, so /api/health + /api/metrics would report the wrong
		// app and load OpenRegister's manifest instead of this app's.
		$context->registerService(
			'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericHealthController',
			static function ($c) {
				$standIn = self::standIn(container: $c, health: true);
				if ($standIn !== null) {
					return $standIn;
				}

				return new GenericHealthController(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
				);
			}
		);
		$context->registerService(
			'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericMetricsController',
			static function ($c) {
				$standIn = self::standIn(container: $c, health: false);
				if ($standIn !== null) {
					return $standIn;
				}

				return new GenericMetricsController(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
				);
			}
		);

		// AppHost boilerplate adoption (ADR-040). The Dashboard (SPA page +
		// catch-all) and per-user Preferences controllers were byte-for-byte
		// copies of OpenRegister's shared AppHost generics, so bind this app's
		// conventional controller class names to the engine generics with the
		// leaf app id injected as $appName. URLs, route names and JSON
		// contracts are unchanged; the engine owns the (identical) auth
		// posture so the leaf can never drift it. The bespoke
		// DashboardController + PreferencesController classes were deleted.
		//
		// NOT adopted (kept bespoke — domain-entangled): SettingsController
		// (carries getPublishingOptions/updatePublishingOptions/getVersionInfo/
		// manualImport + a GET load() that runs the register.d fragment-merge
		// via the bespoke SettingsService — the generic SettingsController only
		// does index/create/load with force-reimport semantics and does not
		// reproduce that envelope), the OpenCatalogiAdmin AdminSettings (its
		// getAuthorizedAppConfig() declares an OpenCatalogi-specific allow-list
		// regex that the manifest-driven GenericAdminSettings does not
		// reproduce — required for delegated-admin gating), and the federation/
		// DCAT/catalog domain services + listeners.
		// The /api/preferences/{key} and / + /{path} routes resolve to
		// leaf-namespaced AppHost controller class names
		// (OCA\OpenCatalogi\AppHost\Controller\Generic{Dashboard,Preferences}Controller)
		// that do not physically exist in this app — same pattern as the
		// Health/Metrics adoption above. Register them as services that
		// construct the OpenRegister generics with this app's id injected as
		// $appName, so templates/index.php and the `pref_` user-value namespace
		// are scoped to opencatalogi, never OpenRegister.
		$context->registerService(
			'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericDashboardController',
			static function ($c) {
				$standIn = self::standIn(container: $c, health: false);
				if ($standIn !== null) {
					return $standIn;
				}

				return new GenericDashboardController(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest')
				);
			}
		);
		$context->registerService(
			'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericPreferencesController',
			static function ($c) {
				$standIn = self::standIn(container: $c, health: false);
				if ($standIn !== null) {
					return $standIn;
				}

				return new GenericPreferencesController(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest'),
					config: $c->get('OCP\\IConfig'),
					userSession: $c->get('OCP\\IUserSession')
				);
			}
		);

	}//end register()

	/**
	 * Boot the application.
	 *
	 * Imports the configuration once after a clean install (ONB-001), and
	 * registers the app-menu navigation entry via INavigationManager (see body).
	 *
	 * @param IBootContext $context The boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001
	 */
	public function boot(IBootContext $context): void {
		// ONB-001: a clean `app:install` / `app:enable` runs no <post-migration>
		// repair step, so InitializeSettings never imported the configuration.
		// Import it once here; FirstRunSettings records success and skips
		// every later request.
		$context->getAppContainer()->get(FirstRunSettings::class)->ensureInitialized();

		//
		// Register the app-menu navigation entry in PHP instead of info.xml.
		// The dashboard SPA is served by the engine-namespaced AppHost route
		// OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#page (see appinfo/routes.php),
		// whose generated route name contains backslashes. info.xml's <navigations><route>
		// is validated against the App Store's info.xsd pattern [0-9a-zA-Z_]+(\.[0-9a-zA-Z_]+){2},
		// which rejects that name (HTTP 400 on publish). IURLGenerator::linkToRoute() carries
		// no such constraint, so registering here keeps the app-menu href working AND lets the
		// release pass App Store validation.
		$server = $context->getServerContainer();
		$navigationManager = $server->get(\OCP\INavigationManager::class);
		$navigationManager->add(
			static function () use ($server) {
				$urlGenerator = $server->get(\OCP\IURLGenerator::class);
				$l10n = $server->get(\OCP\L10N\IFactory::class)->get(Application::APP_ID);

				return [
					'id' => Application::APP_ID,
					'order' => 10,
					'href' => $urlGenerator->linkToRoute('opencatalogi.oca\opencatalogi\apphost\controller\genericdashboard.page'),
					'icon' => $urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
					'name' => $l10n->t('Catalogi'),
					'type' => 'link',
				];
			}
		);
	}//end boot()

	/**
	 * The controller to answer an AppHost route with when OpenRegister is too
	 * old for the AppHost generics, or null when the generic can be built.
	 *
	 * Called from the registerService closures, so when a controller is built
	 * for a request, after every app has registered (ONB-009).
	 *
	 * @param ContainerInterface $container The app container.
	 * @param bool               $health    Whether the route is the public health endpoint.
	 *
	 * @return Controller|null The stand-in, or null when OpenRegister is new enough.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	private static function standIn(ContainerInterface $container, bool $health): ?Controller {
		$compatibility = $container->get(OpenRegisterCompatibility::class);
		if ($compatibility->appHostAvailable() === true) {
			return null;
		}

		$container->get(LoggerInterface::class)->warning(
			'OpenCatalogi needs OpenRegister ' . OpenRegisterCompatibility::REQUIRED_VERSION
				. ' or newer; ' . $compatibility->markerClass() . ' is missing, so a notice answers instead of the app.',
			['app' => self::APP_ID]
		);

		if ($health === true) {
			return new OpenRegisterRequiredHealthController(appName: self::APP_ID, request: $container->get('OCP\\IRequest'));
		}

		return new OpenRegisterRequiredController(
			appName: self::APP_ID,
			request: $container->get('OCP\\IRequest'),
			l: $container->get('OCP\\IL10N')
		);

	}//end standIn()
}//end class
