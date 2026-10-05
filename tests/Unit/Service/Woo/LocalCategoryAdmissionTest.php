<?php

/**
 * The admission of one stored information category, tested on its own.
 *
 * WooCategoryRegistryTest drives this class through the registry, which is how
 * production reaches it. These tests call it directly for the two answers the
 * registry cannot produce: a row carrying no code at all, and a call made with no
 * bundled set to reverse-look the member code in.
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LocalCategoryAdmission.
 */
class LocalCategoryAdmissionTest extends TestCase {

	/**
	 * The admission under test.
	 *
	 * @var LocalCategoryAdmission
	 */
	private LocalCategoryAdmission $admission;

	/**
	 * Wire the admission over the real waardelijst resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->admission = new LocalCategoryAdmission(new TooiVocabularyService());
	}

	public function testARowWithNoCodeIsRefusedUnderAPlaceholder(): void {
		// Refused rows are reported by code, and a row with no code still has to be
		// reportable, or the operator is told nothing about the one that vanished.
		$decision = $this->admission->admit(stored: ['title' => 'Naamloos', 'mapsTo' => 'infocat010'], bundled: []);

		$this->assertNull($decision['record']);
		$this->assertSame(LocalCategoryAdmission::CODE_MISSING, $decision['code']);
		$this->assertSame('the category stores no code', $decision['reason']);
	}

	public function testABlankCodeIsTheSameAsNoCode(): void {
		$decision = $this->admission->admit(stored: ['code' => '   ', 'mapsTo' => 'infocat010'], bundled: []);

		$this->assertNull($decision['record']);
		$this->assertSame(LocalCategoryAdmission::CODE_MISSING, $decision['code']);
	}

	public function testAnAdmittedRowStillCarriesItsUriWhenTheMemberCodeCannotBeNamed(): void {
		// `mapsTo` is the code of the bundled member, and it is reported empty when
		// the caller passes no bundled set. The URI is not: that one comes from the
		// waardelijst resolver, so it is present whatever the caller passed, which
		// is what keeps the sitemap guarantee independent of this argument.
		$decision = $this->admission->admit(
			stored: ['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010'],
			bundled: []
		);

		$this->assertNotNull($decision['record']);
		$this->assertSame('', $decision['record']['mapsTo']);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_99a836c7', $decision['record']['tooiUri']);
	}

	public function testARowWithoutATitleBorrowsTheMembersLabel(): void {
		$decision = $this->admission->admit(stored: ['code' => 'eigen', 'mapsTo' => 'infocat011'], bundled: []);

		$this->assertSame('Convenanten', $decision['record']['label']);
		$this->assertSame('Convenanten', $decision['record']['labelEn']);
	}

	public function testAnEnglishTitleIsKeptWhenGiven(): void {
		$decision = $this->admission->admit(
			stored: ['code' => 'eigen', 'title' => 'Eigen', 'titleEn' => 'Our own', 'mapsTo' => 'infocat011'],
			bundled: []
		);

		$this->assertSame('Eigen', $decision['record']['label']);
		$this->assertSame('Our own', $decision['record']['labelEn']);
	}

	public function testANonStringSchemaSlugIsDropped(): void {
		$decision = $this->admission->admit(
			stored: [
				'code' => 'eigen',
				'mapsTo' => 'infocat011',
				'schemas' => ['tender', 7, null, '  ', ['nested'], 'award'],
			],
			bundled: []
		);

		$this->assertSame(['tender', 'award'], $decision['record']['schemas']);
	}
}
