<?php

/**
 * Golden test for the two CSV renderers and the plain-array readers of WooService and RetentionService.
 *
 * Pins the exact bytes of WooService::renderInventarislijstCsv() and
 * RetentionService::renderReportCsv() (BOM, header row, column order,
 * missing keys, quoting of commas, quotes and newlines, numbers and null),
 * and what each service's normalise() makes of every result shape.
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
 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
 *
 * @covers \OCA\OpenCatalogi\Service\WooService
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RetentionService;
use OCA\OpenCatalogi\Service\WooService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden test for the CSV renderers and normalise().
 */
class CsvAndPlainArrayGoldenTest extends TestCase {

	/**
	 * The inventory list keeps its municipal headers and column order.
	 *
	 * @return void
	 */
	public function testInventoryCsv(): void {
		$woo = (new ReflectionClass(WooService::class))->newInstanceWithoutConstructor();
		$csv = $woo->renderInventarislijstCsv(
			[
				['volgnummer' => '1', 'document' => 'Besluit, deel 1', 'datum' => '2026-01-02', 'beoordeling' => 'openbaar', 'weigeringsgronden' => ''],
				['document' => 'Zegt "nee"', 'volgnummer' => 2, 'extra' => 'x'],
				['beoordeling' => "regel 1\nregel 2", 'weigeringsgronden' => null],
			]
		);

		$this->assertSame(
			"\xEF\xBB\xBF" . "Volgnummer,Document,Datum,Beoordeling,Weigeringsgronden\n"
			. "1,\"Besluit, deel 1\",2026-01-02,openbaar,\n"
			. "2,\"Zegt \"\"nee\"\"\",,,\n"
			. ",,,\"regel 1\nregel 2\",\n",
			$csv
		);
		$this->assertSame("\xEF\xBB\xBF" . "Volgnummer,Document,Datum,Beoordeling,Weigeringsgronden\n", $woo->renderInventarislijstCsv([]));

	}//end testInventoryCsv()

	/**
	 * The retention report uses its field names as headers.
	 *
	 * @return void
	 */
	public function testRetentionCsv(): void {
		$retention = (new ReflectionClass(RetentionService::class))->newInstanceWithoutConstructor();
		$header = 'id,title,publicationDate,retentionCategory,retentionTermMonths,retentionExpiresAt,status,actionTaken,decisionBy,decisionAt,decisionNote';
		$csv = $retention->renderReportCsv(
			[
				['id' => 'p-1', 'title' => 'A, B', 'retentionTermMonths' => 12, 'decisionNote' => 'zei "ja"'],
				['status' => 'expired', 'unknown' => 'x'],
			]
		);

		$this->assertSame(
			"\xEF\xBB\xBF" . $header . "\n"
			. "p-1,\"A, B\",,,12,,,,,,\"zei \"\"ja\"\"\"\n"
			. ",,,,,,expired,,,,\n",
			$csv
		);

	}//end testRetentionCsv()

	/**
	 * Both normalise() copies read every result shape the same way.
	 *
	 * @return void
	 */
	public function testNormalise(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e-1', 'object' => ['x' => 1]];
			}
		};
		$scalar = new class {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}
		};
		$shapes = [
			'array' => [['a' => 1], ['a' => 1]],
			'envelope' => [['id' => 'v', 'object' => ['y' => 2]], ['id' => 'v', 'object' => ['y' => 2]]],
			'entity' => [$entity, ['id' => 'e-1', 'object' => ['x' => 1]]],
			'entity to scalar' => [$scalar, []],
			'plain object' => [new \stdClass(), []],
			'string' => ['s', []],
			'null' => [null, []],
		];
		foreach ([WooService::class, RetentionService::class] as $class) {
			$service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
			$method = new ReflectionMethod($service, 'normalise');
			foreach ($shapes as $name => [$in, $out]) {
				$this->assertSame($out, $method->invoke($service, $in), $class . ' ' . $name);
			}
		}

	}//end testNormalise()
}//end class
