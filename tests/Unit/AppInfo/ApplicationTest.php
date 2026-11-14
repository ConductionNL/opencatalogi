<?php

/**
 * Application first-run settings import and OpenRegister compatibility guard.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use Closure;
use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\AppInfo\FirstRunSettings;
use OCA\OpenCatalogi\AppInfo\OpenRegisterCompatibility;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Controller\OpenRegisterRequiredController;
use OCA\OpenCatalogi\Controller\OpenRegisterRequiredHealthController;
use OCA\OpenCatalogi\SetupCheck\OpenRegisterVersionCheck;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * ONB-001: the configuration is imported once after a clean install.
 * ONB-009: on an OpenRegister without the AppHost engine every AppHost route
 * answers with a notice instead of a fatal 500, and an administrator is told.
 */
class ApplicationTest extends TestCase {

	/**
	 * App config values written through the mock.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * A FirstRunSettings over a stateful app config.
	 *
	 * @param array<int, string>     $installed The installed apps.
	 * @param SettingsServiceInterface $settings  The settings service the container hands out.
	 * @param LoggerInterface|null   $logger    The logger.
	 *
	 * @return FirstRunSettings
	 */
	private function firstRun(array $installed, SettingsServiceInterface $settings, ?LoggerInterface $logger=null): FirstRunSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$key] = $value;
				return true;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(SettingsServiceInterface::class)->willReturn($settings);

		return new FirstRunSettings(
			config: $config,
			appManager: $apps,
			container: $container,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end firstRun()

	/**
	 * The application, without the parent constructor: App::__construct()
	 * asks the server for its config, which the unit suite has none of, and
	 * register() and boot() use no state of their own.
	 *
	 * @return Application
	 */
	private function application(): Application {
		return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

	}//end application()

	public function testAFirstRequestImportsTheConfigurationOnceAndRecordsIt(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->once())->method('loadSettings')->with(false)->willReturn([]);
		$firstRun = $this->firstRun(installed: ['openregister', 'opencatalogi'], settings: $settings);

		$this->assertTrue($firstRun->ensureInitialized());
		$this->assertSame('true', $this->values['settings_initialized']);
		$this->assertFalse($firstRun->ensureInitialized(), 'the second request must not import again');

	}//end testAFirstRequestImportsTheConfigurationOnceAndRecordsIt()

	public function testAnInstanceAlreadyInitialisedImportsNothing(): void {
		$this->values['settings_initialized'] = 'true';
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->never())->method('loadSettings');

		$this->assertFalse($this->firstRun(installed: ['openregister'], settings: $settings)->ensureInitialized());

	}//end testAnInstanceAlreadyInitialisedImportsNothing()

	public function testWithoutOpenRegisterNothingIsImportedOrRecorded(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->never())->method('loadSettings');

		$this->assertFalse($this->firstRun(installed: ['opencatalogi'], settings: $settings)->ensureInitialized());
		$this->assertArrayNotHasKey('settings_initialized', $this->values);

	}//end testWithoutOpenRegisterNothingIsImportedOrRecorded()

	public function testAFailedImportIsLoggedNotRecordedAndDoesNotEscape(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->exactly(2))->method('loadSettings')->willThrowException(new RuntimeException('register import failed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->with($this->stringContains('tried again on the next request'));
		$firstRun = $this->firstRun(installed: ['openregister'], settings: $settings, logger: $logger);

		$this->assertFalse($firstRun->ensureInitialized());
		$this->assertArrayNotHasKey('settings_initialized', $this->values);
		$this->assertFalse($firstRun->ensureInitialized(), 'the next request tries again');

	}//end testAFailedImportIsLoggedNotRecordedAndDoesNotEscape()

	public function testBootRunsTheFirstRunImport(): void {
		$firstRun = $this->createMock(FirstRunSettings::class);
		$firstRun->expects($this->once())->method('ensureInitialized')->willReturn(true);
		$appContainer = $this->createMock(\OCP\AppFramework\IAppContainer::class);
		$appContainer->method('get')->with(FirstRunSettings::class)->willReturn($firstRun);
		$server = $this->createMock(\OCP\IServerContainer::class);
		$server->method('get')->with(INavigationManager::class)->willReturn($this->createMock(INavigationManager::class));
		$context = $this->createMock(IBootContext::class);
		$context->method('getAppContainer')->willReturn($appContainer);
		$context->method('getServerContainer')->willReturn($server);

		$this->application()->boot($context);

	}//end testBootRunsTheFirstRunImport()

	/**
	 * Run register() and collect the service factories and setup checks it registers.
	 *
	 * @return array{services: array<string, Closure>, checks: array<int, string>}
	 */
	private function registered(): array {
		$services = [];
		$checks = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, Closure $factory) use (&$services): void {
				$services[$name] = $factory;
			}
		);
		$context->method('registerSetupCheck')->willReturnCallback(
			static function (string $class) use (&$checks): void {
				$checks[] = $class;
			}
		);

		$this->application()->register($context);

		return ['services' => $services, 'checks' => $checks];

	}//end registered()

	/**
	 * A container answering what the AppHost factories ask for.
	 *
	 * @param OpenRegisterCompatibility $compatibility The probe.
	 * @param LoggerInterface           $logger        The logger.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function container(OpenRegisterCompatibility $compatibility, LoggerInterface $logger): ContainerInterface {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): mixed => match ($id) {
				OpenRegisterCompatibility::class => $compatibility,
				LoggerInterface::class => $logger,
				'OCP\\IRequest' => $this->createMock(IRequest::class),
				'OCP\\IL10N' => $l,
				default => $this->createMock(\stdClass::class),
			}
		);
		return $container;

	}//end container()

	public function testOnATooOldOpenRegisterEveryAppHostRouteAnswersWithTheNotice(): void {
		$registered = $this->registered();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(4))->method('warning')->with($this->stringContains('No\\Such\\AppHostController is missing'));
		$container = $this->container(new OpenRegisterCompatibility(markerClass: 'No\\Such\\AppHostController'), $logger);

		$prefix = 'OCA\\OpenCatalogi\\AppHost\\Controller\\';
		$this->assertInstanceOf(OpenRegisterRequiredHealthController::class, $registered['services'][$prefix . 'GenericHealthController']($container));
		foreach (['GenericMetricsController', 'GenericDashboardController', 'GenericPreferencesController'] as $name) {
			$this->assertInstanceOf(OpenRegisterRequiredController::class, $registered['services'][$prefix . $name]($container), $name);
		}

		$this->assertSame([OpenRegisterVersionCheck::class], $registered['checks']);

	}//end testOnATooOldOpenRegisterEveryAppHostRouteAnswersWithTheNotice()

	public function testWithTheAppHostEngineTheGenericIsBuiltAsBefore(): void {
		$registered = $this->registered();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$container = $this->container(new OpenRegisterCompatibility(markerClass: \stdClass::class), $logger);

		// The generics are not installed in the unit suite, so building one
		// fails on its class: proof the factory went for the generic, not the stand-in.
		$this->expectException(\Error::class);
		$this->expectExceptionMessage('OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController');

		$registered['services']['OCA\\OpenCatalogi\\AppHost\\Controller\\GenericDashboardController']($container);

	}//end testWithTheAppHostEngineTheGenericIsBuiltAsBefore()

	public function testTheProbeLooksForTheAppHostDashboardController(): void {
		$this->assertSame('OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController', (new OpenRegisterCompatibility())->markerClass());
		$this->assertTrue((new OpenRegisterCompatibility(markerClass: \stdClass::class))->appHostAvailable());
		$this->assertFalse((new OpenRegisterCompatibility(markerClass: 'No\\Such\\Class'))->appHostAvailable());

	}//end testTheProbeLooksForTheAppHostDashboardController()

	public function testTheSetupCheckTellsAnAdministratorWhatToUpdate(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));

		$missing = (new OpenRegisterVersionCheck(new OpenRegisterCompatibility(markerClass: 'No\\Such\\Class'), $l))->run();
		$this->assertSame('error', $missing->getSeverity());
		$this->assertStringContainsString('requires OpenRegister 2.0 or newer', (string)$missing->getDescription());
		$this->assertStringContainsString('No\\Such\\Class', (string)$missing->getDescription());

		$present = (new OpenRegisterVersionCheck(new OpenRegisterCompatibility(markerClass: \stdClass::class), $l))->run();
		$this->assertSame('success', $present->getSeverity());

	}//end testTheSetupCheckTellsAnAdministratorWhatToUpdate()

	public function testTheStandInsAnswer503WithTheReasonAndKeepTheAuthPosture(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$controller = new OpenRegisterRequiredController('opencatalogi', $this->createMock(IRequest::class), $l);

		$page = $controller->catchAll();
		$this->assertSame(503, $page->getStatus());
		$this->assertStringContainsString('OpenCatalogi requires OpenRegister 2.0 or newer.', $page->getData());
		$this->assertSame(503, $controller->setPreference(key: 'theme', value: 'dark')->getStatus());
		$this->assertSame('openregister_too_old', $controller->getPreference(key: 'theme')->getData()['error']);

		$health = (new OpenRegisterRequiredHealthController('opencatalogi', $this->createMock(IRequest::class)))->index();
		$this->assertSame(503, $health->getStatus());
		$this->assertSame('error', $health->getData()['status']);

		$this->assertNotEmpty((new ReflectionMethod(OpenRegisterRequiredHealthController::class, 'index'))->getAttributes(PublicPage::class));
		$this->assertNotEmpty((new ReflectionMethod(OpenRegisterRequiredController::class, 'page'))->getAttributes(NoAdminRequired::class));
		$this->assertEmpty(
			(new ReflectionMethod(OpenRegisterRequiredController::class, 'index'))->getAttributes(NoAdminRequired::class),
			'metrics stays administrator only, as on the generic'
		);

	}//end testTheStandInsAnswer503WithTheReasonAndKeepTheAuthPosture()
}//end class
