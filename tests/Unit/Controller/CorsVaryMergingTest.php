<?php

/**
 * Regression test for the `Vary` header every CORS path sends.
 *
 * Each path that puts the allowed origin on a response (the read trait's
 * withCorsHeaders(), SendsListCorsHeaders' answer and preflight, the
 * controllers' own withCors()/addCorsHeaders(), and every public preflight)
 * is driven through a real controller. With an allowlisted origin the
 * response varies on Origin: a response without a Vary gets `Vary: Origin`,
 * existing directives such as `Accept-Encoding` are kept in order, Origin is
 * never named twice (in any letter case, or after a `Vary: *`), and a second
 * pass changes nothing. The wildcard does not depend on the caller, so it
 * adds no Vary and leaves an existing one as it was.
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
 * @covers \OCA\OpenCatalogi\Controller\ResolvesAllowedOrigin
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @covers \OCA\OpenCatalogi\Controller\SendsListCorsHeaders
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ApiDocumentationController;
use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Controller\GlossaryController;
use OCA\OpenCatalogi\Controller\InspectionController;
use OCA\OpenCatalogi\Controller\ListingsController;
use OCA\OpenCatalogi\Controller\OoapiController;
use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Controller\SchemaOrgController;
use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Regression test for Vary merging on every CORS path.
 */
class CorsVaryMergingTest extends TestCase {

	/**
	 * Every method that adds the allowed origin to a response it is handed.
	 */
	private const RESPONSE_PATHS = [
		[ApiDocumentationController::class, 'withCorsHeaders'],
		[DcatController::class, 'withCorsHeaders'],
		[OoapiController::class, 'withCorsHeaders'],
		[SchemaOrgController::class, 'withCorsHeaders'],
		[CatalogiController::class, 'withListCorsHeaders'],
		[CatalogiController::class, 'withListPreflightHeaders'],
		[DirectoryController::class, 'withListCorsHeaders'],
		[DirectoryController::class, 'withListPreflightHeaders'],
		[GlossaryController::class, 'withListCorsHeaders'],
		[GlossaryController::class, 'withListPreflightHeaders'],
		[ThemesController::class, 'withListCorsHeaders'],
		[ThemesController::class, 'withListPreflightHeaders'],
		[ServiceCatalogueController::class, 'withListCorsHeaders'],
		[ServiceCatalogueController::class, 'withCors'],
		[CommunityController::class, 'withCors'],
		[InspectionController::class, 'withCors'],
		[PublicationsController::class, 'addCorsHeaders'],
	];

	/**
	 * Every controller with a public CORS preflight; each builds its own response.
	 */
	private const PREFLIGHTS = [
		CatalogiController::class,
		CommunityController::class,
		DcatController::class,
		DirectoryController::class,
		GlossaryController::class,
		InspectionController::class,
		ListingsController::class,
		OoapiController::class,
		PublicationRulesController::class,
		PublicationsController::class,
		SchemaOrgController::class,
		ServiceCatalogueController::class,
		ThemesController::class,
	];

	/**
	 * The Vary a response already carries (null for none) and the Vary it should end with.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function varies(): array {
		return [
			'no Vary gets Vary: Origin' => [null, 'Origin'],
			'empty Vary gets Vary: Origin' => ['', 'Origin'],
			'Accept-Encoding is kept' => ['Accept-Encoding', 'Accept-Encoding, Origin'],
			'several directives are kept in order' => ['Accept-Encoding, Accept-Language', 'Accept-Encoding, Accept-Language, Origin'],
			'Origin already named is not repeated' => ['Accept-Encoding, Origin', 'Accept-Encoding, Origin'],
			'Origin in another letter case is not repeated' => ['origin, Accept-Encoding', 'origin, Accept-Encoding'],
			'the cacheable answer\'s Vary is kept as is' => ['Origin, Accept, Accept-Language', 'Origin, Accept, Accept-Language'],
			'Vary: * already covers Origin' => ['*', '*'],
			'blank entries are dropped' => [' Accept-Encoding ,, ', 'Accept-Encoding, Origin'],
		];

	}//end varies()

	/**
	 * Every response path merges Origin into the Vary it finds, once.
	 *
	 * @param string|null $existing The response's Vary before the CORS headers, or null for none.
	 * @param string      $expected The Vary after.
	 *
	 * @return void
	 */
	#[DataProvider('varies')]
	public function testEveryResponsePathMergesOriginIntoVary(?string $existing, string $expected): void {
		$want = [];
		$got = [];
		foreach (self::RESPONSE_PATHS as [$class, $method]) {
			$controller = $this->build(class: $class, configured: 'https://a.nl,https://b.nl');
			$response = new JSONResponse([]);
			if ($existing !== null) {
				$response->addHeader('Vary', $existing);
			}

			$this->invoke(controller: $controller, method: $method, response: $response);
			$first = $this->varyOf(response: $response);
			$this->invoke(controller: $controller, method: $method, response: $response);

			$want[$class.'::'.$method] = ['https://b.nl', $expected, $expected];
			$got[$class.'::'.$method] = [$response->getHeaders()['Access-Control-Allow-Origin'] ?? null, $first, $this->varyOf(response: $response)];
		}

		$this->assertSame($want, $got);

	}//end testEveryResponsePathMergesOriginIntoVary()

