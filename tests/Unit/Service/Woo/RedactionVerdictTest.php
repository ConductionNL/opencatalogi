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
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RedactionVerdict.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\RedactionVerdict
 */
class RedactionVerdictTest extends TestCase {

	/**
	 * An l10n double that fills the placeholders.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
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

		$this->assertSame($empty, (new RedactionVerdict(verification: null))->summary());
		$this->assertSame($empty, (new RedactionVerdict(verification: 'clean'))->summary());
		$this->assertSame($empty, (new RedactionVerdict(verification: ['verdict' => ['clean']]))->summary());
		$this->assertSame($empty, (new RedactionVerdict(verification: ['verdict' => '']))->summary());
		$this->assertSame(
			'OpenRegister could not confirm that the redacted values are gone (verdict: unverifiable). The document stays unpublished.',
			(new RedactionVerdict(verification: null))->refusal(l10n: $this->l10n())
		);
	}//end testAnUnreadableAnswerIsUnverifiable()

	/**
	 * Clean needs the engine's release flag set to exactly true, and a stored
	 * clean summary reads back clean.
	 *
	 * @return void
	 */
	public function testCleanNeedsTheReleaseFlag(): void {
		$this->assertSame('unverifiable', (new RedactionVerdict(verification: ['verdict' => 'clean', 'mayBePublished' => 'yes']))->verdict());

		$clean = new RedactionVerdict(verification: ['verdict' => 'clean', 'mayBePublished' => true]);
		$this->assertTrue($clean->isClean());
		$this->assertNull($clean->refusal(l10n: $this->l10n()));
		$this->assertTrue((new RedactionVerdict(verification: $clean->summary()))->isClean(), 'a stored summary reads the same');

		$leaking = new RedactionVerdict(verification: ['verdict' => 'leaking', 'mayBePublished' => true]);
		$this->assertSame('leaking', $leaking->verdict());
		$this->assertFalse($leaking->summary()['mayBePublished']);
		$this->assertStringContainsString('verdict: leaking', (string)$leaking->refusal(l10n: $this->l10n()), 'a leak without findings names the verdict');
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
		$this->assertSame('', $summary['outputMode']);
		$this->assertSame(['text'], $summary['routesChecked']);
		$this->assertSame([['route' => '', 'count' => 3]], $summary['findings']);
		$this->assertSame(
			'OpenRegister found redacted values still recoverable in the redacted file:  (3). The document stays unpublished.',
			$verdict->refusal(l10n: $this->l10n())
		);
	}//end testOnlyScalarsSurvive()
}//end class
