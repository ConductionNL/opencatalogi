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
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
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
	/**
	 * A published record that misses fields has each gap recorded as not supplied, its blank values cleared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/design.md#d2-existing-publications
	 */
	public function testAPublishedRecordGetsItsGapsRecordedAsNotSupplied(): void {
		$saved = RequiredToPublish::recordGaps(
			publication: ['title' => 'Raadsstukken', 'status' => 'published', 'summary' => '  ', 'description' => 'Lang']
		);

		$this->assertSame(
			expected: ['summary' => 'published-before-required', 'organization' => 'published-before-required'],
			actual: $saved['@notSupplied']
		);
		$this->assertNull(actual: $saved['summary']);
		$this->assertNull(actual: $saved['organization']);
		$this->assertSame(expected: 'Lang', actual: $saved['description']);
	}//end testAPublishedRecordGetsItsGapsRecordedAsNotSupplied()

	/**
	 * A draft, a record without a state and a complete record are returned unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/design.md#d2-existing-publications
	 */
	public function testOnlyAStateWithTheRuleIsMarked(): void {
		$draft = ['title' => 'Concept', 'status' => 'draft'];
		$none = ['title' => 'Oud', 'status' => ''];
		$complete = ['status' => 'approved', 'summary' => 'Kort', 'description' => 'Lang', 'organization' => 'org-1'];

		$this->assertSame(expected: $draft, actual: RequiredToPublish::recordGaps(publication: $draft));
		$this->assertSame(expected: $none, actual: RequiredToPublish::recordGaps(publication: $none));
		$this->assertSame(expected: $complete, actual: RequiredToPublish::recordGaps(publication: $complete));
	}//end testOnlyAStateWithTheRuleIsMarked()

	/**
	 * An existing record keeps its own entries and reasons; a list record is replaced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/design.md#d2-existing-publications
	 */
	public function testAnExistingRecordKeepsItsEntries(): void {
		$saved = RequiredToPublish::recordGaps(publication: [
			'status'       => 'in_review',
			'summary'      => 'Kort',
			'organization' => 'org-1',
			'@notSupplied' => ['description' => 'unknown-at-source', 'theme' => 'not-applicable'],
		]);
		$this->assertSame(expected: ['description' => 'unknown-at-source', 'theme' => 'not-applicable'], actual: $saved['@notSupplied']);

		$listed = RequiredToPublish::recordGaps(
			publication: ['status' => 'published', 'summary' => 'Kort', 'organization' => 'org-1', '@notSupplied' => ['description']]
		);
		$this->assertSame(expected: ['description' => 'published-before-required'], actual: $listed['@notSupplied']);
	}//end testAnExistingRecordKeepsItsEntries()
}//end class
