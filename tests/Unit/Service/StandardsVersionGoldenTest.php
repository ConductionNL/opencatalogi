<?php

/**
 * Golden test for StandardsVersionService's fetch and version parsing.
 *
 * Every case answers the version index requests from a world (status, body,
 * stream, network error, SSRF refusal) and compares the published versions, the
 * comparisons and the requests made to fixtures/standards-version-golden.json.
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

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the standards version lookups.
 *
 * @covers \OCA\OpenCatalogi\Service\StandardsVersionService
 */
class StandardsVersionGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/standards-version-golden.json';

	/**
	 * The recorded cases: how the version indexes answer.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$dcat = 'x def-st-dcat-ap-nl30-20240101 y def-st-dcat-ap-nl31-20250601 z def-st-dcat-ap-nl21-20220101';

		return [
			'both answer' => [['body' => $dcat . ' DiWoo 0.9.8 versie 0.9.9 ']],
			'answer as a stream' => [['body' => $dcat, 'stream' => true]],
			'not found' => [['status' => 404, 'body' => $dcat]],
			'redirect' => [['status' => 301, 'body' => $dcat]],
			'network error' => [['throws' => true]],
			'ssrf refused' => [['ssrf' => true]],
			'no version in the page' => [['body' => 'nothing here']],
			'dcat same date twice' => [['body' => 'def-st-dcat-ap-nl30-20240101 def-st-dcat-ap-nl40-20240101']],
			'dcat three digits' => [['body' => 'def-st-dcat-ap-nl301-20260101 def-st-dcat-ap-nl30-20250101']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVersionsMatchTheRecording(array $world): void {
		$name = $this->dataName();
		$requests = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options = []) use ($world, &$requests): IResponse {
				$requests[] = [$url, $options];
				if (($world['throws'] ?? false) === true) {
					throw new \RuntimeException('network');
				}

				$body = ($world['body'] ?? '');
				if (($world['stream'] ?? false) === true) {
					$stream = fopen('php://memory', 'r+');
					fwrite($stream, $body);
					rewind($stream);
					$body = $stream;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(($world['status'] ?? 200));
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$directory = $this->createMock(DirectoryService::class);
		if (($world['ssrf'] ?? false) === true) {
			$directory->method('validateOutboundUrl')->willThrowException(new InvalidArgumentException('blocked'));
		}

		$service = new StandardsVersionService($clients, $directory);
		$actual = [
			'diwoo' => $service->publishedDiwooVersion(),
			'dcat' => $service->publishedDcatApNlVersion(),
			'diwoo again' => $service->publishedDiwooVersion(),
			'diwooComparison' => $service->diwooVersion(),
			'dcatComparison' => $service->dcatApNlVersion(),
			'parsedDcat' => StandardsVersionService::parseDcatApNlVersion(html: (string)($world['body'] ?? '')),
			'requests' => $requests,
		];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testVersionsMatchTheRecording()
}//end class
