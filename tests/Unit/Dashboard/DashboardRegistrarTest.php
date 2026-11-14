<?php

/**
 * Tests for DashboardRegistrar: which widgets the app puts on the dashboard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Dashboard;

use OCA\OpenCatalogi\Dashboard\CatalogWidget;
use OCA\OpenCatalogi\Dashboard\DashboardRegistrar;
use OCA\OpenCatalogi\Dashboard\MostViewedPublicationsWidget;
use OCA\OpenCatalogi\Dashboard\RetentionWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedAttachmentsWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedPublicationsWidget;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DashboardRegistrar::class)]
class DashboardRegistrarTest extends TestCase {

	public function testEveryWidgetIsRegisteredOnceInOrder(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->expects(self::exactly(5))
			->method('registerDashboardWidget')
			->willReturnCallback(
				static function (string $widgetClass) use (&$registered): void {
					$registered[] = $widgetClass;
				}
			);
		$context->expects(self::never())->method('registerService');

		(new DashboardRegistrar())->register($context);

		self::assertSame(
			[
				CatalogWidget::class,
				UnpublishedPublicationsWidget::class,
				UnpublishedAttachmentsWidget::class,
				MostViewedPublicationsWidget::class,
				RetentionWidget::class,
			],
			$registered
		);
	}
}
