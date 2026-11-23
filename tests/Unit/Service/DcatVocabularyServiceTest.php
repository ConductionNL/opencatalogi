<?php

/**
 * Unit tests for DcatVocabularyService.
 *
 * Covers HVD-category and EU MDR data-theme resolution to controlled authority
 * URIs, and the fail-closed behaviour (unresolvable input → null).
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DcatVocabularyService.
 */
class DcatVocabularyServiceTest extends TestCase {

	private DcatVocabularyService $service;

	/**
	 * Set up the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new DcatVocabularyService();

	}//end setUp()

	/**
	 * HVD category resolves by label, alias, bare id, and full URI.
	 *
	 * @return void
	 */
	public function testResolveHvdCategory(): void {
		$expected = 'http://data.europa.eu/bna/c_b79e35eb';

		foreach (['Mobility', 'mobiliteit', 'c_b79e35eb', $expected] as $input) {
			$resolved = $this->service->resolveHvdCategory($input);
			$this->assertNotNull($resolved, "should resolve: $input");
			$this->assertSame($expected, $resolved['uri']);
			$this->assertSame('Mobility', $resolved['label']);
		}

		$this->assertNull($this->service->resolveHvdCategory('not-a-category'));
		$this->assertNull($this->service->resolveHvdCategory(null));

	}//end testResolveHvdCategory()

	/**
	 * All six ODD HVD categories are bundled.
	 *
	 * @return void
	 */
	public function testHvdCategoryListHasSixMembers(): void {
		$this->assertCount(6, $this->service->hvdCategoryList());

	}//end testHvdCategoryListHasSixMembers()

	/**
	 * Data theme resolves by MDR code, synonym, and full URI; unknown → null.
	 *
	 * @return void
	 */
	public function testResolveDataTheme(): void {
		$tran = 'http://publications.europa.eu/resource/authority/data-theme/TRAN';
		$this->assertSame($tran, $this->service->resolveDataTheme('TRAN'));
		$this->assertSame($tran, $this->service->resolveDataTheme('transport'));
		$this->assertSame($tran, $this->service->resolveDataTheme('vervoer'));
		$this->assertSame($tran, $this->service->resolveDataTheme($tran));

		$this->assertSame(
			'http://publications.europa.eu/resource/authority/data-theme/ENVI',
			$this->service->resolveDataTheme('milieu')
		);

		$this->assertNull($this->service->resolveDataTheme('nonsense-theme'));
		$this->assertNull($this->service->resolveDataTheme(null));

	}//end testResolveDataTheme()
	/**
	 * The licence list names the authority it was taken from and that list's version (REQ-DCC-001).
	 *
	 * @return void
	 */
	public function testTheLicenceListCarriesItsSourceAndVersion(): void {
		$this->assertSame(
			expected: ['source' => 'http://publications.europa.eu/resource/authority/licence', 'version' => '20260617-0'],
			actual: $this->service->licenceListSource()
		);

		$list = $this->service->licenceList();
		$this->assertSame(expected: ['CC0', 'CC_BY_4_0', 'CC_BYNC_4_0', 'CC_BYSA_4_0', 'CC_PDM_1_0'], actual: array_keys($list));
		foreach ($list as $code => $member) {
			$this->assertSame(expected: 'http://publications.europa.eu/resource/authority/licence/' . $code, actual: $member['iri']);
			$this->assertNotSame(expected: '', actual: $member['label']);
			$this->assertNotSame(expected: '', actual: $member['labelNl']);
		}

	}//end testTheLicenceListCarriesItsSourceAndVersion()

	/**
	 * A licence resolves from its authority IRI, code, SPDX id, deed IRI and label (REQ-DCC-001).
	 *
	 * @return void
	 */
	public function testALicenceResolvesFromIriCodeOrLabel(): void {
		$cc0 = 'http://publications.europa.eu/resource/authority/licence/CC0';
		$inputs = [
			$cc0,
			'CC0',
			'cc0',
			'CC0-1.0',
			'http://creativecommons.org/publicdomain/zero/1.0/',
			'https://creativecommons.org/publicdomain/zero/1.0',
			'http://creativecommons.org/publicdomain/zero/1.0/deed.nl',
			'Creative Commons CC0 1.0 Universal',
			'creative commons cc0 1.0 universeel',
			'CC-0 (1.0)',
		];
		foreach ($inputs as $input) {
			$this->assertSame(expected: $cc0, actual: $this->service->resolveLicence($input), message: "should resolve: $input");
		}

		$this->assertSame(
			expected: 'http://publications.europa.eu/resource/authority/licence/CC_BY_4_0',
			actual: $this->service->resolveLicence('https://creativecommons.org/licenses/by/4.0/deed.nl')
		);
		$this->assertSame(
			expected: 'http://publications.europa.eu/resource/authority/licence/CC_PDM_1_0',
			actual: $this->service->resolveLicence('Publiek domein')
		);

	}//end testALicenceResolvesFromIriCodeOrLabel()

	/**
	 * Free text, a licence outside the list, and blanks resolve to null (REQ-DCC-001).
	 *
	 * @return void
	 */
	public function testAnUnknownLicenceResolvesToNull(): void {
		$inputs = ['vrij te gebruiken', 'CC_BY_3_0', 'http://creativecommons.org/licenses/by/3.0/', 'Creative Commons', 'https://lic', '', '   '];
		foreach ($inputs as $input) {
			$this->assertNull(actual: $this->service->resolveLicence($input), message: "should not resolve: $input");
		}

		$this->assertNull(actual: $this->service->resolveLicence(null));

	}//end testAnUnknownLicenceResolvesToNull()
}//end class
