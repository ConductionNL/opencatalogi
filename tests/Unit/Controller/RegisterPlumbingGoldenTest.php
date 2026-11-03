<?php

/**
 * Golden test for how the register-bound controllers resolve their register
 * configuration and reach OpenRegister's ObjectService.
 *
 * Every public endpoint of every controller that resolves a configured
 * register/schema pair runs in six worlds over recording doubles. Each answer
 * and every collaborator call is compared to the recording in
 * fixtures/register-plumbing-golden.json. The controllers are built by
 * constructor reflection, so the same test drives any wiring of the same doubles.
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

use OCA\OpenCatalogi\Contract\InspectionServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Controller\CommentPeriodController;
use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Controller\DepublicationController;
use OCA\OpenCatalogi\Controller\GlossaryController;
use OCA\OpenCatalogi\Controller\InspectionController;
use OCA\OpenCatalogi\Controller\ListingsController;
use OCA\OpenCatalogi\Controller\NoticeBoardController;
use OCA\OpenCatalogi\Controller\OoapiController;
use OCA\OpenCatalogi\Controller\PublicationStateController;
use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/**
 * Golden test for the register plumbing of the register-bound controllers.
 *
 * @covers \OCA\OpenCatalogi\Controller\CatalogiController
 * @covers \OCA\OpenCatalogi\Controller\CommentPeriodController
 * @covers \OCA\OpenCatalogi\Controller\CommunityController
 * @covers \OCA\OpenCatalogi\Controller\DepublicationController
 * @covers \OCA\OpenCatalogi\Controller\GlossaryController
 * @covers \OCA\OpenCatalogi\Controller\InspectionController
 * @covers \OCA\OpenCatalogi\Controller\ListingsController
 * @covers \OCA\OpenCatalogi\Controller\NoticeBoardController
 * @covers \OCA\OpenCatalogi\Controller\OoapiController
 * @covers \OCA\OpenCatalogi\Controller\PublicationStateController
 * @covers \OCA\OpenCatalogi\Controller\ServiceCatalogueController
 * @covers \OCA\OpenCatalogi\Controller\ThemesController
 * @covers \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @covers \OCA\OpenCatalogi\Controller\ReadsPublicationRegister
 * @covers \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @covers \OCA\OpenCatalogi\Controller\RegisterAccess
 * @covers \OCA\OpenCatalogi\Controller\CommunityPages
 * @covers \OCA\OpenCatalogi\Controller\ForwardsRegisterAccess
 * @covers \OCA\OpenCatalogi\Controller\PublicationTransitions
 * @covers \OCA\OpenCatalogi\Controller\BannerDismissals
 * @covers \OCA\OpenCatalogi\Controller\InspectionRecords
 * @uses   \OCA\OpenCatalogi\Contract\CommentPeriodRequest
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
class RegisterPlumbingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/register-plumbing-golden.json';

	private const CONTROLLERS = [
		CatalogiController::class,
		CommentPeriodController::class,
		CommunityController::class,
		DepublicationController::class,
		GlossaryController::class,
		InspectionController::class,
		ListingsController::class,
		NoticeBoardController::class,
		OoapiController::class,
		PublicationStateController::class,
		ServiceCatalogueController::class,
		ThemesController::class,
	];

	private const WORLDS = ['resolver', 'fallback', 'unset', 'halfset', 'noconfig', 'absent', 'anonymous'];

	private const PARAMS = [
		'_limit' => '5',
		'q' => 'x',
		'id' => 'id-1',
		'title' => 'T',
		'status' => 'open',
		'reason' => 'r',
		'catalogSlug' => 'cat',
		'message' => 'm',
	];

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The label of every double made for this case.
	 *
	 * @var \SplObjectStorage<object, string>
	 */
	private \SplObjectStorage $labels;

	/**
	 * The collaborators made for this case, by type.
	 *
	 * @var array<string, mixed>
	 */
	private array $made = [];

	/**
	 * The world of the running case.
	 *
	 * @var string
	 */
	private string $world = 'resolver';

	/**
	 * Every controller endpoint in every world.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::CONTROLLERS as $class) {
			$short = (new ReflectionClass($class))->getShortName();
			foreach (self::endpoints(class: $class) as $method) {
				foreach (self::WORLDS as $world) {
					$cases[$short . '::' . $method . ' / ' . $world] = [$class, $method, $world];
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string $class The controller class.
	 * @param string $method The endpoint.
	 * @param string $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testEndpointMatchesTheRecording(string $class, string $method, string $world): void {
		$name = $this->dataName();
		$this->world = $world;
		$this->labels = new \SplObjectStorage();
		$this->made = [];

		$controller = $this->controller(class: $class);
		$reflection = new ReflectionMethod($controller, $method);
		$args = [];
		foreach ($reflection->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$args[] = 'id-1';
		}

		try {
			$answer = $this->normalise(value: $reflection->invokeArgs($controller, $args));
		} catch (\Throwable $e) {
			$answer = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
		}

		$actual = json_decode((string)json_encode(['answer' => $answer, 'log' => $this->log]), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testEndpointMatchesTheRecording()

	/**
	 * The public endpoints a controller declares itself.
	 *
	 * @param string $class The controller class.
	 *
	 * @return array<int, string> The method names.
	 */
	private static function endpoints(string $class): array {
		$methods = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === $class && $method->isConstructor() === false && $method->isStatic() === false) {
				$methods[] = $method->getName();
			}
		}

		sort($methods);
		return $methods;
	}//end endpoints()

	/**
	 * The controller, every constructor argument filled by type.
	 *
	 * @param string $class The controller class.
	 *
	 * @return object The controller.
	 */
	private function controller(string $class): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($parameter->getName() === 'appName') {
				$args[] = 'opencatalogi';
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && ($type instanceof ReflectionNamedType) === true && $type->allowsNull() === false && $type->isBuiltin() === false && interface_exists($type->getName()) === false && str_starts_with($type->getName(), 'OCA\\OpenCatalogi\\Controller\\') === true) {
				break;
			}

			$args[] = $this->collaborator(type: ($type instanceof ReflectionNamedType) ? $type->getName() : 'mixed');
		}

		return new $class(...$args);
	}//end controller()

	/**
	 * One constructor collaborator; the shared doubles are made once per case.
	 *
	 * @param string $type The declared type.
	 *
	 * @return mixed The collaborator.
	 */
	private function collaborator(string $type): mixed {
		if (array_key_exists($type, $this->made) === true) {
			return $this->made[$type];
		}

		$value = match ($type) {
			IRequest::class => $this->request(),
			IAppConfig::class => $this->appConfig(),
			ContainerInterface::class => $this->container(),
			IAppManager::class => $this->appManager(),
			IUserSession::class => $this->userSession(),
			IL10N::class => $this->l10n(),
			ServiceCatalogueServiceInterface::class => $this->catalogue(),
			InspectionServiceInterface::class => $this->double(
				type: $type,
				label: 'InspectionService',
				depth: 0,
				answers: ['resolveLink' => function (array $inspection, string $token): array {
					$this->log[] = ['InspectionService.resolveLink' => [$inspection, $token]];
					return ['readable' => true, 'reason' => null, 'documents' => [], 'endDate' => '2026-12-01'];
				},
				]
			),
			'OCA\\OpenCatalogi\\Contract\\OpenRegisterLookupInterface' => new \OCA\OpenCatalogi\Service\OpenRegisterLookup(
				$this->collaborator(type: ContainerInterface::class),
				$this->collaborator(type: IAppManager::class)
			),
			'OCA\\OpenCatalogi\\Controller\\BannerDismissals' => new $type(
				$this->collaborator(type: IAppConfig::class),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\BannerServiceInterface')
			),
			'OCA\\OpenCatalogi\\Controller\\InspectionRecords' => new $type(
				$this->collaborator(type: InspectionServiceInterface::class),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Controller\\RegisterAccess')
			),
			'OCA\\OpenCatalogi\\Controller\\PublicationTransitions' => new $type(
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\PublicationStateServiceInterface'),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\DepublicationServiceInterface'),
				$this->collaborator(type: ContainerInterface::class),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Controller\\RegisterAccess')
			),
			'OCA\\OpenCatalogi\\Controller\\CommunityPages' => new $type(
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\StatusPageServiceInterface'),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\AtomFeedServiceInterface'),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\MarkupRenderServiceInterface'),
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Controller\\RegisterAccess')
			),
			'OCA\\OpenCatalogi\\Controller\\RegisterAccess' => new $type(
				$this->collaborator(type: 'OCA\\OpenCatalogi\\Contract\\OpenRegisterLookupInterface'),
				$this->collaborator(type: ServiceCatalogueServiceInterface::class)
			),
			default => $this->double(type: $type, label: (new ReflectionClass(explode('#', $type)[0]))->getShortName(), depth: 0),
		};

		$this->made[$type] = $value;
		return $value;
	}//end collaborator()

	/**
	 * The request: fixed parameters and a listed Origin.
	 *
	 * @return IRequest The request.
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(self::PARAMS);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (self::PARAMS[$key] ?? $default)
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? 'https://a.example' : '')
		);
		$request->method('getMethod')->willReturn('GET');
		$this->labels[$request] = 'IRequest';
		return $request;
	}//end request()

	/**
	 * The app config: registers configured unless the world leaves them unset.
	 *
	 * @return IAppConfig The app config.
	 */
	private function appConfig(): IAppConfig {
		$read = function (string $app, string $key, string $default = '') {
			$this->log[] = ['IAppConfig.getValueString' => [$app, $key, $default]];
			if ($key === 'cors_allowed_origins') {
				return 'https://a.example';
			}

			if (str_ends_with($key, '_schema') === true && $this->world === 'halfset') {
				return '';
			}

			if (str_ends_with($key, '_register') === true || str_ends_with($key, '_schema') === true) {
				return ($this->world === 'unset' ? '' : 'cfg-' . $key);
			}

			return $default;
		};
		return $this->double(type: IAppConfig::class, label: 'IAppConfig', depth: 0, answers: ['getValueString' => $read]);
	}//end appConfig()

	/**
	 * The server container: the resolver, the app config and OpenRegister's services, per world.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			function (string $id): bool {
				$this->log[] = ['container.has' => $id];
				return true;
			}
		);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$this->log[] = ['container.get' => $id];
				$resolverWorld = in_array($this->world, ['resolver', 'absent', 'anonymous'], true);
				if ($id === 'OCA\\OpenRegister\\Service\\RegisterResolverService' && $resolverWorld === true) {
					return $this->resolver();
				}

				if ($id === IAppConfig::class && $this->world !== 'noconfig') {
					return $this->collaborator(type: IAppConfig::class);
				}

				if (str_starts_with($id, 'OCA\\OpenRegister\\') === true && $this->world !== 'absent' && str_ends_with($id, 'RegisterResolverService') === false) {
					return $this->openRegisterService(id: $id);
				}

				throw new RuntimeException('Not in the container: ' . $id);
			}
		);
		$this->labels[$container] = 'container';
		return $container;
	}//end container()

	/**
	 * An OpenRegister service: the ObjectService with answers, any other one recorded.
	 *
	 * @param string $id The service id.
	 *
	 * @return object The service.
	 */
	private function openRegisterService(string $id): object {
		if ($id !== ObjectService::class) {
			return $this->recorder(label: (new ReflectionClass($id))->getShortName());
		}

		return $this->collaborator(type: ObjectService::class . '#answers');
	}//end openRegisterService()

	/**
	 * The register resolver: ids derived from the keys.
	 *
	 * @return object The resolver.
	 */
	private function resolver(): object {
		return $this->recorder(label: 'resolver', answers: ['resolveRegisterId' => 'res-reg', 'resolveSchemaId' => 'res-sch']);
	}//end resolver()

	/**
	 * The app manager: OpenRegister installed unless the world is "absent".
	 *
	 * @return IAppManager The app manager.
	 */
	private function appManager(): IAppManager {
		$installed = ($this->world !== 'absent');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			function (string $app) use ($installed): bool {
				$this->log[] = ['IAppManager.isInstalled' => $app];
				return $installed;
			}
		);
		$appManager->method('getInstalledApps')->willReturnCallback(
			function () use ($installed): array {
				$this->log[] = ['IAppManager.getInstalledApps' => []];
				return ($installed === true ? ['files', 'openregister'] : ['files']);
			}
		);
		$this->labels[$appManager] = 'IAppManager';
		return $appManager;
	}//end appManager()

	/**
	 * The user session: "alice", or nobody in the "anonymous" world.
	 *
	 * @return IUserSession The user session.
	 */
	private function userSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$this->labels[$user] = 'IUser';
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->world === 'anonymous' ? null : $user);
		$session->method('isLoggedIn')->willReturn($this->world !== 'anonymous');
		$this->labels[$session] = 'IUserSession';
		return $session;
	}//end userSession()

	/**
	 * The translator: the text, with its parameters when there are any.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters = []): string => ($parameters === [] ? $text : $text . ' ' . json_encode($parameters))
		);
		$this->labels[$l10n] = 'IL10N';
		return $l10n;
	}//end l10n()

	/**
	 * The service catalogue: the ObjectService, or unreadable in the "absent" world.
	 *
	 * @return ServiceCatalogueServiceInterface The service catalogue.
	 */
	private function catalogue(): ServiceCatalogueServiceInterface {
		$objectService = function (): object {
			$this->log[] = ['catalogue.getObjectService' => []];
			if ($this->world === 'absent') {
				throw new CatalogueUnreadableException(message: 'The service catalogue cannot be read because OpenRegister is not installed.');
			}

			return $this->collaborator(type: ObjectService::class . '#answers');
		};
		$read = function (array $entryConfig, array $caseTypeConfig, array $filters = []): array {
			$this->log[] = ['catalogue.readCatalogue' => $this->normalise(value: [$entryConfig, $caseTypeConfig, $filters])];
			return ['entries' => [['id' => 'entry-1', 'available' => false]], 'caseTypes' => []];
		};
		return $this->double(type: ServiceCatalogueServiceInterface::class, label: 'catalogue', depth: 0, answers: ['getObjectService' => $objectService, 'readCatalogue' => $read]);
	}//end catalogue()

	/**
	 * A recording double of a class or interface: every call logged, typed defaults returned.
	 *
	 * @param string $type The class or interface, optionally suffixed "#answers" for the ObjectService.
	 * @param string $label The label in the log.
	 * @param int $depth How deep in a chain of returned doubles this one is.
	 * @param array<string, \Closure> $answers Answers for named methods, in place of the logged default.
	 *
	 * @return MockObject The double.
	 */
	private function double(string $type, string $label, int $depth, array $answers = []): MockObject {
		$searches = str_ends_with($type, '#answers');
		$type = explode('#', $type)[0];
		if ($searches === true) {
			$label = 'ObjectService';
		}

		$double = $this->createMock($type);
		$this->labels[$double] = $label;
		foreach ((new ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isStatic() === true || $method->isFinal() === true || $method->isConstructor() === true || str_starts_with($method->getName(), '__') === true) {
				continue;
			}

			$name = $method->getName();
			if (isset($answers[$name]) === true) {
				$double->method($name)->willReturnCallback($answers[$name]);
				continue;
			}

			$double->method($name)->willReturnCallback(
				function (mixed ...$args) use ($double, $method, $label, $depth, $searches, $name): mixed {
					$this->log[] = [$label . '.' . $name => $this->arguments(method: $method, args: $args)];
					if ($searches === true && in_array($name, ['searchObjectsPaginated', 'searchObjects', 'findAll'], true) === true) {
						return $this->searchAnswer(paginated: $name === 'searchObjectsPaginated');
					}

					return $this->defaultFor(type: $method->getReturnType(), self: $double, depth: $depth, label: $label . '.' . $name);
				}
			);
		}

		return $double;
	}//end double()

	/**
	 * A plain recorder for an untyped service: every call logged, fixed answers or null.
	 *
	 * @param string $label The label in the log.
	 * @param array<string, mixed> $answers Answers per method.
	 *
	 * @return object The recorder.
	 */
	private function recorder(string $label, array $answers = []): object {
		$log = function (string $name, array $args) use ($label): void {
			$this->log[] = [$label . '.' . $name => $this->normalise(value: $args)];
		};

		$recorder = new class($log, $answers) {
			/**
			 * @param \Closure $log Where calls are logged.
			 * @param array<string, mixed> $answers Answers per method.
			 */
			public function __construct(
				private \Closure $log,
				private array $answers,
			) {
			}

			/**
			 * @param string $name The method.
			 * @param array<int, mixed> $args The arguments.
			 *
			 * @return mixed The answer.
			 */
			public function __call(string $name, array $args): mixed {
				($this->log)($name, $args);
				return ($this->answers[$name] ?? null);
			}
		};
		$this->labels[$recorder] = $label;
		return $recorder;
	}//end recorder()

	/**
	 * The ObjectService's answer to a search.
	 *
	 * @param bool $paginated Whether the paginated shape is asked.
	 *
	 * @return array<mixed> The answer.
	 */
	private function searchAnswer(bool $paginated): array {
		$rows = [['id' => 'obj-1', 'title' => 'One', 'slug' => 'one', '@self' => ['id' => 'obj-1', 'register' => '1', 'schema' => '2']]];
		if ($paginated === false) {
			return $rows;
		}

		return ['results' => $rows, 'total' => 1, 'limit' => 5, 'offset' => 0, 'page' => 1, 'pages' => 1];
	}//end searchAnswer()

	/**
	 * The passed arguments by parameter name, trailing defaults left out.
	 *
	 * @param ReflectionMethod $method The method.
	 * @param array<int, mixed> $args The arguments as PHPUnit passes them.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments(ReflectionMethod $method, array $args): array {
		$named = [];
		foreach ($method->getParameters() as $index => $parameter) {
			if (array_key_exists($index, $args) === false) {
				break;
			}

			$named[$parameter->getName()] = $args[$index];
		}

		foreach (array_reverse($method->getParameters()) as $parameter) {
			$key = $parameter->getName();
			if (array_key_exists($key, $named) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === false || $named[$key] !== $parameter->getDefaultValue()) {
				break;
			}

			unset($named[$key]);
		}

		return $this->normalise(value: $named);
	}//end arguments()

	/**
	 * A typed default for a return type.
	 *
	 * @param ReflectionType|null $type The return type.
	 * @param MockObject $self The double, for self and static.
	 * @param int $depth How deep the double is.
	 * @param string $label The label for a returned double.
	 *
	 * @return mixed The default.
	 */
	private function defaultFor(?ReflectionType $type, MockObject $self, int $depth, string $label): mixed {
		if ($type === null || $type->allowsNull() === true) {
			return null;
		}

		if ($type instanceof ReflectionUnionType) {
			$type = $type->getTypes()[0];
		}

		if (($type instanceof ReflectionNamedType) === false) {
			return null;
		}

		return match ($type->getName()) {
			'array', 'iterable' => [],
			'string' => '',
			'int' => 0,
			'float' => 0.0,
			'bool' => false,
			'void', 'null', 'mixed', 'never' => null,
			'object' => new \stdClass(),
			'self', 'static' => $self,
			default => ($depth < 2 ? $this->double(type: $type->getName(), label: $label, depth: $depth + 1) : $this->createStub($type->getName())),
		};

	}//end defaultFor()

	/**
	 * A JSON-safe view of a value: responses by status, data and headers (without the per-request id), doubles by label.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The view.
	 */
	private function normalise(mixed $value): mixed {
		if (is_object($value) === true && isset($this->labels[$value]) === true) {
			return 'double:' . $this->labels[$value];
		}

		if ($value instanceof Response) {
			$view = ['response' => (new ReflectionClass($value))->getShortName(), 'status' => $value->getStatus()];
			if (method_exists($value, 'getData') === true) {
				$view['data'] = $this->normalise(value: $value->getData());
			}

			$headers = $value->getHeaders();
			unset($headers['X-Request-Id']);
			ksort($headers);
			$view['headers'] = $headers;
			return $view;
		}

		if ($value instanceof \Throwable) {
			return ['exception' => get_class($value), 'message' => $value->getMessage()];
		}

		if (is_object($value) === true) {
			if ($value instanceof \JsonSerializable) {
				return $this->normalise(value: $value->jsonSerialize());
			}

			return 'object:' . ((new ReflectionClass($value))->isAnonymous() === true ? 'anonymous' : get_class($value));
		}

		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true) {
			return preg_replace('/ passed in \S+ on line \d+/', ' passed', $value);
		}

		if (is_float($value) === true && is_nan($value) === true) {
			return 'NAN';
		}

		return $value;
	}//end normalise()
}//end class
