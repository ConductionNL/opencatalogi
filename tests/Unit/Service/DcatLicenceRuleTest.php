<?php

/**
 * Unit tests for DcatLicenceRule: the default DCAT licence is a member of
 * the EU licences authority list, stored as its authority IRI.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\DcatLicenceRule;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\DcatLicenceRule
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 */
final class DcatLicenceRuleTest extends TestCase {

	/**
	 * The rule on the real bundled list.
	 *
	 * @return DcatLicenceRule
	 */
	private function rule(): DcatLicenceRule {
		return new DcatLicenceRule(vocabulary: new DcatVocabularyService());
	}//end setting()

	/**
	 * The shipped default, a Creative Commons URI, is stored as the authority IRI.
	 *
	 * @return void
	 */
	public function testTheShippedDefaultResolves(): void {
		$this->assertSame(
			expected: 'http://publications.europa.eu/resource/authority/licence/CC0',
			actual: $this->rule()->storable(value: 'http://creativecommons.org/publicdomain/zero/1.0/')
		);
	}//end testTheShippedDefaultResolves()

	/**
	 * An empty choice stores no default licence.
	 *
	 * @return void
	 */
	public function testAnEmptyChoiceStoresNothing(): void {
		$this->assertSame(expected: '', actual: $this->rule()->storable(value: '  '));
		$this->assertSame(expected: '', actual: $this->rule()->storable(value: null));
	}//end testAnEmptyChoiceStoresNothing()

	/**
	 * Free text or a value that is not text is refused, naming the setting.
	 *
	 * @return void
	 */
	public function testAnUnlistedValueIsRefused(): void {
		foreach (['vrij te gebruiken', 42] as $value) {
			try {
				$this->rule()->storable(value: $value);
				$this->fail(message: 'Refusal expected for ' . var_export(value: $value, return: true));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString(needle: 'dcat_default_license', haystack: $e->getMessage());
			}
		}
	}//end testAnUnlistedValueIsRefused()

	/**
	 * A code, an IRI or a matching Creative Commons URI is listed; free text and a list are not.
	 *
	 * @return void
	 */
	public function testIsListedFollowsTheBundledList(): void {
		$this->assertTrue(condition: $this->rule()->isListed(value: 'CC0'));
		$this->assertTrue(condition: $this->rule()->isListed(value: 'http://publications.europa.eu/resource/authority/licence/CC_BY_4_0'));
		$this->assertFalse(condition: $this->rule()->isListed(value: 'vrij te gebruiken'));
		$this->assertFalse(condition: $this->rule()->isListed(value: ['CC0']));
	}//end testIsListedFollowsTheBundledList()
}//end class
