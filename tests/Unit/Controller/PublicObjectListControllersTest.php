<?php

/**
 * Characterization tests for the public list controllers over one configured
 * register and schema (glossary and themes): the search they send, the
 * paginated answer and the CORS headers on every answer.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\GlossaryController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class PublicObjectListControllersTest extends TestCase {

	/** @var array<int, array<string, mixed>> Every search query the fake ObjectService received. */
	private array $queries = [];

	/**
	 * The controllers under test and their configuration key prefix.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function controllers(): array {
		return [
			'glossary' => [GlossaryController::class, 'glossary'],
			'themes' => [ThemesController::class, 'theme'],
		];
	}

	/**
	 * Build a controller whose OpenRegister answers with the given search result.
	 *
	 * @param string               $class   The controller class.
	 * @param string               $prefix  The configuration key prefix.
	 * @param array<string, mixed> $result  The paginated search result.
	 * @param string               $origins The `cors_allowed_origins` value.
	 * @param string               $origin  The caller's Origin, sent only as HTTP_ORIGIN.
	 *
	 * @return Controller The controller.
	 */
	private function controller(string $class, string $prefix, array $result, string $origins, string $origin): Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['q' => 'x', 'id' => 'drop', '_route' => 'drop', '_schemas' => ['9'], '@self' => ['schema' => '9', 'owner' => 'me']]);
		$request->method('getHeader')->willReturn('');
		$request->server = ['HTTP_ORIGIN' => $origin];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				$prefix.'_register' => '2',
				$prefix.'_schema' => '10',
				'cors_allowed_origins' => $origins,
				default => $default,
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($result): array {
				$this->queries[] = $query;
				return $result;
			}
		);
		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'one']);
		$objects->method('find')->willReturn($entity);
		$resolver = new class($config) {
			public function __construct(private IAppConfig $config) {
			}

			public function resolveRegisterId(string $appId, string $key, ?string $default = null): string {
				return $this->config->getValueString($appId, $key, '');
			}

			public function resolveSchemaId(string $appId, string $key, ?string $default = null): string {
				return $this->config->getValueString($appId, $key, '');
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'RegisterResolverService') ? $resolver : $objects
		);

		if ($class === GlossaryController::class) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			return new GlossaryController('opencatalogi', $request, $config, $container, $appManager, $l10n);
		}

		return new ThemesController('opencatalogi', $request, $config, $container, $appManager);
	}

	/**
	 * @dataProvider controllers
	 */
	public function testIndexScopesTheSearchAndAnswersThePageWithCorsHeaders(string $class, string $prefix): void {
		$controller = $this->controller(
			$class,
			$prefix,
			[
				'results' => [['id' => 'a']],
				'total' => 3,
				'page' => 2,
				'next' => 'n',
				'facets' => ['facets' => ['kind' => [1]]],
				'facetable' => ['kind'],
			],
			'https://a.example, https://b.example',
			'https://b.example'
		);

		$response = $controller->index();

		$this->assertSame([['q' => 'x', '@self' => ['owner' => 'me', 'schema' => '10', 'register' => '2']]], $this->queries);
		$this->assertSame(
			[
				'results' => [['id' => 'a']],
				'total' => 3,
				'limit' => 20,
				'offset' => 0,
				'page' => 2,
				'pages' => 1,
				'next' => 'n',
				'facets' => ['kind' => [1]],
				'facetable' => ['kind'],
			],
			$response->getData()
		);
		$headers = $response->getHeaders();
		$this->assertSame('https://b.example', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('Authorization, Content-Type, Accept', $headers['Access-Control-Allow-Headers']);
	}

	/**
	 * @dataProvider controllers
	 */
	public function testAnUnlistedOriginGetsTheFirstListedOne(string $class, string $prefix): void {
		$controller = $this->controller($class, $prefix, ['results' => [['id' => 'one']]], ' , https://a.example', 'https://evil.example');

		$this->assertSame('https://a.example', $controller->index()->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('https://a.example', $controller->show('one')->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('https://a.example', $controller->preflightedCors()->getHeaders()['Access-Control-Allow-Origin']);
	}

	/**
	 * @dataProvider controllers
	 */
	public function testShowAnswersWithTheSameCorsHeaders(string $class, string $prefix): void {
		$controller = $this->controller($class, $prefix, ['results' => [['id' => 'one']]], '*', 'https://b.example');

		$response = $controller->show('one');

		$this->assertSame(['id' => 'one'], $response->getData());
		$headers = $response->getHeaders();
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('Authorization, Content-Type, Accept', $headers['Access-Control-Allow-Headers']);
	}
}
