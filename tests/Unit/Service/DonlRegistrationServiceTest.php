<?php

/**
 * Tests for the data.overheid.nl registration and its harvest evidence.
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

namespace Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DonlRegistrationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The registration status, the fetch recorder and the DONL count.
 *
 * @covers \OCA\OpenCatalogi\Service\DonlRegistrationService
 * @covers \OCA\OpenCatalogi\Controller\DcatController
 * @uses   \OCA\OpenCatalogi\Service\DcatSerializer
 * @uses   \OCA\OpenCatalogi\Http\DcatResponse
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 */
class DonlRegistrationServiceTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The URLs the HTTP client was asked.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * An app config over $this->stored.
	 *
	 * @return IAppConfig The config.
	 */
	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end config()

	/**
	 * The service, with an HTTP client that answers $status and $body or throws $error.
	 *
	 * @param int $status The HTTP status.
	 * @param string $body The body.
	 * @param \Throwable|null $error What the client throws instead.
	 *
	 * @return DonlRegistrationService The service.
	 */
	private function service(int $status = 200, string $body = '{}', ?\Throwable $error = null): DonlRegistrationService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($response, $error): IResponse {
				$this->asked[] = $url;
				if ($error !== null) {
					throw $error;
				}

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new DonlRegistrationService($this->config(), $clients);
	}//end service()

	/**
	 * Registered, no recorded fetch and a count of 0 is not harvested.
	 *
	 * @return void
	 */
	public function testRegisteredWithoutEvidenceIsNotHarvested(): void {
		$this->stored = [
			'donl_registration_status' => 'registered',
			'donl_dataset_count' => '0',
		];

		$status = $this->service()->status();

		$this->assertSame('registered', $status['status']);
		$this->assertSame(0, $status['donlDatasetCount']);
		$this->assertFalse($status['harvested']);
		$this->assertTrue(DonlRegistrationService::isHarvested(status: ['lastFetchAt' => '2026-11-01T00:00:00+00:00']));
		$this->assertTrue(DonlRegistrationService::isHarvested(status: ['donlDatasetCount' => 118]));
	}//end testRegisteredWithoutEvidenceIsNotHarvested()

	/**
	 * A fetch of /api/dcat by the configured harvester is recorded, through the controller.
	 *
	 * @return void
	 */
	public function testAHarvesterFetchIsRecorded(): void {
		$this->stored = ['donl_harvester_pattern' => 'ckanext-dcat'];
		$donl = $this->service();

		$this->controllerAs(userAgent: 'Mozilla/5.0', donl: $donl)->instance();
		$this->assertArrayNotHasKey('donl_last_fetch_at', $this->stored);

		$this->controllerAs(userAgent: 'CKANEXT-DCAT harvester/1.0', donl: $donl)->instance();
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->stored['donl_last_fetch_at']);
		$this->assertTrue($donl->status()['harvested']);
	}//end testAHarvesterFetchIsRecorded()

	/**
	 * Without a configured pattern no fetch is ever recorded.
	 *
	 * @return void
	 */
	public function testWithoutAPatternNoFetchIsRecorded(): void {
		$this->assertFalse($this->service()->recordFetch(userAgent: 'anything'));
		$this->assertSame([], $this->stored);
	}//end testWithoutAPatternNoFetchIsRecorded()

	/**
	 * The operator's status gets its date once; an unknown status or a plain-http API is refused.
	 *
	 * @return void
	 */
	public function testTheOperatorRecordsTheRegistration(): void {
		$service = $this->service();
		$first = new DateTimeImmutable('2026-10-01T09:00:00+00:00');

		$status = $service->record(input: ['status' => 'requested', 'source' => 'https://example.org/api/dcat', 'apiUrl' => 'https://ckan.example/api/3'], now: $first);
		$this->assertSame('requested', $status['status']);
		$this->assertSame('2026-10-01T09:00:00+00:00', $status['requestedAt']);
		$this->assertSame('https://example.org/api/dcat', $status['source']);

		$status = $service->record(input: ['status' => 'requested'], now: new DateTimeImmutable('2026-10-05T09:00:00+00:00'));
		$this->assertSame('2026-10-01T09:00:00+00:00', $status['requestedAt']);
		$this->assertSame('', $status['registeredAt']);

		$this->expectException(InvalidArgumentException::class);
		$service->record(input: ['status' => 'harvested']);
	}//end testTheOperatorRecordsTheRegistration()

	/**
	 * A plain-http API URL is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAPlainHttpApiIsRefused(): void {
		try {
			$this->service()->record(input: ['status' => 'requested', 'apiUrl' => 'http://ckan.example/api/3']);
			$this->fail('A plain-http API URL was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([], $this->stored);
		}
	}//end testAPlainHttpApiIsRefused()

	/**
	 * The check asks package_search for our source and stores the count and the feed count.
	 *
	 * @return void
	 */
	public function testTheCheckStoresTheDonlCount(): void {
		$this->stored = ['donl_api_url' => 'https://ckan.example/api/3/', 'donl_source' => 'https://example.org/api/dcat', 'donl_last_error' => 'old'];

		$status = $this->service(body: '{"success":true,"result":{"count":118,"results":[]}}')
			->check(feedDatasetCount: 120, now: new DateTimeImmutable('2026-11-13T03:00:00+00:00'));

		$this->assertSame(
			['https://ckan.example/api/3/action/package_search?fq=source_catalog%3A%22https%3A%2F%2Fexample.org%2Fapi%2Fdcat%22&rows=0'],
			$this->asked
		);
		$this->assertSame(118, $status['donlDatasetCount']);
		$this->assertSame(120, $status['feedDatasetCount']);
		$this->assertSame('2026-11-13T03:00:00+00:00', $status['donlCountAt']);
		$this->assertSame('', $status['lastError']);
		$this->assertTrue($status['harvested']);
	}//end testTheCheckStoresTheDonlCount()

	/**
	 * An answer without a count, or no configuration, keeps the count and says why.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutACountIsAnError(): void {
		$this->stored = ['donl_api_url' => 'https://ckan.example/api/3', 'donl_source' => 's', 'donl_dataset_count' => '7'];
		$status = $this->service(status: 503, body: 'down')->check(feedDatasetCount: null);
		$this->assertSame(7, $status['donlDatasetCount']);
		$this->assertStringContainsString('503', $status['lastError']);

		$this->stored = [];
		$this->asked = [];
		$status = $this->service()->check(feedDatasetCount: null);
		$this->assertStringContainsString('not configured', $status['lastError']);
		$this->assertSame([], $this->asked);
	}//end testAnAnswerWithoutACountIsAnError()

	/**
	 * A DcatController whose request carries this user agent.
	 *
	 * @param string $userAgent The User-Agent header.
	 * @param DonlRegistrationService $donl The registration service.
	 *
	 * @return DcatController The controller.
	 */
	private function controllerAs(string $userAgent, DonlRegistrationService $donl): DcatController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match (strtolower($name)) {
				'user-agent' => $userAgent,
				'accept' => 'text/turtle',
				default => '',
			}
		);
		$dcat = $this->createMock(DcatServiceInterface::class);
		$dcat->method('buildInstanceDocument')->willReturn(['@context' => [], '@graph' => []]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DcatController(
			'opencatalogi',
			$request,
			$dcat,
			new DcatSerializer(),
			$this->createMock(CatalogiServiceInterface::class),
			$this->createMock(PublicationQueryServiceInterface::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->createMock(StandardsVersionServiceInterface::class),
			null,
			$donl
		);
	}//end controllerAs()
}//end class
