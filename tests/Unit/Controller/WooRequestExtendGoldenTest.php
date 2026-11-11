<?php

/**
 * Golden test for extending a request's statutory term.
 *
 * Every world is recorded once and replayed from the fixture: the response,
 * the calls the term engine got, and the stored request.
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

require_once __DIR__ . '/WooRequestControllerTest.php';

use OCA\OpenCatalogi\Controller\RequestRecords;
use OCA\OpenCatalogi\Controller\WooRequestController;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\TermRefusedException;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for WooRequestController::extend.
 *
 * @covers \OCA\OpenCatalogi\Controller\WooRequestController
 * @covers \OCA\OpenCatalogi\Controller\RequestRecords
 * @uses \OCA\OpenCatalogi\Service\Woo\WooRequestService
 * @uses \OCA\OpenCatalogi\Service\Woo\WooRequestStore
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class WooRequestExtendGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/woo-request-extend-golden.json';

	/**
	 * The term engine's calls.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		return [
			'works' => [['rationale' => '  The request covers many documents.  ']],
			'no rationale' => [['rationale' => null]],
			'blank rationale' => [['rationale' => "  \t "]],
			'unknown request' => [['rationale' => 'x', 'stored' => false]],
			'no term' => [['rationale' => 'x', 'request' => ['termTimer' => '  ']]],
			'term missing' => [['rationale' => 'x', 'request' => ['termTimer' => null]]],
			'padded term' => [['rationale' => 'x', 'request' => ['status' => 'decided', 'termTimer' => ' timer-9 ']]],
			'engine refuses' => [['rationale' => 'x', 'engine' => 'refuse']],
			'engine unavailable' => [['rationale' => 'x', 'engine' => 'down']],
			'no user' => [['rationale' => 'x', 'user' => false]],
			'register unresolved' => [['rationale' => 'x', 'register' => false]],
		];
	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testExtendMatchesTheRecording(array $world): void {
		$this->calls = [];
		$objects = new WooRequestFakeObjectService();
		if (($world['stored'] ?? true) === true) {
			$objects->objects['req-1'] = array_filter(
				array_merge(
					['id' => 'req-1', 'reference' => 'WOO-2026-ABCDEF', 'status' => 'in_progress', 'termTimer' => 'timer-1'],
					($world['request'] ?? [])
				),
				static fn ($value): bool => $value !== null
			);
		}

		$response = $this->controller(world: $world, objects: $objects)->extend('req-1');
		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'calls' => $this->calls,
			'stored' => ($objects->objects['req-1'] ?? null),
		];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testExtendMatchesTheRecording()

	/**
	 * The controller of a world.
	 *
	 * @param array<string, mixed> $world The world.
	 * @param WooRequestFakeObjectService $objects The register.
	 *
	 * @return WooRequestController
	 */
	private function controller(array $world, WooRequestFakeObjectService $objects): WooRequestController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (['rationale' => $world['rationale']][$key] ?? $default)
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(($world['register'] ?? true) === true ? '42' : '');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $config) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objects,
					IAppConfig::class => $config,
				};
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$userSession = $this->createMock(IUserSession::class);
		if (($world['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession->method('getUser')->willReturn($user);
		}

		return new WooRequestController(
			'opencatalogi',
			$request,
			$l10n,
			$userSession,
			new RequestRecords(new WooRequestService(), new WooRequestStore($config, new OpenRegisterLookup($container, $appManager))),
			$this->terms(engine: ($world['engine'] ?? 'works'))
		);

	}//end controller()

	/**
	 * A term engine that records its extensions and works, refuses or is down.
	 *
	 * @param string $engine works, refuse or down.
	 *
	 * @return StatutoryTerm
	 */
	private function terms(string $engine): StatutoryTerm {
		$terms = $this->getMockBuilder(StatutoryTerm::class)
			->disableOriginalConstructor()
			->onlyMethods(['arm', 'extendOnce', 'pause', 'resume', 'describe'])
			->getMock();
		$terms->method('extendOnce')->willReturnCallback(
			function (string $timerUuid, string $rationale, string $actor) use ($engine): array {
				$this->calls[] = ['extendOnce', $timerUuid, $rationale, $actor];
				if ($engine === 'refuse') {
					throw new TermRefusedException('already extended once');
				}

				if ($engine === 'down') {
					throw new TermEngineUnavailableException('no engine');
				}

				return ['timer' => $timerUuid, 'state' => 'running', 'dueAt' => '2026-04-15T09:00:00+00:00', 'extended' => true];
			}
		);

		return $terms;
	}//end terms()
}//end class
