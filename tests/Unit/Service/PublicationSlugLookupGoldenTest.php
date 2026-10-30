<?php

/**
 * Golden test for `PublicationQueryService::tryPublicationSlugLookup()`.
 *
 * Calls the slug fallback of the document-to-publication link directly over a
 * scripted ObjectService and compares the answer, the scan it asked for and
 * every log line with what was recorded: the register narrowing, the anonymous
 * evaluation (and its one-time error without runAsAnonymous), a failing scan,
 * slug matching on `@self.slug` and `slug`, entity rows, collisions, archived
 * matches and the summary fields.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/PublicationSlugLookupGoldenTest.php`.
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
 * @spec openspec/specs/search/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\PublicSearchAssembly
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicSearchAssembly;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for PublicationQueryService::tryPublicationSlugLookup().
 */
class PublicationSlugLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/publication-slug-lookup-golden.json';

	/**
	 * The slugs looked up, the scan answer and the store shape of each case.
	 *
	 * Rows with `entity` are served as an object whose jsonSerialize() returns that array.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['@self' => ['slug' => 'other', 'id' => 'x'], 'title' => 'Other'],
			['@self' => ['slug' => 'woo-besluit', 'id' => 'self-id'], 'id' => 'top-id', 'title' => 'Besluit'],
		];
		return [
			'match on @self slug, one register' => [['slugs' => ['woo-besluit'], 'register' => 4, 'results' => $rows]],
			'match on @self slug, no register' => [['slugs' => ['woo-besluit'], 'results' => $rows]],
			'match on top-level slug' => [['slugs' => ['plain'], 'results' => [['slug' => 'plain', 'id' => 'p-1']]]],
			'self slug wins over top-level slug' => [[
				'slugs' => ['top'],
				'results' => [['@self' => ['slug' => 'self'], 'slug' => 'top', 'id' => 'p-2']],
			]],
			'no match' => [['slugs' => ['missing'], 'results' => $rows]],
			'no results key' => [['slugs' => ['woo-besluit'], 'results' => null]],
			'empty results' => [['slugs' => ['woo-besluit'], 'results' => []]],
			'entity rows' => [[
				'slugs' => ['ent'],
				'results' => [['entity' => ['@self' => ['slug' => 'ent', 'id' => 'e-1'], 'title' => 'Entity']]],
			]],
			'collision uses the first' => [[
				'slugs' => ['dup'],
				'results' => [
					['slug' => 'dup', 'id' => 'first', 'title' => 'First'],
					['slug' => 'dup', 'id' => 'second', 'title' => 'Second'],
					['slug' => 'dup', 'id' => 'third'],
				],
			]],
			'archived first match' => [[
				'slugs' => ['arch'],
				'results' => [
					['slug' => 'arch', 'id' => 'a-1', 'status' => 'archived'],
					['slug' => 'arch', 'id' => 'a-2', 'status' => 'published'],
				],
			]],
			'no id and no title' => [['slugs' => ['bare'], 'results' => [['slug' => 'bare']]]],
			'numeric id' => [['slugs' => ['num'], 'results' => [['@self' => ['slug' => 'num', 'id' => 42], 'title' => 7]]]],
			'scan fails' => [['slugs' => ['woo-besluit'], 'fails' => true]],
			'no anonymous scope, twice' => [['slugs' => ['woo-besluit', 'other'], 'results' => $rows, 'anonymous' => false]],
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
	public function testSlugLookupMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->lookup(case: $case);

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

	}//end testSlugLookupMatchesTheRecording()

	/**
	 * Build the service over a scripted store and look up each slug.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The answers, the store calls and the log.
	 */
	private function lookup(array $case): array {
		$log = [];
		$results = [];
		foreach (($case['results'] ?? []) as $row) {
			if (isset($row['entity']) === true) {
				$data = $row['entity'];
				$row = new class($data) {
					/**
					 * @param array<string, mixed> $data The serialised row.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}

			$results[] = $row;
		}

		$answer = ['total' => count($results)];
		if (array_key_exists('results', $case) === true && $case['results'] !== null) {
			$answer['results'] = $results;
		}

		$fails = ($case['fails'] ?? false);
		if (($case['anonymous'] ?? true) === true) {
			$store = new class($answer, $fails) {
				/**
				 * @var list<array<string, mixed>>
				 */
				public array $calls = [];

				/**
				 * @param array<string, mixed> $answer The scan answer.
				 * @param bool $fails Whether the scan throws.
				 */
				public function __construct(private array $answer, private bool $fails) {
				}

				/**
				 * @param callable $operation The read to run anonymously.
				 *
				 * @return mixed
				 */
				public function runAsAnonymous(callable $operation): mixed {
					$this->calls[] = ['runAsAnonymous'];
					return $operation();
				}

				/**
				 * @param array<string, mixed> $query The query.
				 * @param bool $_rbac Whether RBAC applies.
				 * @param bool $_multitenancy Whether multitenancy applies.
				 *
				 * @return array<string, mixed>
				 */
				public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
					$this->calls[] = ['searchObjectsPaginated', $query, $_rbac, $_multitenancy];
					if ($this->fails === true) {
						throw new RuntimeException('scan down');
					}

					return $this->answer;
				}
			};
		} else {
			$store = new class($answer, $fails) {
				/**
				 * @var list<array<string, mixed>>
				 */
				public array $calls = [];

				/**
				 * @param array<string, mixed> $answer The scan answer.
				 * @param bool $fails Whether the scan throws.
				 */
				public function __construct(private array $answer, private bool $fails) {
				}

				/**
				 * @param array<string, mixed> $query The query.
				 * @param bool $_rbac Whether RBAC applies.
				 * @param bool $_multitenancy Whether multitenancy applies.
				 *
				 * @return array<string, mixed>
				 */
				public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
					$this->calls[] = ['searchObjectsPaginated', $query, $_rbac, $_multitenancy];
					return $this->answer;
				}
			};
		}//end if

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$log, $level): void {
					$log[] = [$level, $message, $context];
				}
			);
		}

		$service = new PublicationQueryService(
			container: $this->createMock(ContainerInterface::class),
			logger: $logger,
		);

		$run = new PublicSearchAssembly(objectService: $store, scope: ['registers' => [], 'schemas' => [12]]);
		$run->publicationSchemaId = 12;
		$run->refinementRegisterId = ($case['register'] ?? null);

		$method = new ReflectionMethod(PublicationQueryService::class, 'tryPublicationSlugLookup');
		$answers = [];
		foreach ($case['slugs'] as $slug) {
			$answers[$slug] = $method->invoke($service, $slug, $run);
		}

		return ['answers' => $answers, 'calls' => $store->calls, 'log' => $log];

	}//end lookup()
}//end class
