<?php

/**
 * Golden test for the CORS headers of the list controllers.
 *
 * Pins, in order, every `Access-Control-*` header the list controllers send:
 * the preflight answer of Catalogi, Directory, Glossary, Themes and
 * ServiceCatalogue, the JSON answers of Catalogi::show(), Directory::update()
 * (missing directory) and the shared publicJsonResponse() of the configured
 * object lists, and ServiceCatalogue's withCors(). Each controller is built
 * with its own non-default CORS methods, headers and max age where it takes
 * them, so a header taken from the wrong place shows.
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
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 *
 * @covers \OCA\OpenCatalogi\Controller\CatalogiController
 * @covers \OCA\OpenCatalogi\Controller\DirectoryController
 * @covers \OCA\OpenCatalogi\Controller\GlossaryController
 * @covers \OCA\OpenCatalogi\Controller\ThemesController
 * @covers \OCA\OpenCatalogi\Controller\ServiceCatalogueController
 * @covers \OCA\OpenCatalogi\Controller\ListsConfiguredObjects
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginListRequests
 * @covers \OCA\OpenCatalogi\Controller\SendsListCorsHeaders
 * @uses \OCA\OpenCatalogi\Controller\CorsAllowlist
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Controller\GlossaryController;
use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Golden test for the list controllers' CORS headers.
 */
class ListCorsHeadersGoldenTest extends TestCase {

	private const PREFLIGHT = [
		'Access-Control-Allow-Origin' => 'https://a.nl',
		'Access-Control-Allow-Methods' => 'GET, X-METHODS',
		'Access-Control-Max-Age' => '42',
		'Access-Control-Allow-Headers' => 'X-Headers',
		'Access-Control-Allow-Credentials' => 'false',
	];

	private const ANSWER = [
		'Access-Control-Allow-Origin' => 'https://a.nl',
		'Access-Control-Allow-Methods' => 'GET, X-METHODS',
		'Access-Control-Allow-Headers' => 'X-Headers',
	];

	/**
	 * Every preflight sends the five headers in order.
	 *
	 * @return void
	 */
	public function testPreflights(): void {
		$got = [];
		foreach ([CatalogiController::class, DirectoryController::class, GlossaryController::class, ThemesController::class, ServiceCatalogueController::class] as $class) {
			$got[$class] = $this->cors($this->build($class)->preflightedCors());
		}

		$this->assertSame(array_fill_keys(array_keys($got), self::PREFLIGHT), $got);

	}//end testPreflights()

	/**
	 * Without CORS settings the list controllers fall back to the same defaults.
	 *
	 * @return void
	 */
	public function testDefaultPreflights(): void {
		$got = [];
		foreach ([CatalogiController::class, DirectoryController::class, GlossaryController::class, ThemesController::class, ServiceCatalogueController::class] as $class) {
			$got[$class] = $this->cors($this->build($class, false)->preflightedCors());
		}

		$defaults = [
			'Access-Control-Allow-Origin' => 'https://a.nl',
			'Access-Control-Allow-Methods' => 'PUT, POST, GET, DELETE, PATCH',
			'Access-Control-Max-Age' => '1728000',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
			'Access-Control-Allow-Credentials' => 'false',
		];
		$this->assertSame(array_fill_keys(array_keys($got), $defaults), $got);
	}//end testDefaultPreflights()

	/**
	 * The JSON answers send the three answer headers, ServiceCatalogue's withCors() all five.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$catalogi = $this->build(CatalogiController::class);
		$glossary = $this->build(GlossaryController::class);
		$themes = $this->build(ThemesController::class);
		$catalogue = $this->build(ServiceCatalogueController::class);

		$got = [
			'catalogi show' => $this->cors($catalogi->show('c-1')),
			'directory update without directory' => $this->cors($this->build(DirectoryController::class)->update()),
			'glossary list answer' => $this->cors((new ReflectionMethod($glossary, 'publicJsonResponse'))->invoke($glossary, ['x'])),
			'themes list answer' => $this->cors((new ReflectionMethod($themes, 'publicJsonResponse'))->invoke($themes, ['x'])),
			'service catalogue withCors' => $this->cors((new ReflectionMethod($catalogue, 'withCors'))->invoke($catalogue, new JSONResponse(['x']))),
		];

		$this->assertSame(
			[
				'catalogi show' => self::ANSWER,
				'directory update without directory' => self::ANSWER,
				'glossary list answer' => self::ANSWER,
				'themes list answer' => self::ANSWER,
				'service catalogue withCors' => self::PREFLIGHT,
			],
			$got
		);

	}//end testAnswers()

	/**
	 * The Access-Control-* headers of a response, in the order they were added.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function cors(Response $response): array {
		return array_filter(
			$response->getHeaders(),
			static fn (string $name): bool => str_starts_with($name, 'Access-Control-'),
			ARRAY_FILTER_USE_KEY
		);

	}//end cors()

	/**
	 * A controller over mocks, with non-default CORS settings.
	 *
	 * @param class-string $class The controller.
	 * @param bool $customCors Whether to pass non-default CORS settings.
	 *
	 * @return object
	 */
	private function build(string $class, bool $customCors = true): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Origin' ? 'https://evil.nl' : ''));
		$request->method('getParam')->willReturn(null);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'cors_allowed_origins' ? 'https://a.nl' : $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('index')->willReturn(new JSONResponse(['catalog']));

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType ? $type->getName() : null);
			$args[] = match (true) {
				$param->getName() === 'appName' => 'opencatalogi',
				$customCors === true && $param->getName() === 'corsMethods' => 'GET, X-METHODS',
				$customCors === true && $param->getName() === 'corsAllowedHeaders' => 'X-Headers',
				$customCors === true && $param->getName() === 'corsMaxAge' => 42,
				$name === IRequest::class => $request,
				$name === IAppConfig::class => $config,
				$name === IL10N::class => $l10n,
				$name === CatalogiServiceInterface::class => $catalogi,
				$param->isDefaultValueAvailable() === true => $param->getDefaultValue(),
				default => $this->createMock($name),
			};
		}

		return new $class(...$args);
	}//end build()
}//end class
