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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;
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
	/**
	 * An ObjectService double that keeps the find and save calls.
	 *
	 * @param array<string, array<string, mixed>> $stored Stored objects by uuid.
	 *
	 * @return object The double.
	 */
	private function objects(array $stored=[]): object {
		return new class($stored) {
			/** @var array<int, array<string, mixed>> */
			public array $finds = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * @param array<string, array<string, mixed>> $stored Stored objects by uuid.
			 */
			public function __construct(private array $stored) {
			}

			public function find(string $id, ObjectFindOptions $options, bool $_rbac, bool $_multitenancy): ?array {
				$this->finds[] = ['id' => $id, 'register' => $options->register, 'schema' => $options->schema, 'rbac' => $_rbac];
				if (isset($this->stored[$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->stored[$id];
			}

			public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy): array {
				$this->saves[] = [
					'object' => $object,
					'register' => $options->target->register,
					'schema' => $options->target->schema,
					'uuid' => $options->uuid,
					'unowned' => $options->actor->unowned,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
				];

				return $object + ['id' => ($options->uuid ?? 'new-1')];
			}
		};
	}

	public function testAProposalIsStoredPendingAsTheSystemInTheConfiguredSchema(): void {
		$objects = $this->objects();
		$store = $this->store(['publication_register' => '7', 'metadata_suggestion_schema' => '30'], $objects);

		$stored = $store->create(
			proposal: ['field' => 'wooCategory', 'value' => 'infocat001', 'valueLabel' => 'Wetten', 'source' => 'rules', 'reason' => 'r'],
			publicationId: 'pub-1'
		);

		$this->assertSame('pending', $stored['status']);
		$this->assertSame('new-1', $stored['id']);
		$this->assertSame(
			[[
				'object' => [
					'publication' => 'pub-1',
					'field' => 'wooCategory',
					'value' => 'infocat001',
					'valueLabel' => 'Wetten',
					'source' => 'rules',
					'reason' => 'r',
					'status' => 'pending',
				],
				'register' => '7',
				'schema' => '30',
				'uuid' => null,
				'unowned' => true,
				'rbac' => false,
				'multitenancy' => false,
			]],
			$objects->saves
		);
	}

	public function testASuggestionIsFoundByUuidAndAnUnknownOneIsNull(): void {
		$objects = $this->objects(['s-1' => ['object' => ['field' => 'wooCategory', 'status' => 'pending'], 'id' => 's-1']]);
		$store = $this->store(['publication_register' => '7', 'metadata_suggestion_schema' => '30'], $objects);

		$this->assertSame(['field' => 'wooCategory', 'status' => 'pending', 'id' => 's-1'], $store->find(id: 's-1'));
		$this->assertNull($store->find(id: 'missing'));
		$this->assertSame(
			[
				['id' => 's-1', 'register' => '7', 'schema' => '30', 'rbac' => false],
				['id' => 'missing', 'register' => '7', 'schema' => '30', 'rbac' => false],
			],
			$objects->finds
		);
	}

	public function testADecisionRecordsTheStatusWhoAndWhenOnTheSameSuggestion(): void {
		$objects = $this->objects();
		$store = $this->store(['publication_register' => '7', 'metadata_suggestion_schema' => '30'], $objects);

		$decided = $store->decide(
			suggestion: ['id' => 's-1', '@self' => ['id' => 's-1'], 'field' => 'wooCategory', 'value' => 'infocat001', 'status' => 'pending'],
			status: MetadataSuggestionStore::STATUS_ACCEPTED,
			decidedBy: 'editor',
			decidedAt: '2026-11-16T12:00:00+00:00'
		);

		$this->assertSame('accepted', $decided['status']);
		$this->assertSame(
			['field' => 'wooCategory', 'value' => 'infocat001', 'status' => 'accepted', 'decidedBy' => 'editor', 'decidedAt' => '2026-11-16T12:00:00+00:00'],
			$objects->saves[0]['object']
		);
		$this->assertSame('s-1', $objects->saves[0]['uuid']);
		$this->assertTrue($objects->saves[0]['unowned']);
	}

	public function testOnlyAcceptedOrRejectedIsADecision(): void {
		$objects = $this->objects();
		$store = $this->store(['publication_register' => '7', 'metadata_suggestion_schema' => '30'], $objects);

		try {
			$store->decide(suggestion: ['id' => 's-1'], status: 'pending', decidedBy: 'editor', decidedAt: 'now');
			$this->fail('A pending status was taken as a decision.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('"pending"', $e->getMessage());
		}

		$this->assertSame([], $objects->saves);
	}
}//end class
