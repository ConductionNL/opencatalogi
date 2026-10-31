<?php

/**
 * Golden test for the OpenRegister result readers (`asArray()`).
 *
 * Feeds every reader the same shapes OpenRegister can answer with (entities,
 * envelopes with and without ids, bare property arrays and non-arrays) and
 * pins the properties each one reads. Two rules are in use: the envelope
 * rule (ReadsOpenRegisterResults, CommentPeriodWithdrawalService,
 * WooRequestStore) keeps the envelope id and falls back to the properties'
 * id, and the record rule (the two withheld stores) always sets an id taken
 * from the envelope, its @self or its uuid.
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
 * @spec exclude characterization of a pure shape adapter over the consumed OR ObjectService.
 *
 * @covers \OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults
 * @covers \OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestStore
 * @covers \OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore
 * @covers \OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore
 * @covers \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden test for the asArray() readers.
 */
class ObjectPropertiesGoldenTest extends TestCase {

	/**
	 * The shapes OpenRegister can answer with.
	 *
	 * @return array<string, mixed>
	 */
	private static function shapes(): array {
		$entity = new class {
			/**
			 * The entity as OpenRegister serialises it.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'ent-1', 'uuid' => 'u-ent', 'object' => ['title' => 'From entity', 'id' => 'inner']];
			}
		};
		$scalarEntity = new class {
			/**
			 * An entity that serialises to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}
		};
		return [
			'entity' => $entity,
			'entity serialising to a scalar' => $scalarEntity,
			'object without jsonSerialize' => new \stdClass(),
			'envelope with id' => ['id' => 'env-1', 'object' => ['title' => 'T', 'id' => 'inner']],
			'envelope without id' => ['object' => ['title' => 'T', 'id' => 'inner']],
			'envelope without any id' => ['object' => ['title' => 'T']],
			'envelope with uuid only' => ['uuid' => 'u-1', 'object' => ['title' => 'T']],
			'envelope with @self id' => ['@self' => ['id' => 's-1'], 'object' => ['title' => 'T']],
			'envelope whose object is not a list' => ['id' => 'env-2', 'object' => 'text'],
			'plain properties' => ['title' => 'Plain', 'id' => 'p-1'],
			'plain properties with @self' => ['title' => 'Plain', '@self' => ['id' => 's-2']],
			'plain properties without id' => ['title' => 'Plain'],
			'empty list' => [],
			'string' => 'text',
			'null' => null,
			'integer' => 7,
		];

	}//end shapes()

	/**
	 * Every reader answers each shape as pinned.
	 *
	 * @return void
	 */
	public function testReadersMatchThePinnedProperties(): void {
		$readers = [
			'envelope:ReadsOpenRegisterResults' => new class {
				use ReadsOpenRegisterResults;
			},
			'envelope:CommentPeriodWithdrawalService' => (new ReflectionClass(CommentPeriodWithdrawalService::class))->newInstanceWithoutConstructor(),
			'envelope:WooRequestStore' => (new ReflectionClass(WooRequestStore::class))->newInstanceWithoutConstructor(),
			'record:WithheldFromPublicationStore' => (new ReflectionClass(WithheldFromPublicationStore::class))->newInstanceWithoutConstructor(),
			'record:WithheldDocumentStore' => (new ReflectionClass(WithheldDocumentStore::class))->newInstanceWithoutConstructor(),
		];
		$expected = [
			'envelope' => [
				'entity' => ['title' => 'From entity', 'id' => 'ent-1'],
				'entity serialising to a scalar' => [],
				'object without jsonSerialize' => [],
				'envelope with id' => ['title' => 'T', 'id' => 'env-1'],
				'envelope without id' => ['title' => 'T', 'id' => 'inner'],
				'envelope without any id' => ['title' => 'T', 'id' => null],
				'envelope with uuid only' => ['title' => 'T', 'id' => null],
				'envelope with @self id' => ['title' => 'T', 'id' => null],
				'envelope whose object is not a list' => ['id' => 'env-2', 'object' => 'text'],
				'plain properties' => ['title' => 'Plain', 'id' => 'p-1'],
				'plain properties with @self' => ['title' => 'Plain', '@self' => ['id' => 's-2']],
				'plain properties without id' => ['title' => 'Plain'],
				'empty list' => [],
				'string' => [],
				'null' => [],
				'integer' => [],
			],
			'record' => [
				'entity' => ['title' => 'From entity', 'id' => 'ent-1'],
				'entity serialising to a scalar' => [],
				'object without jsonSerialize' => [],
				'envelope with id' => ['title' => 'T', 'id' => 'env-1'],
				'envelope without id' => ['title' => 'T', 'id' => null],
				'envelope without any id' => ['title' => 'T', 'id' => null],
				'envelope with uuid only' => ['title' => 'T', 'id' => 'u-1'],
				'envelope with @self id' => ['title' => 'T', 'id' => 's-1'],
				'envelope whose object is not a list' => ['id' => 'env-2', 'object' => 'text'],
				'plain properties' => ['title' => 'Plain', 'id' => 'p-1'],
				'plain properties with @self' => ['title' => 'Plain', '@self' => ['id' => 's-2'], 'id' => 's-2'],
				'plain properties without id' => ['title' => 'Plain', 'id' => null],
				'empty list' => ['id' => null],
				'string' => [],
				'null' => [],
				'integer' => [],
			],
		];

		$want = [];
		$got = [];
		foreach ($readers as $name => $reader) {
			$rule = explode(':', $name)[0];
			$method = new ReflectionMethod($reader, ($rule === 'record' ? 'asRecord' : 'asArray'));
			foreach (self::shapes() as $shape => $value) {
				$want[$name][$shape] = $expected[$rule][$shape];
				$got[$name][$shape] = $method->invoke($reader, $value);
			}
		}

		$this->assertSame($want, $got);

	}//end testReadersMatchThePinnedProperties()
}//end class
