<?php

/**
 * Golden test for SearchQueryTranslator.
 *
 * Every input is translated as search parameters and as a saved query, and the
 * results are compared to the recording in fixtures/search-query-translator-golden.json.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SearchQueryTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the search query translation.
 *
 * @covers \OCA\OpenCatalogi\Service\SearchQueryTranslator
 */
class SearchQueryTranslatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/search-query-translator-golden.json';

	/**
	 * Every input translates as recorded.
	 *
	 * @return void
	 */
	public function testTranslationsMatchTheRecording(): void {
		$params = [
			'empty' => [],
			'renames' => ['informatiecategorie' => 'c1', 'organisation' => 'o1', 'other' => 'x'],
			'rename blocked by the property' => ['informatiecategorie' => 'c1', 'wooCategory' => 'keep'],
			'empty renames dropped' => ['informatiecategorie' => '', 'organisation' => []],
			'null rename kept' => ['organisation' => null],
			'period both' => ['periodFrom' => '2026-01-01', 'periodTo' => '2026-12-31'],
			'period empty and null' => ['periodFrom' => '', 'periodTo' => null],
			'period into an existing range' => ['periodFrom' => 'a', 'periodTo' => 'b', 'publicationDate' => ['gte' => 'keep']],
			'period with a scalar range' => ['periodFrom' => 'a', 'publicationDate' => '2026'],
			'period zero' => ['periodTo' => '0', 'periodFrom' => 0],
			'period with a null bound' => ['periodFrom' => 'a', 'publicationDate' => ['gte' => null]],
		];
		$saved = [
			'empty' => [],
			'text and catalog' => ['text' => '  wind  ', 'catalog' => ' woo '],
			'blank text' => ['text' => '   ', 'catalog' => ''],
			'filters not a list' => ['filters' => 'x', 'text' => 'a'],
			'filters' => ['filters' => ['informatiecategorie' => 'c', 'organisation' => '', 'periodFrom' => '2026', 'periodTo' => [], 'other' => 'drop']],
			'numeric text' => ['text' => 42, 'catalog' => 7],
			'null filter' => ['filters' => ['periodTo' => null, 'organisation' => 'o']],
			'null organisation' => ['filters' => ['organisation' => null, 'informatiecategorie' => 'c']],
		];

		$actual = ['params' => [], 'saved' => []];
		foreach ($params as $label => $input) {
			$actual['params'][$label] = SearchQueryTranslator::translateSearchParams(params: $input);
		}

		foreach ($saved as $label => $input) {
			$actual['saved'][$label] = SearchQueryTranslator::fromSavedQuery(query: $input);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));

	}//end testTranslationsMatchTheRecording()
}//end class
