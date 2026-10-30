<?php

/**
 * Golden test for how the robots and search controllers reach OpenRegister.
 *
 * Each controller runs with OpenRegister absent, installed but not resolvable,
 * and working. Every case is recorded once and replayed from the fixture: the
 * response (status and body) and every container lookup made.
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

use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Controller\SearchController;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for how the robots and search controllers reach OpenRegister.
 *
 * @covers \OCA\OpenCatalogi\Controller\RobotsController
 * @covers \OCA\OpenCatalogi\Controller\SearchController
 * @uses   \OCA\OpenCatalogi\Controller\AnswersCacheably
 * @uses   \OCA\OpenCatalogi\Http\TextResponse
 * @uses   \OCA\OpenCatalogi\Service\SearchQueryTranslator
 * @uses   \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses   \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission
 * @uses   \OCA\OpenCatalogi\Service\TooiVocabularyService
 */
class OpenRegisterAvailabilityControllerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/openregister-availability-controller-golden.json';

	/**
	 * Every lookup made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The recorded cases: a controller endpoint and a world.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['robots', 'robots-accessor', 'search'] as $endpoint) {
			foreach (['absent', 'unresolvable', 'working'] as $world) {
				$cases[$endpoint . ' / ' . $world] = [$endpoint, $world];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $world    The OpenRegister world.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReachMatchesTheRecording(string $endpoint, string $world): void {
		$name = $this->dataName();
		$actual = ['outcome' => $this->outcome(endpoint: $endpoint, world: $world), 'asked' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testReachMatchesTheRecording()

	/**
	 * Run one endpoint: its response, or what it threw.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $world    The OpenRegister world.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome(string $endpoint, string $world): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $world !== 'absent');
		$appManager->method('getInstalledApps')->willReturn($world === 'absent' ? ['files'] : ['files', 'openregister']);

		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query): array {
				$this->log[] = 'search:' . json_encode($query);
				return [
					'results' => [
						new class {
							public function getSlug(): string {
								return 'cat-1';
							}

							public function getObject(): array {
								return ['hasWooSitemap' => true];
							}
						},
					],
				];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($world, $objects): object {
				$this->log[] = 'container:' . $id;
				if ($world === 'unresolvable') {
					throw new \RuntimeException('not resolvable');
				}

				return $objects;
			}
		);

		try {
			$response = $this->call(endpoint: $endpoint, deps: [$container, $appManager]);
			if (is_object($response) === false || method_exists($response, 'getStatus') === false) {
				return ['answer' => (is_object($response) === true ? 'object' : $response)];
			}

			return ['status' => $response->getStatus(), 'body' => $this->body(response: $response)];
		} catch (\Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

	}//end outcome()

	/**
	 * The body of a response, as text or data.
	 *
	 * @param object $response The response.
	 *
	 * @return mixed The body.
	 */
	private function body(object $response): mixed {
		if (method_exists($response, 'getData') === true) {
			return $response->getData();
		}

		return $response->render();

	}//end body()

	/**
	 * Build the controller and call the endpoint.
	 *
	 * @param string            $endpoint The endpoint.
	 * @param array<int, mixed> $deps     The container and app manager.
	 *
	 * @return mixed The response.
	 */
	private function call(string $endpoint, array $deps): mixed {
		[$container, $appManager] = $deps;
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['_search' => 'x']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://host');
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args = []): string => '/r/' . $route . '/' . implode('/', $args));
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $u): string => 'https://host' . $u);

		if ($endpoint === 'search') {
			$queries = $this->createMock(PublicationQueryService::class);
			$queries->method('assemblePublicSearchResults')->willReturnCallback(
				fn (array $queryParams, object $objectService): array => ['results' => [], 'with' => get_class($objectService) === get_class($objectService)]
			);
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($this->createMock(\OCP\IUser::class));

			return (new SearchController(
				appName: 'opencatalogi',
				request: $request,
				publicationService: $this->createMock(PublicationService::class),
				userSession: $session,
				l10n: $l10n,
				queryService: $queries,
				container: $container,
				appManager: $appManager,
				logger: $this->createMock(LoggerInterface::class)
			))->index();
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '1', 'catalog_schema' => '2']]);
		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new \OCA\OpenCatalogi\Service\TooiVocabularyService()),
		);
		$robots = new RobotsController('opencatalogi', $request, $settings, $container, $appManager, $urls, $l10n, $categories);
		if ($endpoint === 'robots-accessor') {
			$first = $robots->getObjectService();
			$second = $robots->getObjectService();
			return ($first === $second ? 'same-object' : 'other-object');
		}

		return $robots->index();

	}//end call()
}//end class
