<?php

/**
 * Tests for the Access-Control-Allow-Origin answer of PublicationsController.
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
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The preflight answers an allowed caller Origin, read from the header or
 * from the server variables, and otherwise the first allowed origin.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 */
class PublicationsCorsOriginTest extends TestCase {

	/**
	 * Header origin, server origin, and the origin answered.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string}>
	 */
	public static function origins(): array {
		return [
			'allowed origin in the header' => ['https://b.nl', null, 'https://b.nl'],
			'allowed origin only in the server variables' => ['', 'https://b.nl', 'https://b.nl'],
			'unknown origin in the server variables' => ['', 'https://evil.nl', 'https://a.nl'],
			'no origin at all' => ['', null, 'https://a.nl'],
			'the header wins over the server variables' => ['https://evil.nl', 'https://b.nl', 'https://a.nl'],
		];

	}//end origins()

	/**
	 * The preflight's Access-Control-Allow-Origin for a caller.
	 *
	 * @param string      $header   The Origin header.
	 * @param string|null $server   HTTP_ORIGIN in the server variables, or null for none.
	 * @param string      $expected The origin answered.
	 *
	 * @return void
	 */
	#[DataProvider('origins')]
	public function testThePreflightAnswersTheAllowedOrigin(string $header, ?string $server, string $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Origin' ? $header : ''));
		$request->server = ($server === null ? [] : ['HTTP_ORIGIN' => $server]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'cors_allowed_origins' ? ' https://a.nl , https://b.nl ,' : $default)
		);

		$controller = new PublicationsController(
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
			$config
		);

		$this->assertSame($expected, $controller->preflightedCors()->getHeaders()['Access-Control-Allow-Origin']);

	}//end testThePreflightAnswersTheAllowedOrigin()
}//end class
