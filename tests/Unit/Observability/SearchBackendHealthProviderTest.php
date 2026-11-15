<?php

/**
 * Unit tests for SearchBackendHealthProvider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/prometheus-metrics/tasks.md#3
 */

declare(strict_types=1);

namespace Unit\Observability;

use OCA\OpenCatalogi\Observability\SearchBackendHealthProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The search_backend check: database by default, a pinged Elasticsearch when configured.
 *
 * @covers \OCA\OpenCatalogi\Observability\SearchBackendHealthProvider
 */
class SearchBackendHealthProviderTest extends TestCase {

	/**
	 * HTTP client double.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The provider under test.
	 *
	 * @return SearchBackendHealthProvider
	 */
	private function provider(): SearchBackendHealthProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->client = $this->createMock(IClient::class);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);
		return new SearchBackendHealthProvider(
			appConfig: $appConfig,
			clientService: $clients,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end provider()

	/**
	 * A response with a status code.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return IResponse&MockObject
	 */
	private function response(int $status): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		return $response;

	}//end response()

	/**
	 * Without configuration the backend is the database and the check passes without a ping.
	 *
	 * @return void
	 */
	public function testTheDefaultDatabaseBackendPassesWithoutAPing(): void {
		$provider = $this->provider();
		$this->client->expects($this->never())->method('get');

		$this->assertSame('database', $provider->backendType());
		$this->assertSame(['search_backend' => ['ok' => true, 'severity' => 'degraded']], $provider->check());

	}//end testTheDefaultDatabaseBackendPassesWithoutAPing()

	/**
	 * An unknown backend value counts as the database.
	 *
	 * @return void
	 */
	public function testAnUnknownBackendCountsAsTheDatabase(): void {
		$this->config = ['search_backend' => 'solr'];

		$this->assertSame('database', $this->provider()->backendType());

	}//end testAnUnknownBackendCountsAsTheDatabase()

	/**
	 * A reachable Elasticsearch passes, pinged with the three-second timeout.
	 *
	 * @return void
	 */
	public function testAReachableElasticsearchPasses(): void {
		$this->config = ['search_backend' => 'elasticsearch', 'search_backend_url' => 'https://search.example/'];
		$provider = $this->provider();
		$this->client->expects($this->once())->method('get')
			->with('https://search.example/', ['timeout' => 3, 'connect_timeout' => 3, 'http_errors' => false])
			->willReturn($this->response(status: 200));

		$this->assertSame('elasticsearch', $provider->backendType());
		$this->assertSame(['search_backend' => ['ok' => true, 'severity' => 'degraded']], $provider->check());

	}//end testAReachableElasticsearchPasses()

	/**
	 * An unreachable or erroring Elasticsearch degrades the response with a generic message.
	 *
	 * @return void
	 */
	public function testAFailingElasticsearchDegrades(): void {
		$this->config = ['search_backend' => 'elasticsearch', 'search_backend_url' => 'https://search.example/'];
		$provider = $this->provider();
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->response(status: 503),
			$this->throwException(new RuntimeException('cURL error 28: timeout at 10.0.0.5'))
		);

		$this->assertSame(
			['search_backend' => ['ok' => false, 'severity' => 'degraded', 'message' => 'elasticsearch: HTTP 503']],
			$provider->check()
		);
		$unreachable = $provider->check();
		$this->assertSame('elasticsearch: unreachable', $unreachable['search_backend']['message']);
		$this->assertStringNotContainsString('10.0.0.5', json_encode($unreachable));

	}//end testAFailingElasticsearchDegrades()

	/**
	 * Elasticsearch without an address fails without a request.
	 *
	 * @return void
	 */
	public function testElasticsearchWithoutAnAddressFails(): void {
		$this->config = ['search_backend' => 'elasticsearch'];
		$provider = $this->provider();
		$this->client->expects($this->never())->method('get');

		$this->assertFalse($provider->check()['search_backend']['ok']);

	}//end testElasticsearchWithoutAnAddressFails()
}//end class
