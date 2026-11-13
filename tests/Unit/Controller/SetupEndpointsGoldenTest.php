<?php

/**
 * Golden test for the setup endpoints: status, config and every action.
 *
 * Every case runs a sequence of setup calls over recording doubles and compares
 * each answer, every collaborator call and the config left behind to the
 * recording in fixtures/setup-endpoints-golden.json.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use OCA\OpenCatalogi\Contract\ConnectionReporterInterface;
use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Controller\Setup\SetupActions;
use OCA\OpenCatalogi\Controller\Setup\SetupCatalogSteps;
use OCA\OpenCatalogi\Controller\Setup\SetupDemoDataSteps;
use OCA\OpenCatalogi\Controller\Setup\SetupFederationSteps;
use OCA\OpenCatalogi\Controller\Setup\SetupSettingsStep;
use OCA\OpenCatalogi\Controller\Setup\SetupStatusReport;
use OCA\OpenCatalogi\Controller\Setup\SetupStepRunner;
use OCA\OpenCatalogi\Controller\SetupController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Unit\Support\SaveCallShape;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the setup endpoints.
 *
 * @covers \OCA\OpenCatalogi\Controller\SetupController
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupActions
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupCatalogSteps
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupDemoDataSteps
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupFederationSteps
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupSettingsStep
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupStatusReport
 * @covers \OCA\OpenCatalogi\Controller\Setup\SetupStepRunner
 */
class SetupEndpointsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/setup-endpoints-golden.json';

	private const WIRED = [
		'catalog_register' => '1',
		'catalog_schema' => '2',
		'publication_register' => '5',
		'publication_schema' => '6',
		'listing_register' => '7',
		'listing_schema' => '8',
	];

	private const DIRECTORY = 'https://directory.example/api/directory';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: the world and the calls run against it.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$all = [['action', 'load-demo-data'], ['action', 'install-demo-data'], ['action', 'skip-demo-data'], ['action', 'reload-settings'], ['action', 'create-first-catalog'], ['action', 'connect-federation'], ['action', 'sync-all-directories'], ['action', 'complete'], ['action', 'nonsense']];
		$listing = ['object' => ['directory' => self::DIRECTORY]];

		return [
			'status anonymous' => [['user' => false, 'calls' => [['status']]]],
			'status fresh install' => [['config' => [], 'calls' => [['status']]]],
			'status wired, nothing chosen' => [['config' => self::WIRED, 'calls' => [['status']]]],
			'status wired, scope, catalog and listing' => [['config' => self::WIRED + ['default_catalog_scope' => 'public'], 'catalogs' => [['id' => 1]], 'listings' => [$listing], 'calls' => [['status']]]],
			'status flat listing of another directory' => [['config' => self::WIRED, 'listings' => [['directory' => 'https://other.example'], ['directory' => self::DIRECTORY]], 'calls' => [['status']]]],
			'status listing search throws' => [['config' => self::WIRED, 'fail' => ['search'], 'calls' => [['status']]]],
			'status listings not a list' => [['config' => self::WIRED, 'listings' => 4, 'calls' => [['status']]]],
			'status openregister missing' => [['config' => self::WIRED, 'openregister' => false, 'calls' => [['status']]]],
			'status completed version and decided demo' => [['config' => self::WIRED + ['onboarding_completed_version' => '3', 'demo_data_decided' => 'skipped', 'default_catalog_scope' => 'private'], 'calls' => [['status']]]],
			'status scope chosen but no catalog' => [['config' => self::WIRED + ['default_catalog_scope' => 'public'], 'calls' => [['status']]]],
			'status catalog without a scope' => [['config' => self::WIRED, 'catalogs' => [['id' => 1]], 'calls' => [['status']]]],
			'status listing of another directory only' => [['config' => self::WIRED, 'listings' => [['object' => ['directory' => 'https://other.example']]], 'calls' => [['status']]]],
			'federation when only the announce fails' => [['fail' => ['broadcast'], 'calls' => [['action', 'connect-federation']]]],
			'status picked dataset only' => [['config' => ['demo_dataset' => 'demo'], 'calls' => [['status']]]],
			'config writes whitelisted keys' => [['params' => ['default_catalog_scope' => 'private', 'default_catalog_groups' => 'a,b', 'demo_dataset' => 7, 'catalog_register' => 'x', 'other' => 'y'], 'calls' => [['config']]]],
			'config writes nothing' => [['params' => ['other' => 'y'], 'calls' => [['config']]]],
			'config without a reporter' => [['reporter' => false, 'params' => ['default_directory_url' => 'https://d.example'], 'calls' => [['config']]]],
			'every action on a wired install' => [['config' => self::WIRED + ['demo_dataset' => 'demo'], 'calls' => $all]],
			'every action when everything fails' => [['config' => self::WIRED + ['demo_dataset' => 'demo'], 'fail' => ['install', 'settings', 'search', 'save', 'sync', 'broadcast', 'cron'], 'calls' => $all]],
			'every action without openregister' => [['config' => self::WIRED, 'openregister' => false, 'calls' => $all]],
			'load with nothing picked' => [['config' => [], 'calls' => [['action', 'load-demo-data'], ['status']]]],
			'load the none dataset' => [['config' => ['demo_dataset' => 'none'], 'calls' => [['action', 'load-demo-data'], ['status']]]],
			'load a posted known dataset' => [['dataset' => 'woo', 'calls' => [['action', 'load-demo-data'], ['status']]]],
			'load a posted none' => [['dataset' => 'none', 'calls' => [['action', 'load-demo-data']]]],
			'load a posted unknown dataset' => [['dataset' => 'evil', 'calls' => [['action', 'load-demo-data']]]],
			'load a posted array' => [['dataset' => ['x'], 'calls' => [['action', 'load-demo-data']]]],
			'load a posted number' => [['dataset' => 3, 'calls' => [['action', 'load-demo-data']]]],
			'reload settings that wire the registers' => [['config' => [], 'settingsWire' => true, 'calls' => [['action', 'reload-settings']]]],
			'reload settings that leave them unwired' => [['config' => ['catalog_register' => '1'], 'calls' => [['action', 'reload-settings']]]],
			'federation with created listings and no advertise' => [['sync' => ['listings_created' => 2, 'listings_updated' => '1'], 'broadcast' => [self::DIRECTORY => false], 'calls' => [['action', 'connect-federation']]]],
			'federation with nothing new and advertised' => [['sync' => [], 'broadcast' => [self::DIRECTORY => true, 'x' => true], 'calls' => [['action', 'connect-federation']]]],
			'federation advertised for another url' => [['sync' => ['listings_updated' => 3], 'broadcast' => ['https://other' => true], 'calls' => [['action', 'connect-federation']]]],
			'sync with counts' => [['cron' => ['synced_directories' => 2, 'failed_directories' => 1, 'total_directories' => 5], 'calls' => [['action', 'sync-all-directories']]]],
			'sync without a total' => [['cron' => ['synced_directories' => 2, 'failed_directories' => 1], 'calls' => [['action', 'sync-all-directories']]]],
			'sync empty' => [['cron' => [], 'calls' => [['action', 'sync-all-directories']]]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSetupMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$values = ($case['config'] ?? self::WIRED);
		$controller = $this->controller(case: $case, values: $values);

		$answers = [];
		foreach ($case['calls'] as $call) {
			try {
				$response = match ($call[0]) {
					'status' => $controller->status(),
					'config' => $controller->config(),
					'action' => $controller->action($call[1]),
				};
				$answers[] = ['call' => $call, 'status' => $response->getStatus(), 'data' => $response->getData()];
			} catch (\Throwable $e) {
				$answers[] = ['call' => $call, 'thrown' => get_class($e) . ': ' . $e->getMessage()];
			}
		}

		ksort($values);
		$actual = ['answers' => $answers, 'log' => $this->log, 'config' => $values];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testSetupMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param array<string, string> $values The app config, changed in place by writes.
	 *
	 * @return SetupController The controller.
	 */
	private function controller(array $case, array &$values): SetupController {
		$fail = ($case['fail'] ?? []);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values): string {
				$this->log[] = ['read' => [$app, $key, $default]];
				return ($values[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$values): bool {
				$this->log[] = ['write' => [$app, $key, $value]];
				$values[$key] = $value;
				return true;
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$args) use ($case, $fail): mixed {
				$query = $args[0];
				$this->log[] = ['search' => self::passedArguments(method: 'searchObjects', args: $args)];
				if (in_array('search', $fail, true) === true) {
					throw new RuntimeException('search down');
				}

				if (($query['@self']['register'] ?? null) === self::WIRED['listing_register']) {
					$listings = ($case['listings'] ?? []);
					if (is_array($listings) === false) {
						return $listings;
					}

					return array_map(static fn (array $data): object => self::row(data: $data), $listings);
				}

				return ($case['catalogs'] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($fail): mixed {
				$this->log[] = ['save' => self::passedArguments(method: 'saveObject', args: $args)];
				if (in_array('save', $fail, true) === true) {
					throw new RuntimeException('save broke');
				}

				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objects): mixed {
				$this->log[] = ['get' => $id];
				if (($case['openregister'] ?? true) === false) {
					throw new RuntimeException('not installed');
				}

				return $objects;
			}
		);

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('loadSettings')->willReturnCallback(
			function (bool $force = false) use ($case, $fail, &$values): array {
				$this->log[] = ['loadSettings' => $force];
				if (in_array('settings', $fail, true) === true) {
					throw new \Exception('import broke');
				}

				if (($case['settingsWire'] ?? false) === true) {
					$values = self::WIRED + $values;
				}

				return [];
			}
		);

		$demo = $this->createMock(DemoDataServiceInterface::class);
		$demo->method('listChoices')->willReturnCallback(
			function (): array {
				$this->log[] = ['listChoices'];
				return [['id' => 'demo', 'title' => 'Demo'], ['id' => 'woo', 'title' => 'Woo'], ['id' => 'none', 'title' => 'None']];
			}
		);
		$demo->method('install')->willReturnCallback(
			function () use ($fail): array {
				$this->log[] = ['install'];
				if (in_array('install', $fail, true) === true) {
					throw new \Error('install broke');
				}

				return ['objects' => 12, 'schemas' => 3];
			}
		);

		$directory = $this->createMock(DirectoryServiceInterface::class);
		$directory->method('getDefaultDirectoryUrl')->willReturnCallback(
			function (): string {
				$this->log[] = ['defaultDirectory'];
				return self::DIRECTORY;
			}
		);
		$directory->method('syncDirectory')->willReturnCallback(
			function (string $url) use ($case, $fail): array {
				$this->log[] = ['syncDirectory' => $url];
				if (in_array('sync', $fail, true) === true) {
					throw new \Exception('directory down');
				}

				return ($case['sync'] ?? ['listings_created' => 1]);
			}
		);
		$directory->method('doCronSync')->willReturnCallback(
			function () use ($case, $fail): array {
				$this->log[] = ['doCronSync'];
				if (in_array('cron', $fail, true) === true) {
					throw new \Exception('cron down');
				}

				return ($case['cron'] ?? ['synced_directories' => 1]);
			}
		);

		$broadcast = $this->createMock(BroadcastServiceInterface::class);
		$broadcast->method('broadcast')->willReturnCallback(
			function (?string $url = null) use ($case, $fail): array {
				$this->log[] = ['broadcast' => $url];
				if (in_array('broadcast', $fail, true) === true) {
					throw new \Exception('broadcast down');
				}

				return ($case['broadcast'] ?? [self::DIRECTORY => true]);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'T:' . vsprintf($text, $parameters)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn((($case['user'] ?? true) === true ? $this->createMock(IUser::class) : null));

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params'] ?? []);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null) use ($case): mixed {
				$this->log[] = ['param' => $key];
				return (array_key_exists('dataset', $case) === true && $key === 'dataset' ? $case['dataset'] : $default);
			}
		);

		$reporter = null;
		if (($case['reporter'] ?? true) === true) {
			$reporter = $this->createMock(ConnectionReporterInterface::class);
			$reporter->method('refreshFromSave')->willReturnCallback(
				function (array $savedKeys): array {
					$this->log[] = ['refreshFromSave' => $savedKeys];
					return [];
				}
			);
		}

		return $this->build(
			collaborators: [
				'request' => $request,
				'config' => $config,
				'settings' => $settings,
				'demo' => $demo,
				'directory' => $directory,
				'broadcast' => $broadcast,
				'container' => $container,
				'l10n' => $l10n,
				'logger' => $logger,
				'session' => $session,
				'reporter' => $reporter,
			]
		);

	}//end controller()

	/**
	 * The controller under test over the given doubles.
	 *
	 * @param array<string, mixed> $collaborators The doubles by role.
	 *
	 * @return SetupController The controller.
	 */
	private function build(array $collaborators): SetupController {
		return self::setupController(
			'opencatalogi',
			$collaborators['request'],
			$collaborators['config'],
			$collaborators['settings'],
			$collaborators['demo'],
			$collaborators['directory'],
			$collaborators['broadcast'],
			$collaborators['container'],
			$collaborators['l10n'],
			$collaborators['logger'],
			$collaborators['session'],
			$collaborators['reporter']
		);

	}//end build()

	/**
	 * The arguments of an ObjectService call by parameter name, without those left at their default.
	 *
	 * A mock receives every parameter of the loaded ObjectService, so a positional list
	 * would record that class's signature: the test stub's locally and OpenRegister's
	 * own in CI. Named and without defaults, the record is what the controller passed.
	 *
	 * @param string $method The ObjectService method.
	 * @param array<int, mixed> $args The arguments the mock received.
	 *
	 * @return array<string, mixed> The passed arguments by name.
	 */
	private static function passedArguments(string $method, array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $args[$position] === $parameter->getDefaultValue()) {
				continue;
			}

			$named[$parameter->getName()] = $args[$position];
		}

		return ($method === 'saveObject' ? SaveCallShape::namedInOldOrder(args: $named) : $named);
	}//end passedArguments()

	/**
	 * A listing row that serialises to the given data.
	 *
	 * @param array<string, mixed> $data The serialised data.
	 *
	 * @return object The row.
	 */
	private static function row(array $data): object {
		return new class($data) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The serialised data.
			 */
			public function __construct(
				private array $data,
			) {
			}

			/**
			 * The serialised data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end row()

	/**
	 * A SetupController wired over the given collaborators the way the container wires it.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param SettingsServiceInterface $settings Settings service.
	 * @param DemoDataServiceInterface $demo Demo data service.
	 * @param DirectoryServiceInterface $directory Directory service.
	 * @param BroadcastServiceInterface $broadcast Broadcast service.
	 * @param ContainerInterface $container Container.
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger Logger.
	 * @param IUserSession $session User session.
	 * @param ConnectionReporterInterface|null $reporter Connection reporter.
	 *
	 * @return SetupController The controller.
	 */
	private static function setupController(
		string $appName,
		IRequest $request,
		IAppConfig $config,
		SettingsServiceInterface $settings,
		DemoDataServiceInterface $demo,
		DirectoryServiceInterface $directory,
		BroadcastServiceInterface $broadcast,
		ContainerInterface $container,
		IL10N $l10n,
		LoggerInterface $logger,
		IUserSession $session,
		?ConnectionReporterInterface $reporter = null,
	): SetupController {
		$catalog = new SetupCatalogSteps($config, $container, $l10n, $logger);
		return new SetupController(
			$appName,
			$request,
			$l10n,
			$session,
			new SetupStatusReport($config, $demo, $directory, $catalog),
			new SetupActions(
				$config,
				$l10n,
				new SetupStepRunner(
					new SetupDemoDataSteps($config, $demo, $l10n, $logger),
					new SetupSettingsStep($settings, $catalog, $l10n, $logger),
					$catalog,
					new SetupFederationSteps($directory, $broadcast, $l10n, $logger)
				),
				$reporter
			)
		);

	}//end setupController()
}//end class
