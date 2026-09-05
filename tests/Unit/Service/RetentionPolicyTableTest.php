<?php

/**
 * Unit tests for RetentionPolicyTable.
 *
 * Covers the RET-004 translation of stored retention defaults into the shared
 * OR DMN decision-table grammar: FIRST hit policy, quoted-literal category
 * cells (with `"` escaping), the `_fallback` row as the trailing `-`
 * catch-all, non-array rows skipped and missing keys as null output entries.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RetentionPolicyTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionPolicyTableTest extends TestCase {

	private RetentionPolicyTable $table;

	protected function setUp(): void {
		$this->table = new RetentionPolicyTable();

	}//end setUp()

	public function testFromDefaultsBuildsAFirstPolicyTableWithTrailingFallback(): void {
		$result = $this->table->fromDefaults(
			[
				'vergunningen' => ['termMonths' => 12, 'action' => 'depublish'],
				'_fallback' => ['termMonths' => 6, 'action' => 'review'],
				'beschikkingen' => ['termMonths' => 24],
			]
		);

		$this->assertNotNull($result);
		$this->assertSame('FIRST', $result['hitPolicy']);
		$this->assertSame([['name' => 'category', 'type' => 'string']], $result['inputs']);
		$this->assertSame(
			[
				['name' => 'termMonths', 'type' => 'number'],
				['name' => 'action', 'type' => 'string'],
			],
			$result['outputs']
		);

		// Categories in configured order, `_fallback` LAST however early it
		// was configured, missing `action` as a null output entry.
		$this->assertSame(
			[
				['id' => 'cat:vergunningen', 'inputEntries' => ['"vergunningen"'], 'outputEntries' => [12, 'depublish']],
				['id' => 'cat:beschikkingen', 'inputEntries' => ['"beschikkingen"'], 'outputEntries' => [24, null]],
				['id' => '_fallback', 'inputEntries' => ['-'], 'outputEntries' => [6, 'review']],
			],
			$result['rules']
		);

	}//end testFromDefaultsBuildsAFirstPolicyTableWithTrailingFallback()

	public function testFromDefaultsQuotesAndEscapesCategoryCells(): void {
		$result = $this->table->fromDefaults(['zeg "nee"' => ['termMonths' => 3]]);

		$this->assertNotNull($result);
		$this->assertSame(['"zeg \\"nee\\""'], $result['rules'][0]['inputEntries']);

	}//end testFromDefaultsQuotesAndEscapesCategoryCells()

	public function testFromDefaultsSkipsNonArrayRowsAndReturnsNullWhenNothingUsable(): void {
		$this->assertNull($this->table->fromDefaults([]));
		$this->assertNull($this->table->fromDefaults(['vergunningen' => 'not-a-rule']));

		// A usable row next to a broken one survives.
		$result = $this->table->fromDefaults(['broken' => 12, 'ok' => ['action' => 'archive']]);
		$this->assertNotNull($result);
		$this->assertCount(1, $result['rules']);
		$this->assertSame('cat:ok', $result['rules'][0]['id']);
		$this->assertSame([null, 'archive'], $result['rules'][0]['outputEntries']);

	}//end testFromDefaultsSkipsNonArrayRowsAndReturnsNullWhenNothingUsable()
}//end class
