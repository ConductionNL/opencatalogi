<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\InitializeSettings;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The settings repair step is registered where Nextcloud runs it.
 *
 * Nextcloud registers an app's repair steps in `appinfo/info.xml`, not through
 * the bootstrap context (IRegistrationContext has no repair-step method). A
 * fresh install runs only `<install>` and an upgrade only `<post-migration>`,
 * so the step must be in both, and first in each: the steps after it edit the
 * register and schemas it imports.
 *
 * @spec openspec/changes/admin-settings/specs/admin-settings.md#req-set-011-repair-step-for-installupgrade
 */
class InitializeSettingsRegistrationTest extends TestCase {
	/**
	 * The parsed `<repair-steps>` block of appinfo/info.xml.
	 *
	 * @return SimpleXMLElement The block.
	 */
	private function repairSteps(): SimpleXMLElement {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertInstanceOf(expected: SimpleXMLElement::class, actual: $info);
		$this->assertTrue(condition: isset($info->{'repair-steps'}));

		return $info->{'repair-steps'};
	}

	/**
	 * The step classes of one phase, in order.
	 *
	 * @param string $phase The phase element, `install` or `post-migration`.
	 *
	 * @return array<int, string> The class names.
	 */
	private function stepsOf(string $phase): array {
		$steps = [];
		foreach ($this->repairSteps()->{$phase}->step as $step) {
			$steps[] = trim((string)$step);
		}

		return $steps;
	}

	/**
	 * An upgrade runs the step before every other post-migration step.
	 *
	 * @return void
	 */
	public function testTheStepIsTheFirstPostMigrationStep(): void {
		$this->assertSame(expected: InitializeSettings::class, actual: ($this->stepsOf(phase: 'post-migration')[0] ?? null));
	}

	/**
	 * A fresh install, which runs no migration steps, runs the step first.
	 *
	 * @return void
	 */
	public function testTheStepIsTheFirstInstallStep(): void {
		$this->assertSame(expected: InitializeSettings::class, actual: ($this->stepsOf(phase: 'install')[0] ?? null));
	}

	/**
	 * The registered class is one Nextcloud can run as a repair step.
	 *
	 * @return void
	 */
	public function testTheRegisteredClassIsARepairStep(): void {
		$this->assertTrue(condition: is_subclass_of(InitializeSettings::class, IRepairStep::class));
	}
}
