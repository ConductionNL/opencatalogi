<?php

/**
 * Golden test for the `Access-Control-Allow-Origin` value of every public controller.
 *
 * Builds each controller that answers cross-origin requests over mocked
 * collaborators, sets the `cors_allowed_origins` app config and the caller's
 * Origin, and compares the origin it names with the expected table: the
 * wildcard default, blank and padded settings, allowlisted and unlisted
 * callers, empty entries, case-sensitive matching and a missing app config.
 * Every controller ignores blank allowlist entries. The list controllers
 * also read the caller from the server's HTTP_ORIGIN when the Origin header
 * is empty.
 * The GET-only read controllers also pin their CORS header set.
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
 * @covers \OCA\OpenCatalogi\Controller\ApiDocumentationController
 * @covers \OCA\OpenCatalogi\Controller\CatalogiController
 * @covers \OCA\OpenCatalogi\Controller\CommunityController
 * @covers \OCA\OpenCatalogi\Controller\DcatController
 * @covers \OCA\OpenCatalogi\Controller\DirectoryController
 * @covers \OCA\OpenCatalogi\Controller\GlossaryController
 * @covers \OCA\OpenCatalogi\Controller\InspectionController
 * @covers \OCA\OpenCatalogi\Controller\ListingsController
 * @covers \OCA\OpenCatalogi\Controller\OoapiController
 * @covers \OCA\OpenCatalogi\Controller\PublicationRulesController
 * @covers \OCA\OpenCatalogi\Controller\SchemaOrgController
 * @covers \OCA\OpenCatalogi\Controller\ServiceCatalogueController
 * @covers \OCA\OpenCatalogi\Controller\ThemesController
 * @covers \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginListRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
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
use OCA\OpenCatalogi\Controller\SchemaOrgController;
use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Golden test for resolveAllowedOrigin() and the read-only CORS header set.
 */
class CorsAllowedOriginGoldenTest extends TestCase {

	private const CONTROLLERS = [
		ApiDocumentationController::class,
		CatalogiController::class,
		CommunityController::class,
		DcatController::class,
		DirectoryController::class,
		GlossaryController::class,
		InspectionController::class,
		ListingsController::class,
		OoapiController::class,
		PublicationRulesController::class,
		SchemaOrgController::class,
		ServiceCatalogueController::class,
		ThemesController::class,
	];

	/**
	 * Controllers that fall back to the server's HTTP_ORIGIN.
	 */
	private const LIST_RULE = [
		CatalogiController::class,
		DirectoryController::class,
		GlossaryController::class,
		ListingsController::class,
		ThemesController::class,
	];

	/** Controllers whose app config is optional. */
	private const OPTIONAL_CONFIG = [
		ApiDocumentationController::class,
		DcatController::class,
		DirectoryController::class,
		OoapiController::class,
		SchemaOrgController::class,
	];

	/** Controllers that answer GET-only reads with one shared header set. */
	private const READ_ONLY = [
		ApiDocumentationController::class,
		DcatController::class,
		OoapiController::class,
		SchemaOrgController::class,
	];

	/** Controllers with a GET-only preflight route. */
	private const READ_ONLY_PREFLIGHT = [
		DcatController::class,
		OoapiController::class,
		SchemaOrgController::class,
	];

	/**
	 * Configured allowlist, caller origin and the expected header value.
	 *
	 * The fourth value, when present, is what the list controllers answer.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3?: string}>
	 */
	public static function origins(): array {
		$pair = 'https://a.nl,https://b.nl';
		return [
			'wildcard, no caller' => ['*', '', '*'],
			'wildcard, any caller' => ['*', 'https://evil.nl', '*'],
			'blank setting' => ['', 'https://a.nl', '*'],
			'whitespace setting' => ['   ', 'https://a.nl', '*'],
			'padded wildcard' => [' * ', 'https://a.nl', '*'],
			'first listed caller' => [$pair, 'https://a.nl', 'https://a.nl'],
			'second listed caller' => [$pair, 'https://b.nl', 'https://b.nl'],
			'unlisted caller gets the first entry' => [$pair, 'https://evil.nl', 'https://a.nl'],
			'no caller gets the first entry' => [$pair, '', 'https://a.nl'],
			'padded entries are trimmed' => [' , https://a.nl ,  https://b.nl , ', 'https://b.nl', 'https://b.nl'],
			'padded entries, unlisted caller' => [' , https://a.nl , ', 'https://c.nl', 'https://a.nl'],
			'blank first entry, no caller' => [',https://a.nl', '', 'https://a.nl'],
			'blank middle entry, unlisted caller' => ['https://a.nl,,https://b.nl', 'https://c.nl', 'https://a.nl'],
			'only separators' => [',, ,', 'https://a.nl', '*'],
			'matching is case sensitive' => ['https://A.nl', 'https://a.nl', 'https://A.nl'],
			'no prefix matching' => ['https://a.nl', 'https://a.nl.evil.nl', 'https://a.nl'],
			'single entry' => ['https://only.nl', 'https://only.nl', 'https://only.nl'],
			'numeric strings compare strictly' => ['10,https://a.nl', '1e1', '10'],
		];

	}//end origins()

