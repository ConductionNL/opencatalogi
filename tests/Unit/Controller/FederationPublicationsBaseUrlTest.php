<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the base URL FederationController::publications() hands to the
 * aggregation for its pagination links, and what it answers and logs when
 * the aggregation fails.
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FederationController::publications().
 */
class FederationPublicationsBaseUrlTest extends TestCase {

	/**
	 * The server values and the base URL they give.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function servers(): array {
		return [
			'plain http' => [['HTTPS' => '', 'HTTP_HOST' => 'woo.example.nl', 'REQUEST_URI' => '/api/publications'], 'http://woo.example.nl/api/publications'],
			'https on' => [['HTTPS' => 'on', 'HTTP_HOST' => 'woo.example.nl', 'REQUEST_URI' => '/api/publications?_page=2&x=1'], 'https://woo.example.nl/api/publications'],
			'https off' => [['HTTPS' => 'off', 'HTTP_HOST' => 'woo.example.nl', 'REQUEST_URI' => '/a'], 'http://woo.example.nl/a'],
			'https as 1' => [['HTTPS' => '1', 'HTTP_HOST' => 'h', 'REQUEST_URI' => '/a'], 'https://h/a'],
			'https as 0' => [['HTTPS' => '0', 'HTTP_HOST' => 'h', 'REQUEST_URI' => '/a'], 'http://h/a'],
			'no host or uri' => [[], 'http://localhost/'],
			'only a query (strtok skips a leading ?)' => [['HTTP_HOST' => 'h', 'REQUEST_URI' => '?x=1'], 'http://hx=1'],
		];

	}//end servers()

	/**
	 * Restore the server values the cases change.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
	}//end tearDown()

	/**
	 * The base URL is the scheme, the host and the path without its query.
	 *
	 * @param array<string, string> $server The server values.
	 * @param string $expected The base URL.
	 *
	 * @return void
	 */
	#[DataProvider('servers')]
	public function testTheBaseUrlIsTheSchemeHostAndPath(array $server, string $expected): void {
		unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
		foreach ($server as $key => $value) {
			$_SERVER[$key] = $value;
		}

		$asked = [];
		$service = $this->createMock(PublicationService::class);
		$service->method('getAggregatedPublications')->willReturnCallback(
			static function (array $queryParams, array $requestParams, string $baseUrl) use (&$asked): array {
				$asked[] = [$queryParams, $requestParams, $baseUrl];
				return ['results' => []];
			}
		);

		$response = $this->controller(service: $service, logger: null)->publications();

		$this->assertSame(200, $response->getStatus());
		// Built without a collector, the controller fails closed to the `unknown` entry.
		$this->assertSame(
			['results' => [], 'appliedFilters' => [['name' => 'unknown', 'value' => null, 'reason' => 'not recorded']]],
			$response->getData()
		);
		$this->assertSame([[['q' => 'woo'], ['q' => 'woo'], $expected]], $asked);
	}//end testTheBaseUrlIsTheSchemeHostAndPath()

	/**
	 * A failing aggregation answers 500 with the translated message and is logged.
	 *
	 * @return void
	 */
	public function testAFailingAggregationIsLoggedAndAnswers500(): void {
		$service = $this->createMock(PublicationService::class);
		$service->method('getAggregatedPublications')->willThrowException(new RuntimeException('peer down'));

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, array_keys($context), $context['error']];
			}
		);

		$response = $this->controller(service: $service, logger: $logger)->publications();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve publications'], $response->getData());
		$this->assertSame([['[FederationController::publications] Failed to retrieve publications', ['error', 'trace'], 'peer down']], $logged);

		$silent = $this->controller(service: $service, logger: null)->publications();
		$this->assertSame(500, $silent->getStatus());
	}//end testAFailingAggregationIsLoggedAndAnswers500()

	/**
	 * The controller over a request with one query parameter.
	 *
	 * @param PublicationService $service The publication service.
	 * @param LoggerInterface|null $logger The logger, or none.
	 *
	 * @return FederationController
	 */
	private function controller(PublicationService $service, ?LoggerInterface $logger): FederationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['q' => 'woo']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new FederationController('opencatalogi', $request, $service, $l10n, $logger);
	}//end controller()
}//end class
