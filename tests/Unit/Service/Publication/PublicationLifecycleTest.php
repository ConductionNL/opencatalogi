<?php

/**
 * PublicationLifecycleTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Lifecycle\ReviewNotRequiredGuard;
use OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `publishWithoutReview` is open only while the admin turned review off, and closed otherwise.
 *
 * @covers \OCA\OpenCatalogi\Lifecycle\ReviewNotRequiredGuard
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy
 */
final class PublicationLifecycleTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private ReviewNotRequiredGuard $guard;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->guard = new ReviewNotRequiredGuard(new PublicationReviewPolicy($this->appConfig));

	}//end setUp()

	/**
	 * The setting as stored, read with its default `true`.
	 *
	 * @param string $stored The stored text.
	 *
	 * @return void
	 */
	private function storeSetting(string $stored): void {
		$this->appConfig->method('getValueString')
			->with('opencatalogi', 'publication_review_required', 'true')
			->willReturn($stored);

	}//end storeSetting()

	/**
	 * Review required: the draft cannot skip it, and the refusal names the action.
	 *
	 * @return void
	 */
	public function testPublishWithoutReviewIsRefusedWhenReviewIsRequired(): void {
		$this->storeSetting('true');

		$result = $this->guard->check(['status' => 'draft'], 'publishWithoutReview', 'editor');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('publishWithoutReview', (string)$result->getMessage());
	}//end testPublishWithoutReviewIsRefusedWhenReviewIsRequired()

	/**
	 * Review off: the draft is published directly.
	 *
	 * @return void
	 */
	public function testPublishWithoutReviewIsAllowedWhenReviewIsOff(): void {
		$this->storeSetting('false');

		self::assertTrue($this->guard->check(['status' => 'draft'], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testPublishWithoutReviewIsAllowedWhenReviewIsOff()

	/**
	 * Unset, or anything but the text `false`, keeps review on (fail closed).
	 *
	 * @return void
	 */
	public function testAnyOtherStoredValueKeepsReviewOn(): void {
		$this->storeSetting('no');

		self::assertTrue((new PublicationReviewPolicy($this->appConfig))->isReviewRequired());
		self::assertFalse($this->guard->check([], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testAnyOtherStoredValueKeepsReviewOn()

	/**
	 * A setting that cannot be read refuses rather than opening the shortcut.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingRefuses(): void {
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('config down'));

		self::assertFalse($this->guard->check([], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testAnUnreadableSettingRefuses()
}//end class
