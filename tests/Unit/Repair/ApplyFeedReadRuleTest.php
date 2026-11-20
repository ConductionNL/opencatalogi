<?php

/**
 * Unit tests for the ApplyFeedReadRule repair step.
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
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\ApplyFeedReadRule;
use OCA\OpenCatalogi\Service\FeedReadRule;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The step writes the rule only when OpenRegister is enabled, and reports how many schemas changed.
 */
#[CoversClass(ApplyFeedReadRule::class)]
final class ApplyFeedReadRuleTest extends TestCase {

	public function testItWritesTheRuleAndReportsTheCount(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('openregister')->willReturn(true);
		$rule = $this->createMock(FeedReadRule::class);
		$rule->expects($this->once())->method('apply')->willReturn(3);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('OOAPI feed read rule written onto 3 schema(s)');

		$step = new ApplyFeedReadRule($apps, $rule);
		$this->assertSame('Write who may read the OOAPI feeds onto their schemas', $step->getName());
		$step->run($output);
	}

	public function testItDoesNothingWithoutOpenRegister(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(false);
		$rule = $this->createMock(FeedReadRule::class);
		$rule->expects($this->never())->method('apply');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('OpenRegister is not enabled; the OOAPI feed read rule was not written');

		(new ApplyFeedReadRule($apps, $rule))->run($output);
	}
}
