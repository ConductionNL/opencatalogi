<?php

/**
 * Regression test for how every public controller reads the caller's origin.
 *
 * Complements CorsAllowedOriginGoldenTest (the allowlist table) with the
 * caller side of ResolvesAllowedOrigin::callerOrigin(): the Origin header
 * wins over the server's HTTP_ORIGIN, the HTTP_ORIGIN fallback still goes
 * through the allowlist, the wildcard ignores both, and a request with no
 * origin anywhere gets the first allowlisted entry. A second table builds
 * the request the way Nextcloud does (OC\AppFramework\Http\Request::getHeader()
 * reads the Origin header from $server['HTTP_ORIGIN']) and shows that on
 * such a request the HTTP_ORIGIN fallback answers exactly what the Origin
 * header alone answers, for every controller and through every CORS trait.
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
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginListRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @covers \OCA\OpenCatalogi\Controller\CorsAllowlist
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ApiDocumentationController;
use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Controller\CorsAllowlist;
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
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Regression test for the caller-origin read behind resolveAllowedOrigin().
 */
class CorsOriginResolutionTest extends TestCase {

	/**
	 * Every controller that answers cross-origin requests, keyed by the CORS trait it resolves through.
	 */
	private const CONTROLLERS = [
		'read'       => [ApiDocumentationController::class, DcatController::class, OoapiController::class, SchemaOrgController::class],
		'read-write' => [CommunityController::class, InspectionController::class, PublicationRulesController::class, ServiceCatalogueController::class],
		'list'       => [CatalogiController::class, GlossaryController::class, ListingsController::class, ThemesController::class],
		'direct'     => [DirectoryController::class, PublicationsController::class],
	];

	/**
	 * Allowlist, Origin header, server HTTP_ORIGIN (null for none) and the expected value.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null, 3: string}>
	 */
	public static function callers(): array {
		$pair = 'https://a.nl,https://b.nl';
		return [
			'missing Origin, no HTTP_ORIGIN' => [$pair, '', null, 'https://a.nl'],
			'missing Origin, empty HTTP_ORIGIN' => [$pair, '', '', 'https://a.nl'],
			'empty Origin falls back to a listed HTTP_ORIGIN' => [$pair, '', 'https://b.nl', 'https://b.nl'],
			'empty Origin, unlisted HTTP_ORIGIN is not echoed' => [$pair, '', 'https://evil.nl', 'https://a.nl'],
			'explicitly allowed Origin' => [$pair, 'https://b.nl', null, 'https://b.nl'],
			'rejected Origin' => [$pair, 'https://evil.nl', null, 'https://a.nl'],
			'the Origin header wins over HTTP_ORIGIN' => [$pair, 'https://a.nl', 'https://b.nl', 'https://a.nl'],
			'a rejected Origin header does not fall back to HTTP_ORIGIN' => [$pair, 'https://evil.nl', 'https://b.nl', 'https://a.nl'],
			'wildcard, missing Origin' => ['*', '', null, '*'],
			'wildcard, HTTP_ORIGIN only' => ['*', '', 'https://evil.nl', '*'],
			'wildcard, any Origin' => ['*', 'https://evil.nl', 'https://b.nl', '*'],
		];

	}//end callers()

