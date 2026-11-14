<?php

/**
 * The delegated-admin half of the admin settings page: which app-config keys a
 * partial admin may write, the form's parameters, and the section label.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\IConfig;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Settings\OpenCatalogiAdmin
 */
class OpenCatalogiAdminDelegationTest extends TestCase {

	/**
	 * The keys the settings section is meant to let a delegated admin write.
	 *
	 * @var string[]
	 */
	private const WRITABLE_KEYS = [
		'catalog_register',
		'catalog_schema',
		'listing_register',
		'listing_schema',
		'auto_publish_attachments',
		'auto_publish_objects',
		'use_old_style_publishing_view',
		'dcat_publisher_name',
		'dcat_publisher_uri',
		'dcat_default_license',
		'dcat_contact_point',
		'dcat_instance_title',
		'woo_index_registration_status',
		'woo_index_registration_url',
		'woo_index_registration_at',
		'woo_readiness_report',
	];

	/**
	 * The one pattern the section declares for its own app.
	 *
	 * @return string The pattern.
	 */
	private function pattern(): string {
		$authorized = (new OpenCatalogiAdmin($this->createMock(IConfig::class)))->getAuthorizedAppConfig();

		$this->assertSame(['opencatalogi'], array_keys($authorized));
		$this->assertCount(1, $authorized['opencatalogi']);

		return $authorized['opencatalogi'][0];
	}//end pattern()

	public function testItIsADelegatedSettingsPage(): void {
		$this->assertInstanceOf(IDelegatedSettings::class, new OpenCatalogiAdmin($this->createMock(IConfig::class)));
	}//end testItIsADelegatedSettingsPage()

	public function testEveryManagedKeyIsWritable(): void {
		$pattern = $this->pattern();

		foreach (self::WRITABLE_KEYS as $key) {
			$this->assertSame(1, preg_match($pattern, $key), $key . ' should be writable');
		}
	}//end testEveryManagedKeyIsWritable()

	public function testOtherKeysAreNotWritable(): void {
		$pattern = $this->pattern();

		foreach (['', 'installed_version', 'enabled', 'types', 'catalog_register_extra', 'my_catalog_schema', 'dcat_', 'woo_index_registration', 'CATALOG_REGISTER'] as $key) {
			$this->assertSame(0, preg_match($pattern, $key), $key . ' should not be writable');
		}
	}//end testOtherKeysAreNotWritable()

	public function testThePatternIsAnchoredOnBothEnds(): void {
		$pattern = $this->pattern();

		$this->assertSame(0, preg_match($pattern, "catalog_register\nadmin"));
		$this->assertSame(0, preg_match($pattern, 'xcatalog_register'));
		$this->assertSame(0, preg_match($pattern, 'woo_readiness_report2'));
	}//end testThePatternIsAnchoredOnBothEnds()

	public function testTheNameIsLeftToTheSection(): void {
		$this->assertNull((new OpenCatalogiAdmin($this->createMock(IConfig::class)))->getName());
	}//end testTheNameIsLeftToTheSection()

	public function testTheFormRendersTheAdminTemplateWithTheSystemSetting(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getSystemValue')
			->with('open_catalogi_setting', true)
			->willReturn(false);

		$response = (new OpenCatalogiAdmin($config))->getForm();

		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame('admin', $response->getRenderAs());
		$this->assertSame(['mySetting' => false], $response->getParams());
	}//end testTheFormRendersTheAdminTemplateWithTheSystemSetting()
}//end class
