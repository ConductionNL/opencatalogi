<?php

/**
 * Golden test for the CORS headers of PublicationsController.
 *
 * The preflight answer and the headers added to a public answer, with the
 * default CORS settings and with custom ones, are pinned literally.
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
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\CorsSettings;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for the CORS headers of PublicationsController.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 */
class PublicationsCorsGoldenTest extends TestCase {

	private const DEFAULT_HEADERS = [
		'Access-Control-Allow-Origin' => 'https://a.nl',
		'Access-Control-Allow-Methods' => 'PUT, POST, GET, DELETE, PATCH',
		'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
	];

	/**
	 * The preflight and the public answer, with default and with custom settings.
	 *
	 * @return void
	 */
	public function testHeadersMatchTheRecording(): void {
		$this->assertSame(
			[
				'default preflight' => [
					'Access-Control-Allow-Origin' => 'https://a.nl',
					'Access-Control-Allow-Methods' => 'PUT, POST, GET, DELETE, PATCH',
					'Access-Control-Max-Age' => '1728000',
					'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
					'Access-Control-Allow-Credentials' => 'false',
				],
				'default answer' => self::DEFAULT_HEADERS,
				'custom preflight' => [
					'Access-Control-Allow-Origin' => 'https://a.nl',
					'Access-Control-Allow-Methods' => 'GET, X-METHODS',
					'Access-Control-Max-Age' => '42',
					'Access-Control-Allow-Headers' => 'X-Headers',
					'Access-Control-Allow-Credentials' => 'false',
				],
				'custom answer' => [
					'Access-Control-Allow-Origin' => 'https://a.nl',
					'Access-Control-Allow-Methods' => 'GET, X-METHODS',
					'Access-Control-Allow-Headers' => 'X-Headers',
				],
			],
			[
				'default preflight' => $this->cors(response: $this->build(custom: false)->preflightedCors()),
				'default answer' => $this->cors(response: $this->answer(controller: $this->build(custom: false))),
				'custom preflight' => $this->cors(response: $this->build(custom: true)->preflightedCors()),
				'custom answer' => $this->cors(response: $this->answer(controller: $this->build(custom: true))),
			]
		);

	}//end testHeadersMatchTheRecording()

	/**
	 * A public answer with the controller's CORS headers added.
	 *
	 * @param PublicationsController $controller The controller.
	 *
	 * @return Response The answer.
	 */
	private function answer(PublicationsController $controller): Response {
		$response = new JSONResponse(['x' => 1]);
		(new ReflectionMethod(PublicationsController::class, 'addCorsHeaders'))->invoke($controller, $response);

		return $response;

	}//end answer()

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
	 * The controller over doubles, with default or custom CORS settings.
	 *
	 * @param bool $custom Whether to pass custom CORS settings.
	 *
	 * @return PublicationsController The controller.
	 */
	private function build(bool $custom): PublicationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Origin' ? 'https://evil.nl' : ''));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'cors_allowed_origins' ? 'https://a.nl' : $default)
		);

		$args = [
			'opencatalogi',
			$request,
			$this->createMock(PublicationServiceInterface::class),
			$this->createMock(CatalogiServiceInterface::class),
			$this->createMock(PublicationQueryServiceInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			$this->createMock(UsageCounterServiceInterface::class),
			$config,
		];
		if ($custom === true) {
			$args[] = new CorsSettings('GET, X-METHODS', 'X-Headers', 42);
		}

		return new PublicationsController(...$args);

	}//end build()
}//end class