	/**
	 * Every controller reads the caller the same way, through each CORS trait.
	 *
	 * @param string      $configured The cors_allowed_origins setting.
	 * @param string      $origin     The Origin header.
	 * @param string|null $server     The server's HTTP_ORIGIN, or null for none.
	 * @param string      $expected   The expected Access-Control-Allow-Origin value.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testEveryCorsTraitReadsTheCallerTheSameWay(string $configured, string $origin, ?string $server, string $expected): void {
		$serverVars = [];
		if ($server !== null) {
			$serverVars['HTTP_ORIGIN'] = $server;
		}

		$want = [];
		$got = [];
		foreach (self::CONTROLLERS as $trait => $classes) {
			foreach ($classes as $class) {
				$request = $this->request(origin: $origin, server: $serverVars);
				$want[$trait.' '.$class] = $expected;
				$got[$trait.' '.$class] = $this->resolve(class: $class, configured: $configured, request: $request);
			}
		}

		$this->assertSame($want, $got);

	}//end testEveryCorsTraitReadsTheCallerTheSameWay()

	/**
	 * Allowlist, the server variables Nextcloud builds the request from, and the expected value.
	 *
	 * @return array<string, array{0: string, 1: array<string, string|null>, 2: string}>
	 */
	public static function nextcloudRequests(): array {
		$pair = 'https://a.nl,https://b.nl';
		return [
			'no Origin header' => [$pair, [], 'https://a.nl'],
			'empty Origin header' => [$pair, ['HTTP_ORIGIN' => ''], 'https://a.nl'],
			'null HTTP_ORIGIN' => [$pair, ['HTTP_ORIGIN' => null], 'https://a.nl'],
			'listed Origin header' => [$pair, ['HTTP_ORIGIN' => 'https://b.nl'], 'https://b.nl'],
			'unlisted Origin header' => [$pair, ['HTTP_ORIGIN' => 'https://evil.nl'], 'https://a.nl'],
			'wildcard, no Origin header' => ['*', [], '*'],
			'wildcard, Origin header' => ['*', ['HTTP_ORIGIN' => 'https://b.nl'], '*'],
		];

	}//end nextcloudRequests()

	/**
	 * On a request built the way Nextcloud builds it, the HTTP_ORIGIN fallback changes nothing.
	 *
	 * Nextcloud's Request::getHeader('Origin') returns $server['HTTP_ORIGIN']
	 * whenever it is set (lib/private/AppFramework/Http/Request.php), so an
	 * empty header means HTTP_ORIGIN is absent, null or empty and the fallback
	 * reads ''. Every controller therefore answers what the Origin header alone
	 * answers, which is the rule the read and read-write traits had before
	 * the fallback was shared with them.
	 *
	 * @param string                    $configured The cors_allowed_origins setting.
	 * @param array<string, string|null> $server     The server variables.
	 * @param string                    $expected   The expected Access-Control-Allow-Origin value.
	 *
	 * @return void
	 */
	#[DataProvider('nextcloudRequests')]
	public function testOnANextcloudRequestTheServerOriginFallbackChangesNothing(string $configured, array $server, string $expected): void {
		$want = [];
		$got = [];
		foreach (self::CONTROLLERS as $trait => $classes) {
			foreach ($classes as $class) {
				$request = $this->nextcloudRequest(server: $server);
				$headerOnly = (new CorsAllowlist($configured))->allowedFor($request->getHeader('Origin'));
				$this->assertSame($expected, $headerOnly, 'the header-only rule');
				$want[$trait.' '.$class] = $headerOnly;
				$got[$trait.' '.$class] = $this->resolve(class: $class, configured: $configured, request: $request);
			}
		}

		$this->assertSame($want, $got);

	}//end testOnANextcloudRequestTheServerOriginFallbackChangesNothing()

	/**
	 * A request double whose Origin header and server variables are set independently.
	 *
	 * @param string               $origin The Origin header.
	 * @param array<string, string> $server The server variables.
	 *
	 * @return IRequest
	 */
	private function request(string $origin, array $server): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? $origin : '')
		);
		$request->server = $server;
		return $request;

	}//end request()

	/**
	 * A request double that reads headers the way OC\AppFramework\Http\Request::getHeader() does.
	 *
	 * @param array<string, string|null> $server The server variables.
	 *
	 * @return IRequest
	 */
	private function nextcloudRequest(array $server): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($server): string {
				$key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
				if (isset($server[$key]) === true) {
					return $server[$key];
				}

				return '';
			}
		);
		$request->server = $server;
		return $request;

	}//end nextcloudRequest()

	/**
	 * Build a controller over mocks and resolve its allowed origin.
	 *
	 * @param class-string $class      The controller class.
	 * @param string       $configured The cors_allowed_origins setting.
	 * @param IRequest     $request    The request.
	 *
	 * @return string The Access-Control-Allow-Origin value.
	 */
	private function resolve(string $class, string $configured, IRequest $request): string {
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

		$controller = new $class(...$args);
		return (new ReflectionMethod($controller, 'resolveAllowedOrigin'))->invoke($controller);

	}//end resolve()
}//end class
