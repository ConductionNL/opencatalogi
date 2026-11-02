<?php

/**
 * Golden test for the shared reads and preflights of the community controllers.
 *
 * Pins what CommunityController and NoticeBoardController ask OpenRegister for
 * when they read every object of a schema (register, schema, filters, the
 * default and a caller limit, RBAC and multitenancy off) and the rows they
 * answer, and the GET/POST preflight of CommunityController and
 * PublicationRulesController.
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
 *
 * @spec openspec/specs/public-and-community-surface/spec.md
 *
 * @covers \OCA\OpenCatalogi\Controller\CommunityController
 * @covers \OCA\OpenCatalogi\Controller\NoticeBoardController
 * @covers \OCA\OpenCatalogi\Controller\PublicationRulesController
 * @covers \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @covers \OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @covers \OCA\OpenCatalogi\Controller\ReadsPublicationRegister
 * @uses \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Controller\NoticeBoardController;
use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Golden test for the community controllers' reads and preflights.
 */
class CommunityReadsGoldenTest extends TestCase {

	/**
	 * Both controllers read a schema the same way.
	 *
	 * @return void
	 */
	public function testReadAll(): void {
		foreach ([CommunityController::class, NoticeBoardController::class] as $class) {
			$searches = [];
			$controller = $this->build($class, $searches);
			$readAll = new ReflectionMethod($controller, 'readAll');

			$this->assertSame(
				[['title' => 'Plain'], ['title' => 'Wrapped', 'id' => 'env-1'], []],
				$readAll->invoke($controller, 'notice_schema'),
				$class
			);
			$this->assertSame([['title' => 'Plain'], ['title' => 'Wrapped', 'id' => 'env-1'], []], $readAll->invoke($controller, 'notice_schema', ['catalog' => 'woo', '_limit' => 5]), $class);
			$this->assertSame(
				[
					[['@self' => ['register' => 'pub-reg', 'schema' => 'cfg:notice_schema'], '_limit' => 500], false, false],
					[['catalog' => 'woo', '_limit' => 5, '@self' => ['register' => 'pub-reg', 'schema' => 'cfg:notice_schema']], false, false],
				],
				$searches,
				$class
			);
		}

	}//end testReadAll()

	/**
	 * The read-write preflight sends five headers in order.
	 *
	 * @return void
	 */
	public function testReadWritePreflight(): void {
		foreach ([CommunityController::class, PublicationRulesController::class] as $class) {
			$searches = [];
			$headers = array_filter(
				$this->build($class, $searches)->preflightedCors()->getHeaders(),
				static fn (string $name): bool => str_starts_with($name, 'Access-Control-'),
				ARRAY_FILTER_USE_KEY
			);
			$this->assertSame(
				[
					'Access-Control-Allow-Origin' => 'https://a.nl',
					'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
					'Access-Control-Max-Age' => '1728000',
					'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
					'Access-Control-Allow-Credentials' => 'false',
				],
				$headers,
				$class
			);
		}

	}//end testReadWritePreflight()

	/**
	 * A controller over doubles that record every search.
	 *
	 * @param class-string $class The controller.
	 * @param array<int, mixed> $searches The searches, collected.
	 *
	 * @return object
	 */
	private function build(string $class, array &$searches): object {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match (true) {
				$key === 'cors_allowed_origins' => 'https://a.nl',
				$key === 'publication_register' => 'pub-reg',
				default => 'cfg:' . $key,
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($config) {
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$store = new class($searches) {
			/**
			 * @param array<int, mixed> $searches The shared log.
			 */
			public function __construct(
				private array &$searches,
			) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = [$query, $_rbac, $_multitenancy];
				return ['results' => [['title' => 'Plain'], ['id' => 'env-1', 'object' => ['title' => 'Wrapped']], 'not a row']];
			}
		};
		$objects = $this->createMock(ServiceCatalogueServiceInterface::class);
		$objects->method('getObjectService')->willReturn($store);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType ? $type->getName() : null);
			$args[] = match (true) {
				$param->getName() === 'appName' => 'opencatalogi',
				$name === IRequest::class => $request,
				$name === IAppConfig::class => $config,
				$name === ContainerInterface::class => $container,
				$name === ServiceCatalogueServiceInterface::class => $objects,
				default => $this->createMock($name),
			};
		}

		return new $class(...$args);
	}//end build()
}//end class
