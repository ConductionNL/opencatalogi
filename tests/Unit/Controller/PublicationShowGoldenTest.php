<?php

/**
 * Golden test for PublicationsController::show().
 *
 * Every case replays a public detail read over recording doubles and compares
 * the answer (status, body, headers) and every call the controller made, in
 * order, to the recording in fixtures/publication-show-golden.json.
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
use OCP\AppFramework\Db\DoesNotExistException;
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
 * Golden test for PublicationsController::show().
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @uses   \OCA\OpenCatalogi\Controller\CatalogSlugQuery
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses   \OCA\OpenCatalogi\Controller\FoundPublication
 * @uses   \OCA\OpenCatalogi\Controller\PublicationRead
 * @uses   \OCA\OpenCatalogi\Contract\PublicationUsage
 */
class PublicationShowGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-show-golden.json';

	private const UUID = '0b1e7a52-0000-4000-8000-000000000001';

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
		$scoped = ['slug' => 'woo', 'registers' => [1, 2], 'schemas' => [3, 4]];

		return [
			'unknown catalog' => [['catalog' => null]],
			'unscoped catalog' => [['catalog' => ['slug' => 'woo', 'registers' => [], 'schemas' => [3]]]],
			'registers as json text, first pair' => [[
				'catalog' => ['slug' => 'woo', 'registers' => '["1","x",2]', 'schemas' => [3]],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'uuid in the last pair' => [['catalog' => $scoped, 'stored' => ['uuid:' . self::UUID . ':2:4' => ['p1']]]],
			'uuid through the location fallback' => [[
				'catalog' => $scoped,
				'location' => ['register' => 2, 'schema' => 3],
				'stored' => ['uuid:' . self::UUID . ':2:3' => ['p1']],
			]],
			'location found but the search is empty, slug hit' => [[
				'catalog' => $scoped,
				'id' => 'Besluit_1',
				'location' => ['register' => 1, 'schema' => 3],
				'stored' => ['slug:besluit-1:2:3' => ['p2']],
			]],
			'slug hit on a show-withheld catalog' => [[
				'catalog' => $scoped + ['showWithheld' => true],
				'id' => 'besluit-1',
				'stored' => ['slug:besluit-1:1:4' => ['p2']],
			]],
			'ambiguous slug' => [[
				'catalog' => $scoped,
				'id' => 'dup',
				'stored' => ['slug:dup:1:3' => ['p1', 'p2']],
			]],
			'nothing found' => [['catalog' => $scoped, 'id' => 'missing']],
			'found outside the catalog scope' => [['catalog' => $scoped, 'stored' => ['uuid:' . self::UUID . ':1:3' => ['out']]]],
			'extend as text is sanitised' => [[
				'catalog' => $scoped,
				'params' => ['extend' => ' @self.a, b ,@self.a,@self.c'],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'_extend as list, capped at five' => [[
				'catalog' => $scoped,
				'params' => ['_extend' => ['@self.a', 7, '@self.b', '@self.c', '@self.d', '@self.e', '@self.f']],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'extend as a number' => [[
				'catalog' => $scoped,
				'params' => ['extend' => 5],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'schema.org by format' => [[
				'catalog' => $scoped + ['showWithheld' => true],
				'params' => ['format' => 'JSONLD'],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'schema.org by accept header' => [[
				'catalog' => $scoped,
				'headers' => ['Accept' => 'application/ld+json'],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'withheld catalog, uuid hit' => [[
				'catalog' => $scoped + ['showWithheld' => true],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'signed in caller' => [[
				'catalog' => $scoped,
				'signedIn' => true,
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'revalidation answers 304 and is not counted' => [[
				'catalog' => $scoped,
				'headers' => ['If-None-Match' => '*'],
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'HEAD request is not counted' => [[
				'catalog' => $scoped,
				'method' => 'HEAD',
				'stored' => ['uuid:' . self::UUID . ':1:3' => ['p1']],
			]],
			'openregister missing' => [['catalog' => $scoped, 'openregister' => false]],
			'lookup throws DoesNotExist' => [['catalog' => $scoped, 'throw' => 'missing']],
			'lookup throws' => [['catalog' => $scoped, 'throw' => 'boom']],
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
	public function testShowMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$response = $this->controller(case: $case)->show('woo', ($case['id'] ?? self::UUID));
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

	}//end testShowMatchesTheRecording()

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
			function (array $catalog): array {
				$this->log[] = ['guard' => $catalog];
				return $catalog + ['guarded' => true];
			}
		);
		$queries->method('findObjectLocation')->willReturnCallback(
			function (string $uuid, array $allowedRegisters, array $allowedSchemas) use ($case): ?array {
				$this->log[] = ['location' => [$uuid, $allowedRegisters, $allowedSchemas]];
				return ($case['location'] ?? null);
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

			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->log[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if (($this->case['throw'] ?? null) === 'missing') {
					throw new DoesNotExistException('gone');
				}

				if (($this->case['throw'] ?? null) === 'boom') {
					throw new RuntimeException('secret detail');
				}

				$self = $query['@self'];
				$field = (isset($self['uuid']) === true) ? 'uuid' : 'slug';
				$key = $field . ':' . $self[$field] . ':' . $self['register'] . ':' . $self['schema'];

				return array_map(
					static fn (string $name): object => new class($name, (int)$self['register'], (int)$self['schema']) {
						public function __construct(
							private string $name,
							private int $register,
							private int $schema,
						) {
						}

						public function getUuid(): string {
							return 'uuid-of-' . $this->name;
						}

						public function getId(): int {
							return 42;
						}

						public function getRegister(): int {
							return ($this->name === 'out') ? 9 : $this->register;
						}

						public function getSchema(): int {
							return $this->schema;
						}
					},
					($this->case['stored'][$key] ?? [])
				);
			}

			public function renderEntity(object $entity, array $_extend = [], int $depth = 0, array $filter = [], array $fields = [], array $unset = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['render' => [$entity->getUuid(), $_extend, $depth, $filter, $fields, $unset, $_rbac, $_multitenancy]];
				return ['id' => $entity->getUuid(), 'title' => 'T'];
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