	/**
	 * With the wildcard no response path adds a Vary, and an existing one is left alone.
	 *
	 * @return void
	 */
	public function testTheWildcardLeavesVaryAlone(): void {
		$want = [];
		$got = [];
		foreach (self::RESPONSE_PATHS as [$class, $method]) {
			$controller = $this->build(class: $class, configured: '*');
			$bare = new JSONResponse([]);
			$this->invoke(controller: $controller, method: $method, response: $bare);
			$withVary = new JSONResponse([]);
			$withVary->addHeader('Vary', 'Accept-Encoding');
			$this->invoke(controller: $controller, method: $method, response: $withVary);

			$want[$class.'::'.$method] = ['*', null, 'Accept-Encoding'];
			$got[$class.'::'.$method] = [$bare->getHeaders()['Access-Control-Allow-Origin'] ?? null, $this->varyOf(response: $bare), $this->varyOf(response: $withVary)];
		}

		$this->assertSame($want, $got);

	}//end testTheWildcardLeavesVaryAlone()

	/**
	 * Every preflight varies on Origin for an allowlisted origin and sends no Vary for the wildcard.
	 *
	 * @return void
	 */
	public function testEveryPreflightVariesOnlyWhenTheOriginComesFromTheAllowlist(): void {
		$want = [];
		$got = [];
		foreach (self::PREFLIGHTS as $class) {
			$listed = $this->build(class: $class, configured: 'https://a.nl,https://b.nl')->preflightedCors();
			$wildcard = $this->build(class: $class, configured: '*')->preflightedCors();

			$want[$class] = ['https://b.nl', 'Origin', '*', null];
			$got[$class] = [
				$listed->getHeaders()['Access-Control-Allow-Origin'] ?? null,
				$this->varyOf(response: $listed),
				$wildcard->getHeaders()['Access-Control-Allow-Origin'] ?? null,
				$this->varyOf(response: $wildcard),
			];
		}

		$this->assertSame($want, $got);

	}//end testEveryPreflightVariesOnlyWhenTheOriginComesFromTheAllowlist()

	/**
	 * The response's Vary header, or null when it has none.
	 *
	 * @param Response $response The response.
	 *
	 * @return string|null The Vary value.
	 */
	private function varyOf(Response $response): ?string {
		return ($response->getHeaders()['Vary'] ?? null);

	}//end varyOf()

	/**
	 * Call a private CORS method with the response.
	 *
	 * @param object       $controller The controller.
	 * @param string       $method     The method name.
	 * @param JSONResponse $response   The response; the method adds its headers to it.
	 *
	 * @return void
	 */
	private function invoke(object $controller, string $method, JSONResponse $response): void {
		(new ReflectionMethod($controller, $method))->invoke($controller, $response);

	}//end invoke()

	/**
	 * Construct a controller over mocks, called from https://b.nl.
	 *
	 * @param class-string $class      The controller class.
	 * @param string       $configured The cors_allowed_origins setting.
	 *
	 * @return object
	 */
	private function build(string $class, string $configured): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? 'https://b.nl' : '')
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'cors_allowed_origins' ? $configured : $default)
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType ? $type->getName() : null);
			if ($param->getName() === 'appName') {
				$args[] = 'opencatalogi';
			} elseif ($name === IRequest::class) {
				$args[] = $request;
			} elseif ($name === IAppConfig::class) {
				$args[] = $config;
			} elseif ($param->isDefaultValueAvailable() === true) {
				$args[] = $param->getDefaultValue();
			} else {
				$args[] = $this->createMock($name);
			}
		}

		return new $class(...$args);

	}//end build()
}//end class
