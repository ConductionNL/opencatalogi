<?php

/**
 * Unit tests for the featured subjects of GET /api/themes?featured=true (REQ-SUB-002).
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
 * Unit tests for the featured subjects.
 */
#[CoversClass(ThemesController::class)]
#[UsesClass(SubjectPublications::class)]
#[UsesClass(CorsSettings::class)]
#[UsesClass(CallerScope::class)]
class FeaturedThemesTest extends TestCase {
	use SubjectControllerFixture;

	public function testTheCountOnlyCountsPublicPublications(): void {
		$register = new FakeSubjectRegister(
			[$this->theme('t1', ['title' => 'Parkeren', 'featured' => true, 'featuredOrder' => 1])],
			[
				['id' => 'p1', 'themes' => ['t1'], 'publicationDate' => '2026-01-01T00:00:00+00:00'],
				['id' => 'p2', 'themes' => ['t1'], 'publicationDate' => '2026-02-01T00:00:00+00:00'],
				['id' => 'draft', 'themes' => ['t1']],
				['id' => 'next-week', 'themes' => ['t1'], 'publicationDate' => gmdate('c', time() + 7 * 86400)],
			]
		);

		$data = $this->themesController($register, ['featured' => 'true'])->index()->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame(2, $data['results'][0]['publicationCount']);
	}

	public function testFeaturedSubjectsComeInOrder(): void {
		$register = new FakeSubjectRegister(
			[
				$this->theme('t3', ['title' => 'Zwembad', 'featured' => true, 'featuredOrder' => 2]),
				$this->theme('t2', ['title' => 'Afval', 'featured' => true, 'featuredOrder' => 2]),
				$this->theme('t1', ['title' => 'Parkeren', 'featured' => true, 'featuredOrder' => 1, 'slug' => 'parkeren', 'image' => 'p.png']),
				$this->theme('t4', ['title' => 'Niet uitgelicht', 'featured' => false, 'featuredOrder' => 0]),
			]
		);

		$data = $this->themesController($register, ['featured' => 'true'])->index()->getData();

		$this->assertSame(['t1', 't2', 't3'], array_column($data['results'], 'id'));
		$this->assertSame(
			[
				'id' => 't1',
				'slug' => 'parkeren',
				'title' => 'Parkeren',
				'summary' => null,
				'description' => null,
				'image' => 'p.png',
				'url' => null,
				'isExternal' => null,
				'featuredOrder' => 1,
				'publicationCount' => 0,
			],
			$data['results'][0]
		);
		$this->assertSame(true, $register->searches[0]['query']['featured']);
	}

	public function testWithoutTheFeaturedFilterTheListIsUnchanged(): void {
		$register = new FakeSubjectRegister([$this->theme('t1', ['title' => 'Parkeren'])]);

		$data = $this->themesController($register)->index()->getData();

		$this->assertSame('Parkeren', $data['results'][0]['title']);
		$this->assertArrayNotHasKey('publicationCount', $data['results'][0]);
		$this->assertCount(1, $register->searches);
	}
}
