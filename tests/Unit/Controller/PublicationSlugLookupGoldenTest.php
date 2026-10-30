<?php

/**
 * Golden test for the catalogue-scoped slug lookup of PublicationsController.
 *
 * Every case is recorded once and replayed from the fixture: the publication
 * found (by uuid) or none, every search sent to OpenRegister, and every
 * warning logged.
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
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for the catalogue-scoped slug lookup of PublicationsController.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 */
class PublicationSlugLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-slug-lookup-golden.json';

	/**
	 * Everything asked and logged, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: slug, registers, schemas and the stored slugs per register:schema.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<int, mixed>, 3: array<string, list<string>>}>
	 */
	public static function cases(): array {
		return [
			'one match' => ['besluit-1', [1], [1], ['1:1:besluit-1' => ['u-1']]],
			'normalised before the lookup' => ['  Besluit_2026 0042!! ', [1], [1], ['1:1:besluit-2026-0042' => ['u-2']]],
			'nothing left after normalising' => ['!!!', [1], [1], []],
			'no match' => ['missing', [1, 2], [3, 4], []],
			'match in the last pair' => ['x', [1, 2], [3, 4], ['2:4:x' => ['u-9']]],
			'two matches in one pair' => ['dup', [1, 2], [3, 4], ['1:3:dup' => ['u-a', 'u-b']]],
			'two matches across pairs' => ['dup', [1, 2], [3, 4], ['1:4:dup' => ['u-a'], '2:3:dup' => ['u-b'], '2:4:dup' => ['u-c']]],
			'search answers no list' => ['odd', [1], [1, 2], ['1:1:odd' => 'not-a-list', '1:2:odd' => ['u-5']]],
			'long slug is cut' => [str_repeat('a', 300), [1], [1], []],
			'dashes collapse' => ['--a---b--', [1], [1], ['1:1:a-b' => ['u-6']]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string                       $slug      The URL id.
	 * @param array<int, mixed>            $registers The catalogue's registers.
	 * @param array<int, mixed>            $schemas   The catalogue's schemas.
	 * @param array<string, list<string>|string> $stored The stored slugs per register:schema:slug.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testLookupMatchesTheRecording(string $slug, array $registers, array $schemas, array $stored): void {
		$name = $this->dataName();
		$found = $this->lookup(objectService: $this->objects(stored: $stored), args: [$slug, 'woo', $registers, $schemas]);
		$actual = ['found' => ($found === null ? null : $found->getUuid()), 'log' => $this->log];

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

	}//end testLookupMatchesTheRecording()

	/**
	 * Call the private lookup on a controller over doubles.
	 *
	 * @param object            $objectService The ObjectService double.
	 * @param array<int, mixed> $args          Slug, catalog slug, registers, schemas.
	 *
	 * @return object|null The match.
	 */
	private function lookup(object $objectService, array $args): ?object {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['warning' => $message, 'context' => $context];
			}
		);

		$controller = new PublicationsController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$this->createMock(PublicationServiceInterface::class),
			$this->createMock(CatalogiServiceInterface::class),
			$this->createMock(PublicationQueryServiceInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$logger,
			$this->createMock(IL10N::class),
			$this->createMock(UsageCounterServiceInterface::class)
		);

		[$slug, $catalogSlug, $registers, $schemas] = $args;
		$method = new ReflectionMethod(PublicationsController::class, 'findBySlugInCatalogScope');

		return $method->invoke($controller, $objectService, $slug, $catalogSlug, $registers, $schemas);

	}//end lookup()

	/**
	 * An ObjectService double that answers slug searches from a map and records them.
	 *
	 * @param array<string, list<string>|string> $stored The stored slugs per register:schema:slug.
	 *
	 * @return object The double.
	 */
	private function objects(array $stored): object {
		$log = &$this->log;

		return new class($log, $stored) {
			public function __construct(
				private array &$log,
				private array $stored,
			) {
			}

			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->log[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$self = $query['@self'];
				$hit = ($this->stored[$self['register'] . ':' . $self['schema'] . ':' . $self['slug']] ?? []);
				if (is_array($hit) === false) {
					return $hit;
				}

				return array_map(
					static fn (string $uuid): object => new class($uuid) {
						public function __construct(
							private string $uuid,
						) {
						}

						public function getUuid(): string {
							return $this->uuid;
						}
					},
					$hit
				);
			}
		};

	}//end objects()
}//end class
