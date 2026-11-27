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

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\RedactionVerdict;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RedactionVerdict.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\RedactionVerdict
 */
class RedactionVerdictTest extends TestCase {

	/**
	 * An l10n double that fills the placeholders.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		return $l10n;
	}//end l10n()

	/**
	 * Anything that is not a list with a verdict reads as unverifiable.
	 *
	 * @return void
	 */
	public function testAnUnreadableAnswerIsUnverifiable(): void {
		$empty = ['verdict' => 'unverifiable', 'mayBePublished' => false, 'outputMode' => '', 'routesChecked' => [], 'findings' => []];

		$this->assertSame(expected: $empty, actual: (new RedactionVerdict(verification: null))->summary());
		$this->assertSame(expected: $empty, actual: (new RedactionVerdict(verification: 'clean'))->summary());
		$this->assertSame(expected: $empty, actual: (new RedactionVerdict(verification: ['verdict' => ['clean']]))->summary());
		$this->assertSame(expected: $empty, actual: (new RedactionVerdict(verification: ['verdict' => '']))->summary());
		$this->assertSame(
			expected: 'OpenRegister could not confirm that the redacted values are gone (verdict: unverifiable). The document stays unpublished.',
			actual: (new RedactionVerdict(verification: null))->refusal(l10n: $this->l10n())
		);
	}//end testAnUnreadableAnswerIsUnverifiable()

	/**
	 * Clean needs the engine's release flag set to exactly true, and a stored
	 * clean summary reads back clean.
	 *
	 * @return void
	 */
	public function testCleanNeedsTheReleaseFlag(): void {
		$flagNotTrue = new RedactionVerdict(verification: ['verdict' => 'clean', 'mayBePublished' => 'yes']);
		$this->assertSame(expected: 'unverifiable', actual: $flagNotTrue->verdict());

		$clean = new RedactionVerdict(verification: ['verdict' => 'clean', 'mayBePublished' => true]);
		$this->assertTrue(condition: $clean->isClean());
		$this->assertNull(actual: $clean->refusal(l10n: $this->l10n()));
		$this->assertTrue(condition: (new RedactionVerdict(verification: $clean->summary()))->isClean(), message: 'a stored summary reads the same');

		$leaking = new RedactionVerdict(verification: ['verdict' => 'leaking', 'mayBePublished' => true]);
		$this->assertSame(expected: 'leaking', actual: $leaking->verdict());
		$this->assertFalse(condition: $leaking->summary()['mayBePublished']);
		$this->assertStringContainsString(
			needle: 'verdict: leaking',
			haystack: (string)$leaking->refusal(l10n: $this->l10n()),
			message: 'a leak without findings names the verdict'
		);
	}//end testCleanNeedsTheReleaseFlag()

	/**
	 * Only scalars are kept as text; a non-scalar output mode or route is dropped.
	 *
	 * @return void
	 */
	public function testOnlyScalarsSurvive(): void {
		$verdict = new RedactionVerdict(
			verification: [
				'verdict' => 'leaking',
				'outputMode' => ['pdf'],
				'routesChecked' => 'text',
				'findings' => [['route' => ['text'], 'count' => '3'], 'Jan Jansen'],
			]
		);

		$summary = $verdict->summary();
		$this->assertSame(expected: '', actual: $summary['outputMode']);
		$this->assertSame(expected: ['text'], actual: $summary['routesChecked']);
		$this->assertSame(expected: [['route' => '', 'count' => 3]], actual: $summary['findings']);
		$this->assertSame(
			expected: 'OpenRegister found redacted values still recoverable in the redacted file:  (3). The document stays unpublished.',
			actual: $verdict->refusal(l10n: $this->l10n())
		);
	}//end testOnlyScalarsSurvive()
}//end class
