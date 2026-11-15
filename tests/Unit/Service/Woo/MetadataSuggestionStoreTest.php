<?php

/**
 * Unit tests for MetadataSuggestionStore.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\MetadataSuggestionStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Suggestions are read per publication from the configured schema, and an unset key is named.
 */
#[CoversClass(MetadataSuggestionStore::class)]
final class MetadataSuggestionStoreTest extends TestCase {

	/**
	 * The store over the given settings and an ObjectService double.
	 *
	 * @param array<string, string> $settings The app settings.
	 * @param object|null $objects The ObjectService double.
	 *
	 * @return MetadataSuggestionStore
	 */
	private function store(array $settings, ?object $objects=null): MetadataSuggestionStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $settings[$key] ?? ''
		);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn(true);
		$lookup->method('objectService')->willReturn($objects ?? new \stdClass());

		return new MetadataSuggestionStore($config, $lookup);
	}//end store()

	public function testSuggestionsAreReadForOnePublicationFromTheConfiguredSchema(): void {
		$objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			public function searchObjectsPaginated(array $query, bool $_rbac, bool $_multitenancy): array {
				$this->queries[] = $query;
				return ['results' => [['field' => 'wooCategory', 'value' => 'c_1']]];
			}
		};
		$store = $this->store(['publication_register' => '7', 'metadata_suggestion_schema' => ' 30 '], $objects);

		$this->assertTrue($store->isConfigured());
		$this->assertSame('30', $store->schemaId());
		$this->assertSame([['field' => 'wooCategory', 'value' => 'c_1']], $store->forPublication(publicationId: 'pub-1'));
		$this->assertSame(
			[['@self' => ['register' => '7', 'schema' => '30'], 'publication' => 'pub-1', '_limit' => 100]],
			$objects->queries
		);
	}//end testSuggestionsAreReadForOnePublicationFromTheConfiguredSchema()

	public function testAnUnsetSchemaIsNamed(): void {
		$store = $this->store(['publication_register' => '7']);

		$this->assertFalse($store->isConfigured());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('metadata_suggestion_schema');

		$store->create(proposal: ['field' => 'wooCategory'], publicationId: 'pub-1');
	}//end testAnUnsetSchemaIsNamed()
}//end class
