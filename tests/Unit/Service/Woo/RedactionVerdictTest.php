<?php

/**
 * Unit tests for RedactionVerdict.
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
 * @spec openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#requirement-the-woo-pipeline-trusts-only-the-engines-verdict-and-its-human-gate-req-wrt-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\RedactionVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RedactionVerdict.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\RedactionVerdict
 */
class RedactionVerdictTest extends TestCase {

	/**
	 * Anything that is not a list with a verdict reads as unverifiable.
	 *
	 * @return void
	 */
	public function testAnUnreadableAnswerIsUnverifiable(): void {
		$empty = ['verdict' => 'unverifiable', 'outputMode' => '', 'routesChecked' => [], 'findings' => []];

		$this->assertSame($empty, RedactionVerdict::summary(verification: null));
		$this->assertSame($empty, RedactionVerdict::summary(verification: 'clean'));
		$this->assertSame($empty, RedactionVerdict::summary(verification: ['verdict' => ['clean']]));
		$this->assertSame($empty, RedactionVerdict::summary(verification: ['verdict' => '']));
	}//end testAnUnreadableAnswerIsUnverifiable()

	/**
	 * Clean needs the engine's release flag set to exactly true.
	 *
	 * @return void
	 */
	public function testCleanNeedsTheReleaseFlag(): void {
		$this->assertSame('unverifiable', RedactionVerdict::summary(verification: ['verdict' => 'clean', 'mayBePublished' => 'yes'])['verdict']);
		$this->assertSame('clean', RedactionVerdict::summary(verification: ['verdict' => 'clean', 'mayBePublished' => true])['verdict']);
		$this->assertSame('leaking', RedactionVerdict::summary(verification: ['verdict' => 'leaking', 'mayBePublished' => true])['verdict']);
	}//end testCleanNeedsTheReleaseFlag()

	/**
	 * Only scalars are kept as text; a non-scalar output mode or route is dropped.
	 *
	 * @return void
	 */
	public function testOnlyScalarsSurvive(): void {
		$summary = RedactionVerdict::summary(
			verification: [
				'verdict' => 'leaking',
				'outputMode' => ['pdf'],
				'routesChecked' => 'text',
				'findings' => [['route' => ['text'], 'count' => '3']],
			]
		);

		$this->assertSame('', $summary['outputMode']);
		$this->assertSame(['text'], $summary['routesChecked']);
		$this->assertSame([['route' => '', 'count' => 3]], $summary['findings']);
		$this->assertSame(' (3)', RedactionVerdict::routeCounts(summary: $summary));
	}//end testOnlyScalarsSurvive()

	/**
	 * Reading a stored summary back: the verdict, and whether it is clean.
	 *
	 * @return void
	 */
	public function testAStoredSummaryIsReadBack(): void {
		$this->assertTrue(RedactionVerdict::isClean(summary: ['verdict' => 'clean']));
		$this->assertFalse(RedactionVerdict::isClean(summary: ['verdict' => 'leaking']));
		$this->assertFalse(RedactionVerdict::isClean(summary: null));
		$this->assertSame('clean', RedactionVerdict::verdictOf(summary: ['verdict' => 'clean']));
		$this->assertSame('', RedactionVerdict::verdictOf(summary: 'clean'));
		$this->assertSame('', RedactionVerdict::verdictOf(summary: []));
	}//end testAStoredSummaryIsReadBack()
}//end class
