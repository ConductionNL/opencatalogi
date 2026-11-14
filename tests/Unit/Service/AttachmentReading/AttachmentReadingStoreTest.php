<?php

/**
 * Tests for the attachment reading store.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace Unit\Service\AttachmentReading;

use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Double of OpenRegister's ObjectService for the reading state.
 */
class ReadingStoreFakeObjects {

	/** @var list<array<string, mixed>> */
	public array $rows = [];

	/** @var list<array<string, mixed>> */
	public array $queries = [];

	/** @var list<array<string, mixed>> */
	public array $saves = [];

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return ['results' => $this->rows];
	}//end searchObjectsPaginated()

	public function saveObject(array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $object;
	}//end saveObject()
}//end class

/**
 * Tests for AttachmentReadingStore.
 *
 * @covers \OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore
 */
class AttachmentReadingStoreTest extends TestCase {

	private ReadingStoreFakeObjects $objects;

	protected function setUp(): void {
		$this->objects = new ReadingStoreFakeObjects();

	}//end setUp()

	/**
	 * The store under test.
	 *
	 * @param bool $installed Whether OpenRegister is installed.
	 * @param string $schema The configured attachmentReading schema.
	 *
	 * @return AttachmentReadingStore
	 */
	private function store(bool $installed = true, string $schema = '31'): AttachmentReadingStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => '3',
				'attachment_reading_schema' => $schema,
				default => $default,
			}
		);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn($installed);
		$lookup->method('objectService')->willReturn($this->objects);

		return new AttachmentReadingStore($config, $lookup);
	}//end store()

	public function testAFirstReadingCreatesTheObjectAsTheSystem(): void {
		$this->store()->record(fileId: 42, publicationId: 'pub-1', state: 'unavailable', reason: 'OCR is not installed');

		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame(['fileId' => 42, 'publication' => 'pub-1', 'state' => 'unavailable', 'reason' => 'OCR is not installed'], array_diff_key($save['object'], ['updatedAt' => true]));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $save['object']['updatedAt']);
		$this->assertSame(['3', '31', null, false, false], [$save['register'], $save['schema'], $save['uuid'], $save['rbac'], $save['multitenancy']]);
		$this->assertSame(['@self' => ['register' => '3', 'schema' => '31'], 'fileId' => 42, '_limit' => 10], $this->objects->queries[0]['query']);
		$this->assertFalse($this->objects->queries[0]['rbac']);
	}//end testAFirstReadingCreatesTheObjectAsTheSystem()

	public function testAReadingAgainUpdatesTheObjectOfThatFileOnly(): void {
		$this->objects->rows = [
			['id' => 'other-file', 'fileId' => 41, 'state' => 'typed'],
			['@self' => ['id' => 'this-file'], 'fileId' => 42, 'state' => 'unavailable'],
		];

		$this->store()->record(fileId: 42, publicationId: 'pub-1', state: 'ocr', reason: '');

		$this->assertSame('this-file', $this->objects->saves[0]['uuid']);
		$this->assertSame('ocr', $this->objects->saves[0]['object']['state']);
	}//end testAReadingAgainUpdatesTheObjectOfThatFileOnly()

	public function testWithoutOpenRegisterTheWriteIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->store(installed: false)->record(fileId: 42, publicationId: 'pub-1', state: 'typed', reason: '');
	}//end testWithoutOpenRegisterTheWriteIsRefused()

	public function testAnUnconfiguredSchemaIsRefusedAndNothingIsWritten(): void {
		try {
			$this->store(schema: '')->record(fileId: 42, publicationId: 'pub-1', state: 'typed', reason: '');
			$this->fail('an unconfigured schema must be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('attachment_reading_schema', $e->getMessage());
		}

		$this->assertSame([], $this->objects->saves);
	}//end testAnUnconfiguredSchemaIsRefusedAndNothingIsWritten()
}//end class
