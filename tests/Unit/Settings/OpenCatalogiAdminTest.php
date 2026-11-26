<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class OpenCatalogiAdminTest extends TestCase {
	private OpenCatalogiAdmin $settings;
	private IConfig $config;
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->settings = new OpenCatalogiAdmin($this->config);
	}

	public function testGetForm(): void {
		$this->config->method('getSystemValue')
			->with('open_catalogi_setting', true)
			->willReturn(true);

		$response = $this->settings->getForm();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * The page gets the version information with it as initial state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-10
	 */
	public function testTheFormHandsTheVersionInfoToThePage(): void {
		$versionInfo = [
			'appName' => 'OpenCatalogi',
			'appVersion' => '2.0.0',
			'configuredVersion' => '2.0.0',
			'versionsMatch' => true,
			'needsUpdate' => false,
		];
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getVersionInfo')->willReturn($versionInfo);
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with(OpenCatalogiAdmin::VERSION_INFO_STATE, $versionInfo);

		$response = (new OpenCatalogiAdmin(config: $this->config, initialState: $initialState, settings: $settings))->getForm();

		$this->assertSame(expected: 'settings/admin', actual: $response->getTemplateName());
	}

	/**
	 * An unreadable version leaves the page to ask for it itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-10
	 */
	public function testAnUnreadableVersionProvidesNothingAndStillRendersThePage(): void {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getVersionInfo')->willThrowException(new \RuntimeException('Failed to get version information'));
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');

		$response = (new OpenCatalogiAdmin(config: $this->config, initialState: $initialState, settings: $settings))->getForm();

		$this->assertSame(expected: 'settings/admin', actual: $response->getTemplateName());
	}

	public function testGetSection(): void {
		$this->assertSame('opencatalogi', $this->settings->getSection());
	}

	public function testGetPriority(): void {
		$this->assertSame(10, $this->settings->getPriority());
	}
}
