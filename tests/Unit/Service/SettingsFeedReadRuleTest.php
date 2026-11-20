<?php

/**
 * Saving the OOAPI consumer list or an OOAPI schema writes the feed read rule again.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\FeedReadRule;
use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The settings write hands the OOAPI read rule to FeedReadRule when it is affected, and only then.
 */
#[CoversClass(SettingsService::class)]
class SettingsFeedReadRuleTest extends TestCase {

	private ContainerInterface|MockObject $container;
	private LoggerInterface|MockObject $logger;
	private FeedReadRule|MockObject $rule;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rule = $this->createMock(FeedReadRule::class);
	}

	private function service(): SettingsService {
		$values = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$values): bool {
				$values[$key] = $value;
				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values): string {
				return ($values[$key] ?? $default);
			}
		);

		return new SettingsService(
			$appConfig,
			$this->container,
			$this->createMock(IAppManager::class),
			$this->logger,
			$this->createMock(RegisterSchemaLinkService::class)
		);
	}

	public function testSavingTheConsumerListWritesTheRule(): void {
		$this->container->expects($this->once())->method('get')->with(FeedReadRule::class)->willReturn($this->rule);
		$this->rule->expects($this->once())->method('apply')->willReturn(3);

		$this->assertSame(['ooapi_consumers' => 'alice,bob'], $this->service()->updateSettings(['ooapi_consumers' => 'alice,bob']));
	}

	public function testSavingAnOoapiSchemaWritesTheRule(): void {
		$this->container->expects($this->once())->method('get')->with(FeedReadRule::class)->willReturn($this->rule);
		$this->rule->expects($this->once())->method('apply');

		$this->service()->updateSettings(['ooapi_offerings_schema' => '14']);
	}

	public function testAnUnrelatedSettingLeavesTheRuleAlone(): void {
		$this->container->expects($this->never())->method('get');

		$this->service()->updateSettings(['dcat_instance_title' => 'Catalogi']);
	}

	public function testAFailingRuleIsLoggedAndTheSettingStaysSaved(): void {
		$this->container->method('get')->willReturn($this->rule);
		$this->rule->method('apply')->willThrowException(new RuntimeException('no group'));
		$this->logger->expects($this->once())->method('warning')->with('[SettingsService] the OOAPI read rule was not written: no group');

		$this->assertSame(['ooapi_consumers' => 'alice'], $this->service()->updateSettings(['ooapi_consumers' => 'alice']));
	}
}
