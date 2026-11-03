<?php

/**
 * Golden test for SettingsService::updateSettings() and updatePublishingOptions().
 *
 * Every case writes one payload through an app config double and compares the
 * answer and every stored key and value to the recording in
 * fixtures/settings-write-golden.json. The allowlists decide which keys a caller
 * may store, so the record lists each write.
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

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the settings write allowlists.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
class SettingsWriteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/settings-write-golden.json';

	/**
	 * The keys and values written, in order.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $written = [];

	/**
	 * The recorded cases: the method and its payload.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2?: string}>
	 */
	public static function cases(): array {
		$every = [];
		foreach (['catalog', 'listing', 'organization', 'theme', 'glossary', 'usageCounter', 'ooapi_courses', 'ooapi_programs', 'ooapi_offerings', 'publication', 'document'] as $type) {
			foreach (['source', 'schema', 'register'] as $suffix) {
				$every["{$type}_{$suffix}"] = $type . '-' . $suffix;
			}
		}

		$instance = [
			'auto_publish_attachments' => 'true', 'auto_publish_objects' => 'false', 'use_old_style_publishing_view' => 'x',
			'dcat_instance_title' => 'T', 'dcat_publisher_name' => 'P', 'dcat_publisher_uri' => 'https://p', 'dcat_default_license' => 'L', 'dcat_contact_point' => 'C',
			'ooapi_consumers' => 'alice,bob',
			'woo_index_registration_status' => 'registered', 'woo_index_registration_url' => 'https://u', 'woo_index_registration_at' => '2026', 'woo_index_registration_answer' => 'a',
			'public_api_cache_seconds' => '120',
		];

		// Every config key the app reads or writes, plus near misses: a key added to an
		// allowlist shows up in the written set of these two cases.
		$known = json_decode((string)file_get_contents(__DIR__ . '/fixtures/settings-known-keys.json'), true);
		$knownValues = [];
		foreach ($known as $index => $key) {
			$knownValues[$key] = 'v' . $index;
		}

		return [
			'every known config key' => ['updateSettings', $knownValues],
			'every known config key as a publishing option' => ['updatePublishingOptions', array_fill_keys($known, true)],
			'every object type key' => ['updateSettings', $every],
			'every instance key' => ['updateSettings', $instance],
			'unknown and injected keys' => ['updateSettings', ['evil' => '1', 'installed_version' => '9', 'catalog_register' => '3', 'enabled' => 'no']],
			'empty payload' => ['updateSettings', []],
			'cache negative' => ['updateSettings', ['public_api_cache_seconds' => -5]],
			'cache above an hour' => ['updateSettings', ['public_api_cache_seconds' => 99999]],
			'cache as a list' => ['updateSettings', ['public_api_cache_seconds' => ['5']]],
			'a write the config drops' => ['updateSettings', ['dcat_instance_title' => 'T', 'catalog_schema' => '4'], 'drops'],
			'a publishing write the config drops' => ['updatePublishingOptions', ['auto_publish_objects' => true], 'drops'],
			'cache not a number' => ['updateSettings', ['public_api_cache_seconds' => 'soon']],
			'cache as float text' => ['updateSettings', ['public_api_cache_seconds' => '30.7']],
			'channel sources map' => ['updateSettings', ['channel_sources' => ['plooi' => 'src-1', 'x' => 2]]],
			'channel sources text' => ['updateSettings', ['channel_sources' => '{"plooi":"src-2"}']],
			'channel sources rubbish' => ['updateSettings', ['channel_sources' => 'nope']],
			'settings with a failing config' => ['updateSettings', ['catalog_schema' => '1'], 'throws'],
			'publishing all true' => ['updatePublishingOptions', ['auto_publish_attachments' => true, 'auto_publish_objects' => 'true', 'use_old_style_publishing_view' => true]],
			'publishing other truthy values' => ['updatePublishingOptions', ['auto_publish_attachments' => 1, 'auto_publish_objects' => 'yes', 'use_old_style_publishing_view' => 'TRUE']],
			'publishing false and null' => ['updatePublishingOptions', ['auto_publish_attachments' => false, 'auto_publish_objects' => null, 'use_old_style_publishing_view' => 'false']],
			'publishing unknown keys' => ['updatePublishingOptions', ['dcat_instance_title' => 'x', 'auto_publish' => true]],
			'publishing with a failing config' => ['updatePublishingOptions', ['auto_publish_objects' => true], 'throws'],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string $method The method.
	 * @param array<string, mixed> $payload The payload.
	 * @param string $config 'throws' for a failing app config.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testWritesMatchTheRecording(string $method, array $payload, string $config = 'ok'): void {
		$name = $this->dataName();
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored, $config): bool {
				if ($config === 'throws') {
					throw new \RuntimeException('config down');
				}

				$this->written[] = [$app, $key, $value];
				if ($config !== 'drops') {
					$stored[$key] = $value;
				}

				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);

		$service = new SettingsService(
			$appConfig,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);

		try {
			$answer = $service->{$method}($payload);
		} catch (\Throwable $e) {
			$answer = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['answer' => $answer, 'written' => $this->written];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testWritesMatchTheRecording()
}//end class
