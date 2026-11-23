<?php

/**
 * Unit tests for DcatLicenceSetting: the default DCAT licence is a member of
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
use OCA\OpenCatalogi\Service\DcatLicenceSetting;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\DcatLicenceSetting
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 */
final class DcatLicenceSettingTest extends TestCase {

	/**
	 * The setting on the real bundled list.
	 *
	 * @return DcatLicenceSetting
	 */
	private function setting(): DcatLicenceSetting {
		return new DcatLicenceSetting(vocabulary: new DcatVocabularyService());
	}//end setting()

	/**
	 * The shipped default, a Creative Commons URI, is stored as the authority IRI.
	 *
	 * @return void
	 */
	public function testTheShippedDefaultResolves(): void {
		$this->assertSame(
			expected: 'http://publications.europa.eu/resource/authority/licence/CC0',
			actual: $this->setting()->storable(value: 'http://creativecommons.org/publicdomain/zero/1.0/')
		);
	}//end testTheShippedDefaultResolves()

	/**
	 * An empty choice stores no default licence.
	 *
	 * @return void
	 */
	public function testAnEmptyChoiceStoresNothing(): void {
		$this->assertSame(expected: '', actual: $this->setting()->storable(value: '  '));
		$this->assertSame(expected: '', actual: $this->setting()->storable(value: null));
	}//end testAnEmptyChoiceStoresNothing()

	/**
	 * Free text or a value that is not text is refused, naming the setting.
	 *
	 * @return void
	 */
	public function testAnUnlistedValueIsRefused(): void {
		foreach (['vrij te gebruiken', 42] as $value) {
			try {
				$this->setting()->storable(value: $value);
				$this->fail(message: 'Refusal expected for ' . var_export(value: $value, return: true));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString(needle: 'dcat_default_license', haystack: $e->getMessage());
			}
		}
	}//end testAnUnlistedValueIsRefused()
}//end class
