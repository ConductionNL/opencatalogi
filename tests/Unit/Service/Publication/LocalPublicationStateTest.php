<?php

/**
 * What the public read adds to a publication this instance holds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Service\Publication\LocalPublicationState;
use OCA\OpenCatalogi\Service\Publication\PublicationCommentPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\LocalPublicationState
 */
class LocalPublicationStateTest extends TestCase {

	/**
	 * Without either service the publication is answered as it is.
	 *
	 * @return void
	 */
	public function testWithoutServicesThePublicationIsUnchanged(): void {
		$publication = ['id' => 'pub-1', 'title' => 'Besluit'];

		$this->assertSame($publication, (new LocalPublicationState())->addTo(publication: $publication));
	}

	/**
	 * The withheld list is added first, then the comment period, each to what the other answered.
	 *
	 * @return void
	 */
	public function testTheWithheldListThenTheCommentPeriodAreAdded(): void {
		$withheld = $this->createMock(WithheldFromPublicationInterface::class);
		$withheld->expects($this->once())->method('addForPublication')
			->with(['id' => 'pub-1'])
			->willReturn(['id' => 'pub-1', 'withheld' => ['doc-2']]);
		$periods = $this->createMock(PublicationCommentPeriod::class);
		$periods->expects($this->once())->method('addTo')
			->with(['id' => 'pub-1', 'withheld' => ['doc-2']])
			->willReturn(['id' => 'pub-1', 'withheld' => ['doc-2'], 'commentPeriod' => ['state' => 'open']]);

		$this->assertSame(
			['id' => 'pub-1', 'withheld' => ['doc-2'], 'commentPeriod' => ['state' => 'open']],
			(new LocalPublicationState($withheld, $periods))->addTo(publication: ['id' => 'pub-1'])
		);
	}

	/**
	 * Only the comment period is added when there is no withheld list service.
	 *
	 * @return void
	 */
	public function testOnlyTheCommentPeriodWithoutAWithheldService(): void {
		$periods = $this->createMock(PublicationCommentPeriod::class);
		$periods->method('addTo')->willReturnCallback(
			static fn (array $publication): array => $publication + ['commentPeriod' => null]
		);

		$this->assertSame(
			['id' => 'pub-1', 'commentPeriod' => null],
			(new LocalPublicationState(null, $periods))->addTo(publication: ['id' => 'pub-1'])
		);
	}
}
