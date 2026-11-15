<?php

/**
 * Unit tests for PublicationQueryService::resolveSchemaAndRegisterObjects().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A catalog's schema and register ids are resolved to {id, slug, title} keyed
 * by the id as given; ids the mappers cannot find are left out, and without
 * OpenRegister both sets are empty.
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 */
class PublicationQuerySchemaRegisterLabelsTest extends TestCase {

	/**
	 * A mapper double whose find() answers entities for the known ids.
	 *
	 * @param array<int, array{0: string, 1: string}> $known Id => [slug, title].
	 */
	private static function mapper(array $known): object {
		return new class($known) {
			/** @var list<int> */
			public array $asked = [];

			public function __construct(private array $known) {
			}

			public function find(int $id): object {
				$this->asked[] = $id;
				if (isset($this->known[$id]) === false) {
					throw new RuntimeException('Not found: ' . $id);
				}

				[$slug, $title] = $this->known[$id];
				return new class($id, $slug, $title) {
					public function __construct(private int $id, private string $slug, private string $title) {
					}

					public function getId(): int {
						return $this->id;
					}

					public function getSlug(): string {
						return $this->slug;
					}

					public function getTitle(): string {
						return $this->title;
					}
				};
			}
		};
	}

	/**
	 * The service over a container holding the two mappers.
	 */
	private function service(object $schemaMapper, object $registerMapper): PublicationQueryService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Db\SchemaMapper', $schemaMapper],
				['OCA\OpenRegister\Db\RegisterMapper', $registerMapper],
			]
		);

		return new PublicationQueryService(container: $container);
	}

	public function testIdsAreResolvedAndUnknownOnesLeftOut(): void {
		$schemas = self::mapper([3 => ['besluit', 'Besluit'], 4 => ['advies', 'Advies']]);
		$registers = self::mapper([1 => ['woo', 'Woo-register']]);

		$resolved = $this->service($schemas, $registers)->resolveSchemaAndRegisterObjects(
			['schemas' => ['3', 4, 99], 'registers' => [1, 2]]
		);

		$this->assertSame(
			[
				'schemas' => [
					'3' => ['id' => 3, 'slug' => 'besluit', 'title' => 'Besluit'],
					4 => ['id' => 4, 'slug' => 'advies', 'title' => 'Advies'],
				],
				'registers' => [
					1 => ['id' => 1, 'slug' => 'woo', 'title' => 'Woo-register'],
				],
			],
			$resolved
		);
		$this->assertSame([3, 4, 99], $schemas->asked);
		$this->assertSame([1, 2], $registers->asked);
	}

	public function testIdListsStoredAsJsonAreDecoded(): void {
		$resolved = $this->service(self::mapper([7 => ['zaak', 'Zaak']]), self::mapper([8 => ['open', 'Open']]))
			->resolveSchemaAndRegisterObjects(['schemas' => '[7]', 'registers' => '[8]']);

		$this->assertSame(['id' => 7, 'slug' => 'zaak', 'title' => 'Zaak'], $resolved['schemas'][7]);
		$this->assertSame(['id' => 8, 'slug' => 'open', 'title' => 'Open'], $resolved['registers'][8]);
	}

	public function testUnreadableJsonAndMissingKeysResolveToNothing(): void {
		$schemas = self::mapper([]);
		$resolved = $this->service($schemas, self::mapper([]))->resolveSchemaAndRegisterObjects(['schemas' => 'not json']);

		$this->assertSame(['schemas' => [], 'registers' => []], $resolved);
		$this->assertSame([], $schemas->asked);
	}

	public function testWithoutOpenRegisterBothSetsAreEmpty(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$resolved = (new PublicationQueryService(container: $container))->resolveSchemaAndRegisterObjects(['schemas' => [1], 'registers' => [1]]);

		$this->assertSame(['schemas' => [], 'registers' => []], $resolved);
	}
}
