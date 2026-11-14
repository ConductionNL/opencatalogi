<?php

/**
 * Unit tests for GET /api/themes/{id}/publications and the subject detail links (REQ-SUB-003).
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

namespace Unit\Controller;

require_once __DIR__ . '/SubjectControllerFixture.php';

use OCA\OpenCatalogi\Controller\CorsSettings;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCA\OpenCatalogi\Service\CallerScope;
use OCA\OpenCatalogi\Service\Subject\SubjectPublications;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Unit\Service\Subject\FakeSubjectRegister;

/**
 * Unit tests for the publications under a subject.
 */
#[CoversClass(ThemesController::class)]
#[UsesClass(SubjectPublications::class)]
#[UsesClass(CorsSettings::class)]
#[UsesClass(CallerScope::class)]
class ThemePublicationsTest extends TestCase {
	use SubjectControllerFixture;

	/**
	 * "Sporthal" with three public publications and one draft.
	 *
	 * @return FakeSubjectRegister
	 */
	private function sporthal(): FakeSubjectRegister {
		return new FakeSubjectRegister(
			[$this->theme('t1', ['title' => 'Sporthal', 'slug' => 'sporthal'])],
			[
				['id' => 'p1', 'themes' => ['t1'], 'publicationDate' => '2026-01-01T00:00:00+00:00'],
				['id' => 'p3', 'themes' => ['t1'], 'publicationDate' => '2026-03-01T00:00:00+00:00'],
				['id' => 'p2', 'themes' => ['t1'], 'publicationDate' => '2026-02-01T00:00:00+00:00'],
				['id' => 'draft', 'themes' => ['t1']],
			]
		);
	}

	public function testADraftUnderASubjectIsNotListed(): void {
		$register = new FakeSubjectRegister(
			[$this->theme('t1', ['title' => 'Sporthal'])],
			[['id' => 'draft', 'themes' => ['t1']]]
		);

		$response = $this->themesController($register)->publications('t1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
		$this->assertSame(0, $response->getData()['total']);
	}

	public function testTheSubjectListsItsPublicPublicationsNewestFirst(): void {
		$response = $this->themesController($this->sporthal(), ['_limit' => '2', '_page' => '1'])->publications('t1');

		$data = $response->getData();
		$this->assertSame(['p3', 'p2'], array_column($data['results'], 'id'));
		$this->assertSame(3, $data['total']);
		$this->assertSame(2, $data['pages']);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
	}

	public function testASlugResolves(): void {
		$data = $this->themesController($this->sporthal())->publications('sporthal')->getData();

		$this->assertSame(['p3', 'p2', 'p1'], array_column($data['results'], 'id'));
	}

	public function testAnUnknownSubjectIs404(): void {
		$this->assertSame(404, $this->themesController($this->sporthal())->publications('nope')->getStatus());
		$this->assertSame(404, $this->themesController($this->sporthal())->publications('Not A Slug')->getStatus());
	}

	public function testTheSubjectLinksAndCountsWhatIsFiledUnderIt(): void {
		$data = $this->themesController($this->sporthal())->show('sporthal')->getData();

		$this->assertSame('Sporthal', $data['title']);
		$this->assertSame('/index.php/apps/opencatalogi/api/themes/t1/publications', $data['publicationsUrl']);
		$this->assertSame(3, $data['publicationCount']);
	}
}
