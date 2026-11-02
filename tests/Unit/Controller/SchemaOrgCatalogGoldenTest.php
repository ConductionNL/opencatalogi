<?php

/**
 * Golden test for SchemaOrgController::catalog().
 *
 * Every case serves one catalog over recording doubles and compares the answer
 * and every collaborator call to the recording in
 * fixtures/schema-org-catalog-golden.json.
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
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCA\OpenCatalogi\Controller\SchemaOrgController;
use OCA\OpenCatalogi\Controller\SchemaOrgCatalogDocument;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for SchemaOrgController::catalog().
 *
 * @covers \OCA\OpenCatalogi\Controller\SchemaOrgController
 * @covers \OCA\OpenCatalogi\Controller\SchemaOrgCatalogDocument
 * @uses   \OCA\OpenCatalogi\Controller\AnswersCrossOriginReadRequests
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 */
class SchemaOrgCatalogGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schema-org-catalog-golden.json';

	/**
	 * The cases: what the slug lookup, the guard and the node builder answer.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$catalog = ['id' => 'c-1', 'slug' => 'open', 'schemas' => ['s-public', 's-private']];
		return [
			'found, guarded and built' => [['catalog' => $catalog, 'guarded' => ['id' => 'c-1', 'slug' => 'open', 'schemas' => ['s-public']], 'node' => ['@type' => 'DataCatalog', 'name' => 'Open']]],
			'found, guard passes through' => [['catalog' => $catalog, 'node' => ['@type' => 'DataCatalog']]],
			'unknown slug' => [['catalog' => null]],
			'slug lookup throws' => [['lookupThrows' => true]],
			'guard throws' => [['catalog' => $catalog, 'guardThrows' => true]],
			'node builder throws' => [['catalog' => $catalog, 'nodeThrows' => true]],
			'origin allowed by the allowlist' => [['catalog' => $catalog, 'node' => ['@type' => 'DataCatalog'], 'origin' => 'https://portal.example', 'allowlist' => 'https://portal.example']],
			'origin outside the allowlist' => [['catalog' => $catalog, 'node' => [], 'origin' => 'https://evil.example', 'allowlist' => 'https://portal.example']],
			'other slug text' => [['catalog' => $catalog, 'node' => ['n' => 1], 'slug' => 'Gemeente-Zuid']],
		];

	}//end cases()

	/**
	 * The answer and the calls match the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCatalogMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$calls = [];

		$catalogs = $this->createMock(CatalogiServiceInterface::class);
		$catalogs->method('getCatalogBySlug')->willReturnCallback(
			static function (string $slug) use (&$calls, $case): ?array {
				$calls[] = ['getCatalogBySlug', $slug];
				if (($case['lookupThrows'] ?? false) === true) {
					throw new \RuntimeException('lookup failed');
				}

				return $case['catalog'];
			}
		);

		$queries = $this->createMock(PublicationQueryServiceInterface::class);
		$queries->method('applyCatalogReadRuleGuard')->willReturnCallback(
			static function (array $catalog) use (&$calls, $case): array {
				$calls[] = ['applyCatalogReadRuleGuard', $catalog];
				if (($case['guardThrows'] ?? false) === true) {
					throw new \LogicException('guard failed');
				}

				return ($case['guarded'] ?? $catalog);
			}
		);

		$schemaOrg = $this->createMock(SchemaOrgServiceInterface::class);
		$schemaOrg->method('buildCatalogNode')->willReturnCallback(
			static function (array $catalog, string $slug) use (&$calls, $case): array {
				$calls[] = ['buildCatalogNode', $catalog, $slug];
				if (($case['nodeThrows'] ?? false) === true) {
					throw new \Error('node failed');
				}

				return $case['node'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$calls): void {
				$calls[] = ['logger.error', $message, $context];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $header): string => ($header === 'Origin' ? ($case['origin'] ?? '') : ''));

		$config = null;
		if (isset($case['allowlist']) === true) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->willReturn($case['allowlist']);
		}

		$controller = $this->controller(
			collaborators: ['request' => $request, 'schemaOrg' => $schemaOrg, 'catalogs' => $catalogs, 'queries' => $queries, 'l10n' => $l10n, 'logger' => $logger, 'config' => $config]
		);
		$response = $controller->catalog($case['slug'] ?? 'open');

		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'headers' => array_diff_key($response->getHeaders(), ['X-Request-Id' => true]),
			'calls' => $calls,
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
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testCatalogMatchesTheRecording()

	/**
	 * The controller under test, built over the given doubles.
	 *
	 * @param array<string, mixed> $collaborators The doubles by role.
	 *
	 * @return SchemaOrgController The controller.
	 */
	private function controller(array $collaborators): SchemaOrgController {
		return new SchemaOrgController(
			'opencatalogi',
			$collaborators['request'],
			new SchemaOrgCatalogDocument($collaborators['schemaOrg'], $collaborators['catalogs'], $collaborators['queries']),
			$collaborators['l10n'],
			$collaborators['logger'],
			$collaborators['config']
		);

	}//end controller()
}//end class
