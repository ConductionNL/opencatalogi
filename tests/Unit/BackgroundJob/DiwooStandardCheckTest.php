<?php

/**
 * Tests for the daily DiWoo standard version check.
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

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\DiwooStandardCheck;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The job reads the authority through the real StandardsVersionService over a
 * recorded version index; only the HTTP client and the store are doubles.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
 */
#[CoversClass(DiwooStandardCheck::class)]
#[UsesClass(StandardsVersionService::class)]
class DiwooStandardCheckTest extends TestCase {

	/**
	 * The values the job stored, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A job whose HTTP client answers the version index with the given status and body,
	 * or throws when $status is null.
	 *
	 * @param int|null $status The HTTP status, null for a connection failure.
	 * @param string   $body   The response body.
	 *
	 * @return DiwooStandardCheck
	 */
	private function job(?int $status, string $body = ''): DiwooStandardCheck {
		$client = $this->createMock(IClient::class);
		if ($status === null) {
			$client->method('get')->willThrowException(new \RuntimeException('connection refused'));
		} else {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);
			$client->expects($this->once())->method('get')
				->with(StandardsVersionService::DIWOO_VERSION_INDEX, $this->anything())
				->willReturn($response);
		}

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1763337600);

		return new DiwooStandardCheck(
			$time,
			new StandardsVersionService($clients, $this->createMock(DirectoryService::class)),
			$config,
			$this->createMock(LoggerInterface::class),
		);
	}//end job()

	/**
	 * The stored result, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function storedResult(): array {
		$this->assertArrayHasKey('opencatalogi/' . DiwooStandardCheck::CONFIG_KEY, $this->stored);

		return json_decode($this->stored['opencatalogi/' . DiwooStandardCheck::CONFIG_KEY], true);
	}//end storedResult()

	public function testANewerVersionFailsReadiness(): void {
		$html = (string)file_get_contents(__DIR__ . '/../../fixtures/diwoo-version-index-0.9.9.html');

		$job = $this->job(status: 200, body: $html);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$result = $this->storedResult();
		$this->assertFalse($result['ready']);
		$this->assertSame(StandardsVersionService::STATUS_BEHIND, $result['status']);
		$this->assertSame('0.9.9', $result['published']);
		$this->assertSame('DiWoo 0.9.9 is current; this instance emits ' . StandardsVersionService::DIWOO_VERSION, $result['message']);
		$this->assertSame(1763337600, $result['checkedAt']);
	}//end testANewerVersionFailsReadiness()

	public function testAnUnreachableAuthorityIsNotCurrent(): void {
		$result = $this->job(status: null)->check();

		$this->assertFalse($result['ready']);
		$this->assertSame(StandardsVersionService::STATUS_UNKNOWN, $result['status']);
		$this->assertNull($result['published']);
		$this->assertSame('could not verify', $result['message']);
		$this->assertSame($result, $this->storedResult());
	}//end testAnUnreachableAuthorityIsNotCurrent()

	public function testAnErrorPageIsNotCurrent(): void {
		$result = $this->job(status: 503, body: 'De huidige versie is v9.9.9')->check();

		$this->assertFalse($result['ready']);
		$this->assertSame('could not verify', $result['message']);
	}//end testAnErrorPageIsNotCurrent()

	public function testTheEmittedVersionBeingCurrentIsReady(): void {
		$html = '<p>De huidige versie van standaard en publicatievoorwaarden is versie v' . StandardsVersionService::DIWOO_VERSION . '</p>';

		$result = $this->job(status: 200, body: $html)->check();

		$this->assertTrue($result['ready']);
		$this->assertSame(StandardsVersionService::STATUS_CURRENT, $result['status']);
	}//end testTheEmittedVersionBeingCurrentIsReady()

	public function testItRunsDailyAndNotInParallel(): void {
		$job = $this->job(status: null);

		$this->assertSame(86400, (new \ReflectionProperty($job, 'interval'))->getValue($job));
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testItRunsDailyAndNotInParallel()

	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>' . DiwooStandardCheck::class . '</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
