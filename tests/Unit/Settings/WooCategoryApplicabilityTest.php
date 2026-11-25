<?php

/**
 * Tests for the shipped Woo category applicability table (REQ-WVL-004).
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\ValueList\CategoryApplicability;
use PHPUnit\Framework\TestCase;

/**
 * Every entry of `lib/Settings/woo-category-applicability.json` cites the Woo and quotes the scope it rests on.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
class WooCategoryApplicabilityTest extends TestCase {

	/**
	 * The shipped table.
	 *
	 * @return array<string, mixed>
	 */
	private static function table(): array {
		return (array)json_decode((string)file_get_contents(CategoryApplicability::TABLE), true, 512, JSON_THROW_ON_ERROR);
	}//end table()

	/**
	 * The skos:definition of each concept in the recorded TOOI list, by concept URI.
	 *
	 * @return array<string, string>
	 */
	private static function definitions(): array {
		$definitions = [];
		$recorded = (string)file_get_contents(__DIR__ . '/../../fixtures/tooi/scw_woo_informatiecategorieen_4.json');
		foreach ((array)json_decode($recorded, true) as $node) {
			$definition = ($node['http://www.w3.org/2004/02/skos/core#definition'][0]['@value'] ?? null);
			if ($definition !== null) {
				$definitions[(string)$node['@id']] = (string)$definition;
			}
		}

		return $definitions;
	}//end definitions()

	/**
	 * Each entry names its category, cites the Woo article and quotes the category's scope word for word.
	 *
	 * The quoted scope is checked against the recorded TOOI list, so a basis
	 * cannot be paraphrased into something the law does not say.
	 *
	 * @return void
	 */
	public function testEveryEntryCitesItsLegalBasis(): void {
		$table = self::table();
		$definitions = self::definitions();
		$tooi = new TooiVocabularyService();

		$this->assertNotEmpty(actual: $table['entries']);
		foreach ($table['entries'] as $entry) {
			$this->assertTrue(condition: CategoryApplicability::cites(entry: $entry), message: (string)($entry['category'] ?? '?') . ' cites its basis');
			$this->assertSame(expected: 'Wet open overheid, artikel 3.3', actual: $entry['article']);
			$this->assertSame(
				expected: ($definitions[$entry['tooiUri']] ?? null),
				actual: $entry['basis'],
				message: $entry['category'] . ': the basis is the scope the TOOI list records'
			);
			$this->assertSame(
				expected: $entry['tooiUri'],
				actual: $tooi->resolveInformatiecategorie(value: $entry['category'])['uri'] ?? null,
				message: $entry['category'] . ' is the category its URI names'
			);
			$this->assertNotSame(expected: '', actual: trim((string)$entry['reason']));
			$this->assertNotEmpty(actual: $entry['notApplicableTo']);
			$this->assertSame(expected: [], actual: array_values(array_diff($entry['notApplicableTo'], $table['types'])), message: 'only known types');
			$this->assertNotContains(needle: 'overig', haystack: $entry['notApplicableTo'], message: 'nothing is proposed for an unnamed type');
		}
	}//end testEveryEntryCitesItsLegalBasis()

	/**
	 * The types are the seven the Woo settings offer.
	 *
	 * @return void
	 */
	public function testTheTypesAreTheSevenOffered(): void {
		$this->assertSame(
			expected: ['gemeente', 'provincie', 'waterschap', 'gemeenschappelijke-regeling', 'rijksoverheid', 'zelfstandig-bestuursorgaan', 'overig'],
			actual: self::table()['types']
		);
	}//end testTheTypesAreTheSevenOffered()
}//end class
