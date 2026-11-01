<?php

/**
 * Golden test for PublicationsController::index().
 *
 * Every case replays a public list read over recording doubles and compares
 * the answer (status, body, headers) and every call the controller made, in
 * order, to the recording in fixtures/publication-index-golden.json.
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

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for PublicationsController::index().
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @uses   \OCA\OpenCatalogi\Controller\CatalogSlugQuery
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses   \OCA\OpenCatalogi\Controller\FoundPublication
 * @uses   \OCA\OpenCatalogi\Controller\PublicationRead
 * @uses   \OCA\OpenCatalogi\Contract\PublicationUsage
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeIds
 */
class PublicationIndexGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-index-golden.json';

	/**
	 * Everything asked and logged, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases.
	 *
	 * A case sets: catalog (null for an unknown slug), id, params (request
	 * parameters), headers (request headers), stored (objects per
	 * `field:value:register:schema`), location (what findObjectLocation answers),
	 * signedIn, openregister (installed or not) and throw (a lookup that throws).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$scoped = ['slug' => 'woo', 'title' => 'Woo', 'registers' => [1], 'schemas' => [3, 4]];
		$rows = ['results' => [['id' => 'a', 'empty' => ''], 'entity', 'raw'], 'total' => 3];

		return [
			'unknown catalog' => [['catalog' => null]],
			'no schemas' => [['catalog' => ['slug' => 'woo', 'title' => 'Woo', 'registers' => [1], 'schemas' => []]]],
			'schemas named by slug only' => [['catalog' => ['slug' => 'woo', 'registers' => [1], 'schemas' => ['besluit']]]],
			'guard drops every schema' => [['catalog' => $scoped, 'guardDrops' => true]],
			'plain list' => [['catalog' => $scoped, 'result' => $rows]],
			'keeps empty values' => [['catalog' => $scoped, 'params' => ['_empty' => 'true'], 'result' => $rows]],
			'no results key' => [['catalog' => $scoped, 'result' => ['total' => 0]]],
			'results not a list' => [['catalog' => $scoped, 'result' => ['results' => 'x']]],
			'extend as text' => [['catalog' => $scoped, 'params' => ['extend' => '@self.a, b', 'q' => 'x'], 'result' => $rows]],
			'_extend as list' => [['catalog' => $scoped, 'params' => ['_extend' => ['@self.a', 5], 'q' => 'x'], 'result' => $rows]],
			'both extend keys' => [['catalog' => $scoped, 'params' => ['extend' => '@self.b', '_extend' => '@self.c'], 'result' => $rows]],
			'extend a number' => [['catalog' => $scoped, 'params' => ['extend' => 3], 'result' => $rows]],
			'empty extend left alone' => [['catalog' => $scoped, 'params' => ['extend' => '', '_extend' => []], 'result' => $rows]],
			'signed in caller' => [['catalog' => $scoped, 'signedIn' => true, 'result' => $rows]],
			'revalidation answers 304' => [['catalog' => $scoped, 'headers' => ['If-None-Match' => '*'], 'result' => $rows]],
			'openregister missing' => [['catalog' => $scoped, 'openregister' => false]],
			'search throws' => [['catalog' => $scoped, 'throw' => true]],
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
	public function testIndexMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$response = $this->controller(case: $case)->index('woo');
		$headers = $response->getHeaders();
		unset($headers['X-Request-Id'], $headers['Content-Security-Policy'], $headers['Feature-Policy'], $headers['X-Robots-Tag']);
		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'headers' => $headers,
			'log' => $this->log,
		];

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

	}//end testIndexMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return PublicationsController The controller.
	 */
	private function controller(array $case): PublicationsController {
		$catalogs = $this->createMock(CatalogiServiceInterface::class);
		$catalogs->method('getCatalogBySlug')->willReturn($case['catalog']);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn((($case['openregister'] ?? true) === true) ? ['openregister'] : []);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new PublicationsController(
			'opencatalogi',
			$this->request(case: $case),
			$this->createMock(PublicationServiceInterface::class),
			$catalogs,
			$this->queries(case: $case),
			$this->container(case: $case),
			$appManager,
			$this->logger(),
			$l10n,
			$this->counter()
		);

	}//end controller()

	/**
	 * A request double answering the case's parameters and headers.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return IRequest The double.
	 */
	private function request(array $case): IRequest {
		$params = ($case['params'] ?? []);
		$headers = ($case['headers'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[$name] ?? ''));
		$request->method('getMethod')->willReturn(($case['method'] ?? 'GET'));

		return $request;
	}//end request()

	/**
	 * A query-service double that records the guard and the location lookup.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return PublicationQueryServiceInterface The double.
	 */
	private function queries(array $case): PublicationQueryServiceInterface {
		$queries = $this->createMock(PublicationQueryServiceInterface::class);
		$queries->method('applyCatalogReadRuleGuard')->willReturnCallback(
			function (array $catalog) use ($case): array {
				$this->log[] = ['guard' => $catalog];
				if (($case['guardDrops'] ?? false) === true) {
					$catalog['schemas'] = [];
				}

				return $catalog + ['guarded' => true];
			}
		);
		$queries->method('buildCatalogSearchQuery')->willReturnCallback(
			function (array $catalog, array $queryParams, object $objectService): array {
				$this->log[] = ['build' => [$catalog['slug'], $queryParams, get_class($objectService) !== '']];
				return ['_schemas' => $catalog['schemas'], 'params' => $queryParams];
			}
		);
		$queries->method('stripEmptyValues')->willReturnCallback(
			function (array $data): array {
				$this->log[] = ['strip' => $data];
				return array_filter($data, static fn ($value): bool => $value !== '');
			}
		);
		$queries->method('resolveSchemaAndRegisterObjects')->willReturnCallback(
			function (array $catalog): array {
				$this->log[] = ['resolve' => $catalog['slug']];
				return ['schemas' => ['s'], 'registers' => ['r']];
			}
		);

		return $queries;
	}//end queries()

	/**
	 * A container double handing out the services the read asks for, recording each ask.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(array $case): ContainerInterface {
		$session = $this->createMock(IUserSession::class);
		$session->method('isLoggedIn')->willReturn(($case['signedIn'] ?? false));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$withheld = $this->createMock(WithheldFromPublicationInterface::class);
		$withheld->method('addForCatalog')->willReturnCallback(
			function (array $response, array $catalog, string $publicationId): array {
				$this->log[] = ['withheld' => [$catalog['slug'], $publicationId]];
				return $response + ['withheld' => [$publicationId]];
			}
		);
		$schemaOrg = $this->createMock(SchemaOrgServiceInterface::class);
		$schemaOrg->method('buildPublicationNode')->willReturnCallback(
			function (array $object, array $catalog, string $catalogSlug): array {
				$this->log[] = ['schemaOrg' => [$object['id'], $catalog['slug'], $catalogSlug]];
				return ['@type' => 'CreativeWork', 'identifier' => $object['id']];
			}
		);

		$services = [
			IUserSession::class => $session,
			IAppConfig::class => $config,
			WithheldFromPublicationInterface::class => $withheld,
			SchemaOrgServiceInterface::class => $schemaOrg,
			'OCA\OpenRegister\Service\ObjectService' => $this->objects(case: $case),
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services): mixed {
				$this->log[] = ['get' => $id];
				return $services[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * An ObjectService double that answers uuid and slug searches from the case's store.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return object The double.
	 */
	private function objects(array $case): object {
		$log = &$this->log;

		return new class($log, $case) {
			public function __construct(
				private array &$log,
				private array $case,
			) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if (($this->case['throw'] ?? false) === true) {
					throw new RuntimeException('secret detail');
				}

				$result = ($this->case['result'] ?? []);
				if (is_array($result['results'] ?? null) === true) {
					$result['results'] = array_map(
						static fn ($row) => ($row === 'entity') ? new class {
							public function jsonSerialize(): array {
								return ['id' => 'e', 'blank' => ''];
							}
						} : $row,
						$result['results']
					);
				}

				return $result;
			}
		};

	}//end objects()

	/**
	 * A logger double recording every line.
	 *
	 * @return LoggerInterface The double.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['trace']);
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A usage counter double recording each count.
	 *
	 * @return UsageCounterServiceInterface The double.
	 */
	private function counter(): UsageCounterServiceInterface {
		$counter = $this->createMock(UsageCounterServiceInterface::class);
		$counter->method('increment')->willReturnCallback(
			function ($usage, ?string $userAgent = null): bool {
				$this->log[] = ['count' => [$usage->publicationId, $usage->kind, $usage->catalog]];
				return true;
			}
		);

		return $counter;
	}//end counter()
}//end class
