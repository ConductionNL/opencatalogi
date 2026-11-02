<?php

/**
 * Golden test for the `opencatalogi.searchCatalog` agent tool.
 *
 * Runs `OpenCatalogiToolProvider::invokeTool('opencatalogi.searchCatalog')`
 * over a scripted PublicationService and compares the answer, the search it
 * asked for and every log line with what was recorded: the argument
 * refusals, the catalog narrowing, the error and failure paths, the result
 * shapes and the cap of 20 with its truncation flag.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Mcp/SearchCatalogToolGoldenTest.php`.
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
 * @covers \OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for OpenCatalogiToolProvider's searchCatalog tool.
 */
class SearchCatalogToolGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/search-catalog-tool-golden.json';

	/**
	 * A list of $count publications as arrays.
	 *
	 * @param int $count How many.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function publications(int $count): array {
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = ['uuid' => "pub-{$i}", 'title' => "Publication {$i}"];
		}

		return $rows;
	}//end publications()

	/**
	 * The arguments and the PublicationService answer of each case.
	 *
	 * Result rows with `entity` are served as an ObjectEntity whose
	 * jsonSerialize() returns that array; rows with `plain` as a plain object.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$ok = ['results' => self::publications(2)];
		return [
			'no query' => [['args' => []]],
			'query not a string' => [['args' => ['query' => 12]]],
			'blank query' => [['args' => ['query' => "  \t "]]],
			'limit zero' => [['args' => ['query' => 'woo', 'limit' => 0]]],
			'limit 51' => [['args' => ['query' => 'woo', 'limit' => 51]]],
			'limit text' => [['args' => ['query' => 'woo', 'limit' => 'many']]],
			'limit null is the default' => [['args' => ['query' => 'woo', 'limit' => null], 'data' => $ok]],
			'limit as string' => [['args' => ['query' => ' woo ', 'limit' => '5'], 'data' => $ok]],
			'limit 50' => [['args' => ['query' => 'woo', 'limit' => 50], 'data' => $ok]],
			'catalog trimmed' => [['args' => ['query' => 'woo', 'catalog' => ' cat-1 '], 'data' => $ok]],
			'catalog blank' => [['args' => ['query' => 'woo', 'catalog' => '   '], 'data' => $ok]],
			'catalog not a string' => [['args' => ['query' => 'woo', 'catalog' => 7], 'data' => $ok]],
			'search fails anonymously' => [['args' => ['query' => 'woo'], 'fails' => true]],
			'search fails for a user' => [['args' => ['query' => 'woo'], 'fails' => true, 'user' => 'alice']],
			'search answers an error' => [['args' => ['query' => 'woo'], 'data' => ['error' => 'Catalog not found', 'results' => []]]],
			'search answers a string' => [['args' => ['query' => 'woo'], 'data' => 'nothing']],
			'search answers no results key' => [['args' => ['query' => 'woo'], 'data' => ['total' => 3]]],
			'results not a list' => [['args' => ['query' => 'woo'], 'data' => ['results' => 'x']]],
			'mixed result shapes' => [[
				'args' => ['query' => 'woo'],
				'data' => [
					'results' => [
						['id' => 'by-id', 'name' => 'Named'],
						['@self' => ['uuid' => 'self-uuid']],
						['slug' => 'by-slug'],
						['entity' => ['uuid' => 'entity-uuid', 'title' => 'Entity']],
						['plain' => ['id' => 'plain-id']],
						'scalar',
					],
				],
			]],
			'exactly twenty' => [['args' => ['query' => 'woo'], 'data' => ['results' => self::publications(20)]]],
			'more than twenty' => [['args' => ['query' => 'woo', 'limit' => 40], 'data' => ['results' => self::publications(23)]]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSearchCatalogMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->invoke(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testSearchCatalogMatchesTheRecording()

	/**
	 * Build the provider over scripted collaborators and invoke the tool.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The answer, the search calls and the log.
	 */
	private function invoke(array $case): array {
		$calls = [];
		$data = ($case['data'] ?? []);
		if (is_array($data) === true && is_array($data['results'] ?? null) === true) {
			foreach ($data['results'] as $index => $row) {
				if (is_array($row) === true && isset($row['entity']) === true) {
					$entity = $this->getMockBuilder(ObjectEntity::class)
						->disableOriginalConstructor()
						->onlyMethods(['jsonSerialize'])
						->getMock();
					$entity->method('jsonSerialize')->willReturn($row['entity']);
					$data['results'][$index] = $entity;
				} else if (is_array($row) === true && isset($row['plain']) === true) {
					$data['results'][$index] = (object)$row['plain'];
				}
			}
		}

		$publications = $this->createMock(PublicationServiceInterface::class);
		$publications->method('index')->willReturnCallback(
			static function (?string $catalogId = null, array $customParams = []) use (&$calls, $case, $data): JSONResponse {
				$calls[] = ['index', $catalogId, $customParams];
				if (($case['fails'] ?? false) === true) {
					throw new RuntimeException('search down');
				}

				return new JSONResponse($data);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$provider = new OpenCatalogiToolProvider(
			publicationService: $publications,
			userSession: $session,
			logger: $logger
		);
		$answer = $provider->invokeTool('opencatalogi.searchCatalog', $case['args']);

		return ['answer' => json_decode((string)json_encode($answer), true), 'calls' => $calls];

	}//end invoke()
}//end class
