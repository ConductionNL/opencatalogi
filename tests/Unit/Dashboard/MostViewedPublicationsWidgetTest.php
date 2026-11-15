<?php

/**
 * Tests for MostViewedPublicationsWidget: its identity on the dashboard and the assets it loads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */

declare(strict_types=1);

namespace Unit\Dashboard;

use OCA\OpenCatalogi\Dashboard\MostViewedPublicationsWidget;
use OCP\IL10N;
use OCP\Util;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MostViewedPublicationsWidget::class)]
class MostViewedPublicationsWidgetTest extends TestCase {

	private function widget(?IL10N $l10n = null): MostViewedPublicationsWidget {
		if ($l10n === null) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(fn (string $text): string => 'translated: ' . $text);
		}

		return new MostViewedPublicationsWidget($l10n);
	}

	/**
	 * The scripts Util has queued for opencatalogi so far.
	 *
	 * @return list<string>
	 */
	private function queuedScripts(): array {
		$scripts = (new ReflectionProperty(Util::class, 'scripts'))->getValue();

		return $scripts['opencatalogi'] ?? [];
	}

	public function testItsIdentityOnTheDashboard(): void {
		$widget = $this->widget();

		self::assertSame('opencatalogi_most_viewed_publications_widget', $widget->getId());
		self::assertSame(11, $widget->getOrder());
		self::assertSame('icon-catalogi-widget', $widget->getIconClass());
		self::assertNull($widget->getUrl());
	}

	public function testTheTitleIsTranslatedFromItsSourceString(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::once())->method('t')->with('Most viewed publications')->willReturn('vertaald');

		self::assertSame('vertaald', $this->widget(l10n: $l10n)->getTitle());
	}

	public function testLoadQueuesTheSharedChunksBeforeItsOwnBundle(): void {
		$before = count($this->queuedScripts());

		$this->widget()->load();

		$added = array_values(array_filter(
			array_slice($this->queuedScripts(), $before),
			static fn (string $path): bool => str_contains($path, '/js/')
		));
		self::assertSame(
			[
				'opencatalogi/js/opencatalogi-shared-vendor',
				'opencatalogi/js/opencatalogi-shared-nc-vue',
				'opencatalogi/js/opencatalogi-mostViewedPublicationsWidget',
			],
			$added
		);
	}
}