	/**
	 * Every controller names the same origin for each allowlist and caller.
	 *
	 * @param string $configured The cors_allowed_origins setting.
	 * @param string $origin The caller's Origin header.
	 * @param string $expected The expected Access-Control-Allow-Origin value.
	 * @param string $listRule The list controllers' value, when it differs.
	 *
	 * @return void
	 */
	#[DataProvider('origins')]
	public function testEveryControllerNamesTheSameOrigin(string $configured, string $origin, string $expected, ?string $listRule = null): void {
		$want = [];
		$got = [];
		foreach (self::CONTROLLERS as $class) {
			$controller = $this->build($class, $this->config($configured), $origin);
			$want[$class] = (in_array($class, self::LIST_RULE, true) === true ? ($listRule ?? $expected) : $expected);
			$got[$class] = (new ReflectionMethod($controller, 'resolveAllowedOrigin'))->invoke($controller);
		}

		$this->assertSame($want, $got);

	}//end testEveryControllerNamesTheSameOrigin()

	/**
	 * Only the list controllers read the caller from the server's HTTP_ORIGIN.
	 *
	 * @return void
	 */
	public function testServerOriginFallback(): void {
		$want = [];
		$got = [];
		foreach (self::CONTROLLERS as $class) {
			$controller = $this->build($class, $this->config('https://a.nl,https://b.nl'), '', 'https://b.nl');
			$want[$class] = (in_array($class, self::LIST_RULE, true) === true ? 'https://b.nl' : 'https://a.nl');
			$got[$class] = (new ReflectionMethod($controller, 'resolveAllowedOrigin'))->invoke($controller);
		}

		$this->assertSame($want, $got);

	}//end testServerOriginFallback()

	/**
	 * Without an app config the optional-config controllers answer with the wildcard.
	 *
	 * @return void
	 */
	public function testMissingConfigMeansWildcard(): void {
		foreach (self::OPTIONAL_CONFIG as $class) {
			$controller = $this->build($class, null, 'https://a.nl');
			$this->assertSame('*', (new ReflectionMethod($controller, 'resolveAllowedOrigin'))->invoke($controller), $class);
		}

	}//end testMissingConfigMeansWildcard()

	/**
	 * The read-only controllers send one GET-only header set.
	 *
	 * @return void
	 */
	public function testReadOnlyHeaderSet(): void {
		foreach (self::READ_ONLY as $class) {
			$controller = $this->build($class, $this->config('https://a.nl,https://b.nl'), 'https://b.nl');
			$this->assertSame(
				[
					'Access-Control-Allow-Origin' => 'https://b.nl',
					'Access-Control-Allow-Methods' => 'GET, OPTIONS',
					'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
				],
				(new ReflectionMethod($controller, 'corsHeaders'))->invoke($controller),
				$class
			);
		}

	}//end testReadOnlyHeaderSet()

	/**
	 * The read-only preflight adds the max age and refuses credentials.
	 *
	 * @return void
	 */
	public function testReadOnlyPreflight(): void {
		foreach (self::READ_ONLY_PREFLIGHT as $class) {
			$controller = $this->build($class, $this->config('https://a.nl'), 'https://evil.nl');
			$response = $controller->preflightedCors();
			$this->assertInstanceOf(Response::class, $response);
			$headers = $response->getHeaders();
			foreach ([
				'Access-Control-Allow-Origin' => 'https://a.nl',
				'Access-Control-Allow-Methods' => 'GET, OPTIONS',
				'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
				'Access-Control-Max-Age' => '1728000',
				'Access-Control-Allow-Credentials' => 'false',
			] as $name => $value) {
				$this->assertSame($value, $headers[$name] ?? null, $class . ' ' . $name);
			}

			$this->assertSame(200, $response->getStatus(), $class);
		}

	}//end testReadOnlyPreflight()

	/**
	 * An app config that answers the CORS key with the given setting.
	 *
	 * @param string $configured The cors_allowed_origins setting.
	 *
	 * @return IAppConfig
	 */
	private function config(string $configured): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($configured): string {
				if ($app === 'opencatalogi' && $key === 'cors_allowed_origins' && $default === '*') {
					return $configured;
				}

				return 'unexpected';
			}
		);
		return $config;
	}//end config()

	/**
	 * Construct a controller with mocks for every collaborator.
	 *
	 * @param class-string $class The controller class.
	 * @param IAppConfig|null $config The app config, or null to leave an optional one out.
	 * @param string $origin The caller's Origin header.
	 * @param string|null $server The server's HTTP_ORIGIN, or null for none.
	 *
	 * @return object
	 */
	private function build(string $class, ?IAppConfig $config, string $origin, ?string $server = null): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? $origin : '')
		);
		if ($server !== null) {
			$request->server = ['HTTP_ORIGIN' => $server];
		}

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
