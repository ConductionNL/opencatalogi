<?php

/**
 * Golden test for `toFields()` of the CMS and attachment migration services.
 *
 * Both flatten an OpenRegister row into a field map with its uuid resolved:
 * from @self uuid, @self id, the row's uuid or id, in that order. Pinned for
 * every row shape and for both services.
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
 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
 *
 * @covers \OCA\OpenCatalogi\Service\CmsMigrationService
 * @covers \OCA\OpenCatalogi\Service\AttachmentMigrationService
 * @covers \OCA\OpenCatalogi\Service\FlattensObjectRows
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\AttachmentMigrationService;
use OCA\OpenCatalogi\Service\CmsMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for the migration services' toFields().
 */
class MigrationRowFieldsGoldenTest extends TestCase {

	/**
	 * Both services flatten every row shape the same way.
	 *
	 * @return void
	 */
	public function testToFields(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['title' => 'E', '@self' => ['uuid' => 'eu']];
			}
		};
		$scalar = new class {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};
		$cases = [
			'self uuid' => [['t' => 1, '@self' => ['uuid' => 'su', 'id' => 'si'], 'uuid' => 'ru'], ['t' => 1, '@self' => ['uuid' => 'su', 'id' => 'si'], 'uuid' => 'su']],
			'self id' => [['@self' => ['id' => 7]], ['@self' => ['id' => 7], 'uuid' => '7']],
			'self not a list' => [['@self' => 'x', 'id' => 'ri'], ['@self' => 'x', 'id' => 'ri', 'uuid' => 'ri']],
			'empty self uuid falls back' => [['@self' => ['uuid' => ''], 'uuid' => 'ru'], ['@self' => ['uuid' => ''], 'uuid' => 'ru']],
			'row id' => [['id' => 3], ['id' => 3, 'uuid' => '3']],
			'nothing' => [['title' => 'T'], ['title' => 'T', 'uuid' => '']],
			'entity' => [$entity, ['title' => 'E', '@self' => ['uuid' => 'eu'], 'uuid' => 'eu']],
			'entity to scalar' => [$scalar, []],
			'object without serialiser' => [new \stdClass(), []],
			'string' => ['s', []],
			'null' => [null, []],
		];
		foreach ([CmsMigrationService::class, AttachmentMigrationService::class] as $class) {
			$service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
			foreach ($cases as $name => [$row, $fields]) {
				$this->assertSame($fields, $service->toFields($row), $class . ' ' . $name);
			}
		}

	}//end testToFields()
}//end class
