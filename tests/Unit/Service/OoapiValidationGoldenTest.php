<?php

/**
 * Golden test for OoapiService::validateCatalog().
 *
 * The course pages are given; the violations are recorded once and replayed.
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

use OCA\OpenCatalogi\Contract\OoapiPage;
use OCA\OpenCatalogi\Contract\OoapiScope;
use OCA\OpenCatalogi\Service\OoapiService;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the OOAPI catalog validation.
 *
 * @covers \OCA\OpenCatalogi\Service\OoapiService
 * @uses   \OCA\OpenCatalogi\Contract\OoapiPage
 * @uses   \OCA\OpenCatalogi\Contract\OoapiScope
 */
class OoapiValidationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/ooapi-validation-golden.json';

	/**
	 * Two pages of courses, every kind of gap, match the recording, and the pages asked are recorded.
	 *
	 * @return void
	 */
	public function testViolationsMatchTheRecording(): void {
		$pages = [
			1 => ['items' => [
				['courseId' => 'c1', 'code' => 'A', 'name' => 'Een'],
				['courseId' => 'c2', 'code' => '', 'name' => 'Leeg'],
				['courseId' => 'c3', 'name' => null],
				['code' => 'X', 'name' => 'Geen id'],
				['courseId' => '', 'code' => '', 'name' => ''],
			], 'hasNext' => true],
			2 => ['items' => [['courseId' => 'c4', 'code' => 0, 'name' => 'Nul']], 'hasNext' => false],
		];
		$asked = [];
		$service = $this->getMockBuilder(OoapiService::class)->disableOriginalConstructor()->onlyMethods(['listCourses'])->getMock();
		$service->method('listCourses')->willReturnCallback(
			static function (OoapiScope $scope, OoapiPage $page) use ($pages, &$asked): array {
				$asked[] = [$page->number, $page->size];
				return $pages[$page->number];
			}
		);

		$scope = new OoapiScope(catalog: ['id' => 'cat'], register: '1', schema: '11');
		$actual = json_decode((string)json_encode(['violations' => $service->validateCatalog($scope), 'asked' => $asked]), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);

	}//end testViolationsMatchTheRecording()
}//end class
