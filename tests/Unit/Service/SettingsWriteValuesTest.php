<?php

/**
 * Tests for the values SettingsService::updateSettings() accepts, and for its writable keys.
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

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClassConstant;

/**
 * A list or object value is rejected before anything is written; the writable
 * instance keys are an explicit list, each with a default.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
class SettingsWriteValuesTest extends TestCase {

	/**
	 * The keys and values written, in order.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $written = [];

	/**
	 * Payloads with one value that is not a single value.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function rejected(): array {
		return [
			'a list for an instance key' => [['dcat_instance_title' => 'T', 'ooapi_consumers' => ['alice', 'bob']], 'ooapi_consumers'],
			'a map for an object type key' => [['catalog_schema' => ['id' => 4]], 'catalog_schema'],
			'an object' => [['woo_index_registration_url' => new \stdClass()], 'woo_index_registration_url'],
			'a list for the cache time' => [['public_api_cache_seconds' => ['5']], 'public_api_cache_seconds'],
		];

	}//end rejected()

	/**
	 * A list or object value throws, names the key, and nothing of the payload is written.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string $key The key whose value is rejected.
	 *
	 * @return void
	 */
	#[DataProvider('rejected')]
	public function testANonScalarValueIsRejectedBeforeAnyWrite(array $payload, string $key): void {
		try {
			$this->service()->updateSettings($payload);
			$this->fail('A list or object value was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Setting ' . $key . ' takes a single value, not a list or an object', $e->getMessage());
		}

		$this->assertSame([], $this->written);

	}//end testANonScalarValueIsRejectedBeforeAnyWrite()

	/**
	 * The channel sources map stays a map, and unknown keys are never inspected.
	 *
	 * @return void
	 */
	public function testTheChannelSourcesMapAndUnknownKeysAreNotRejected(): void {
		$this->service()->updateSettings(['channel_sources' => ['plooi' => 'src-1'], 'not_a_setting' => ['x']]);

		$this->assertSame([['channel_sources', '{"plooi":"src-1"}']], $this->written);

	}//end testTheChannelSourcesMapAndUnknownKeysAreNotRejected()

	/**
	 * Every writable instance key has a default, and the list is exactly this one:
	 * a default added for display only never becomes writable unnoticed.
	 *
	 * @return void
	 */
	public function testTheWritableInstanceKeysAreAnExplicitListWithDefaults(): void {
		$writable = (new ReflectionClassConstant(SettingsService::class, 'WRITABLE_SETTINGS'))->getValue();
		$defaults = (new ReflectionClassConstant(SettingsService::class, 'SETTING_DEFAULTS'))->getValue();

		$this->assertSame([], array_diff($writable, array_keys($defaults)));
		$this->assertSame(
			[
				'auto_publish_attachments',
				'auto_publish_objects',
				'use_old_style_publishing_view',
				'dcat_instance_title',
				'dcat_publisher_name',
				'dcat_publisher_uri',
				'dcat_default_license',
				'dcat_contact_point',
				'ooapi_consumers',
				'woo_index_registration_status',
				'woo_index_registration_url',
				'woo_index_registration_at',
				'woo_index_registration_answer',
				'channel_sources',
				'public_api_cache_seconds',
			],
			$writable
		);

	}//end testTheWritableInstanceKeysAreAnExplicitListWithDefaults()

	/**
	 * The service around an app config that logs its writes.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$this->written = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[] = [$key, $value];
				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new SettingsService(
			$appConfig,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);

	}//end service()
}//end class
