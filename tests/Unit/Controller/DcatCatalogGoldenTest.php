<?php

/**
 * Golden test for DcatController::catalog().
 *
 * Every case serves one catalog feed over recording doubles and compares the
 * answer (status, headers, body) and every call, in order, to the recording in
 * fixtures/dcat-catalog-golden.json.
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
use OCA\OpenCatalogi\Contract\DcatSerializerInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Http\DcatResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the per-catalog DCAT feed.
 *
 * @covers \OCA\OpenCatalogi\Controller\DcatController
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @covers \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @uses   \OCA\OpenCatalogi\Http\DcatResponse
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 */
class DcatCatalogGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dcat-catalog-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$meta = ['etag' => '"abc"', 'lastModified' => 'Sun, 01 Nov 2026 10:00:00 GMT'];

		return [
			'served' => [['meta' => $meta]],
			'served on page 3' => [['meta' => $meta, 'params' => ['page' => '3']]],
			'a page below one' => [['meta' => $meta, 'params' => ['page' => '-4']]],
			'turtle' => [['meta' => $meta, 'format' => 'turtle']],
			'no caching metadata' => [['meta' => []]],
			'unsupported format' => [['format' => null]],
			'unknown catalog' => [['catalog' => null]],
			'harvesting disabled' => [['enabled' => false]],
			'etag matches' => [['meta' => $meta, 'headers' => ['If-None-Match' => ' "abc" ']]],
			'etag differs' => [['meta' => $meta, 'headers' => ['If-None-Match' => '"old"']]],
			'no etag but a conditional request' => [['meta' => ['lastModified' => 'x'], 'headers' => ['If-None-Match' => '']]],
			'an empty conditional request against an empty etag' => [['meta' => ['etag' => ''], 'headers' => ['If-None-Match' => '']]],
			'a blank conditional request against an empty etag' => [['meta' => ['etag' => ''], 'headers' => ['If-None-Match' => '  ']]],
			'the guard throws' => [['fail' => 'guard']],
			'the build throws' => [['fail' => 'build']],
			'from an allowed origin' => [['meta' => $meta, 'headers' => ['Origin' => 'https://harvester.example']]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCatalogMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		try {
			$response = $this->controller(case: $case)->catalog(catalogSlug: 'woo');
			$actual = [
				'status' => $response->getStatus(),
				'class' => get_class($response),
				'headers' => $response->getHeaders(),
				'calls' => $this->calls,
			];
			if ($response instanceof DcatResponse) {
				$actual['body'] = $response->render();
			} else {
				$actual['data'] = $response->getData();
			}
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testCatalogMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DcatController The controller.
	 */
	private function controller(array $case): DcatController {
		$params = ($case['params'] ?? []);
		$headers = ($case['headers'] ?? []);
		$fail = ($case['fail'] ?? '');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null) use ($params): mixed {
				$this->calls[] = ['getParam' => $key];
				return ($params[$key] ?? $default);
			}
		);
		$request->method('getHeader')->willReturnCallback(
			function (string $name) use ($headers): string {
				$this->calls[] = ['getHeader' => $name];
				return ($headers[$name] ?? '');
			}
		);

		$serializer = $this->createMock(DcatSerializerInterface::class);
		$serializer->method('negotiate')->willReturnCallback(
			function (?string $format, ?string $accept) use ($case): ?string {
				$this->calls[] = ['negotiate' => [$format, $accept]];
				return array_key_exists('format', $case) === true ? $case['format'] : 'jsonld';
			}
		);
		$serializer->method('serialize')->willReturnCallback(
			function (array $document, string $format): string {
				$this->calls[] = ['serialize' => [$document, $format]];
				return $format . ':' . json_encode($document);
			}
		);

		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('getCatalogBySlug')->willReturnCallback(
			function (string $slug) use ($case): ?array {
				$this->calls[] = ['getCatalogBySlug' => $slug];
				return array_key_exists('catalog', $case) === true ? $case['catalog'] : ['id' => 'c-1', 'schemas' => [1, 2]];
			}
		);

		$dcat = $this->createMock(DcatServiceInterface::class);
		$dcat->method('isDcatEnabled')->willReturnCallback(
			function (array $catalog) use ($case): bool {
				$this->calls[] = ['isDcatEnabled' => $catalog];
				return ($case['enabled'] ?? true);
			}
		);
		$dcat->method('buildCatalogDocument')->willReturnCallback(
			function (array $catalog, string $catalogSlug, int $page = 1) use ($case, $fail): array {
				$this->calls[] = ['buildCatalogDocument' => [$catalog, $catalogSlug, $page]];
				if ($fail === 'build') {
					throw new \RuntimeException('build failed');
				}

				return ['@type' => 'dcat:Catalog', '_meta' => ($case['meta'] ?? [])];
			}
		);

		$query = $this->createMock(PublicationQueryServiceInterface::class);
		$query->method('applyCatalogReadRuleGuard')->willReturnCallback(
			function (array $catalog) use ($fail): array {
				$this->calls[] = ['applyCatalogReadRuleGuard' => $catalog];
				if ($fail === 'guard') {
					throw new \RuntimeException('guard failed');
				}

				return ['schemas' => [1]] + $catalog;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['error' => [$message, $context]];
			}
		);

		return new DcatController(
			'opencatalogi',
			$request,
			$dcat,
			$serializer,
			$catalogi,
			$query,
			$l10n,
			$logger,
			$this->createMock(StandardsVersionServiceInterface::class)
		);
	}//end controller()
}//end class
