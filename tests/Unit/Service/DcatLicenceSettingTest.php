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
	 * The shipped default, a Creative Commons URI, is stored as the authority IRI.
	 *
	 * @return void
	 */
	public function testTheShippedDefaultResolves(): void {
		$this->assertSame(
			'http://publications.europa.eu/resource/authority/licence/CC0',
			DcatLicenceSetting::storable(value: 'http://creativecommons.org/publicdomain/zero/1.0/', vocabulary: new DcatVocabularyService())
		);
	}//end testTheShippedDefaultResolves()

	/**
	 * An empty choice stores no default licence.
	 *
	 * @return void
	 */
	public function testAnEmptyChoiceStoresNothing(): void {
		$vocabulary = new DcatVocabularyService();

		$this->assertSame('', DcatLicenceSetting::storable(value: '  ', vocabulary: $vocabulary));
		$this->assertSame('', DcatLicenceSetting::storable(value: null, vocabulary: $vocabulary));
	}//end testAnEmptyChoiceStoresNothing()

	/**
	 * Free text or a value that is not text is refused, naming the setting.
	 *
	 * @return void
	 */
	public function testAnUnlistedValueIsRefused(): void {
		foreach (['vrij te gebruiken', 42] as $value) {
			try {
				DcatLicenceSetting::storable(value: $value, vocabulary: new DcatVocabularyService());
				$this->fail('Refusal expected for ' . var_export($value, true));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('dcat_default_license', $e->getMessage());
			}
		}
	}//end testAnUnlistedValueIsRefused()
}//end class
