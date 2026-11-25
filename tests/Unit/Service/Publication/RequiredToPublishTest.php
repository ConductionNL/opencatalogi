<?php

/**
 * Unit tests for RequiredToPublish: which of summary, description and organisation a record leaves empty.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
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
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\RequiredToPublish;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\RequiredToPublish
 */
class RequiredToPublishTest extends TestCase {

	/**
	 * A complete record misses nothing.
	 *
	 * @return void
	 */
	public function testACompleteRecordMissesNothing(): void {
		$this->assertSame(
			[],
			RequiredToPublish::missing(publication: ['summary' => 'Kort', 'description' => 'Lang', 'organization' => 'org-1'])
		);
	}//end testACompleteRecordMissesNothing()

	/**
	 * A title-only draft misses all three, in the order a message names them.
	 *
	 * @return void
	 */
	public function testATitleOnlyRecordMissesAllThreeInOrder(): void {
		$this->assertSame(['summary', 'description', 'organization'], RequiredToPublish::missing(publication: ['title' => 'Raadsstukken']));
	}//end testATitleOnlyRecordMissesAllThreeInOrder()

	/**
	 * Blank strings, null and an empty object count as empty; a number or a filled object does not.
	 *
	 * @return void
	 */
	public function testBlankNullAndEmptyCountAsMissing(): void {
		$this->assertSame(
			['summary', 'description', 'organization'],
			RequiredToPublish::missing(publication: ['summary' => '  ', 'description' => null, 'organization' => []])
		);
		$this->assertSame(
			['description'],
			RequiredToPublish::missing(publication: ['summary' => 0, 'description' => '', 'organization' => ['id' => 'org-1']])
		);
	}//end testBlankNullAndEmptyCountAsMissing()
}//end class
