<?php

/**
 * Unit tests for PublicationVersionService: a correction to a public
 * publication keeps the old public projection and copies of its public files.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\Publication\PublicationVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationVersionService
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationStateService
 */
final class PublicationVersionServiceTest extends TestCase {

	private const PUBLICATION = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * The objects the double ObjectService saved, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The versions the double search answers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The shared files the double file service answers.
	 *
	 * @var list<object>
	 */
	private array $files = [];

	/**
	 * The copies the double file service made, as [sourceFileId, targetUuid].
	 *
	 * @var list<array{int, string}>
	 */
	private array $copies = [];

	/**
	 * The service over an anonymous view that leaves out `internalNote`.
	 *
	 * @param array<string, string> $config The register configuration answered.
	 *
	 * @return PublicationVersionService
	 */
	private function service(array $config = ['register' => '19', 'schema' => '42']): PublicationVersionService {
		$view = $this->createMock(originalClassName: PublishedRecordViewInterface::class);
		$view->method('view')->willReturnCallback(
			static function (array $record): array {
				unset($record['internalNote']);
				return $record;
			}
		);

		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('registerConfiguration')->willReturn($config);
		$lookup->method('installedObjectService')->willReturn($this->objectService());
		$lookup->method('installedService')->willReturn($this->fileService());

		return new PublicationVersionService(states: new PublicationStateService(), view: $view, openRegister: $lookup);
	}//end service()

	/**
	 * An ObjectService double that records saves and answers the stored versions.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;
		return new class ($test) {
			/**
			 * @param PublicationVersionServiceTest $test The test holding the state.
			 */
			public function __construct(private readonly PublicationVersionServiceTest $test) {
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param mixed $options The save options.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(array $object, mixed $options, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				return $this->test->recordSave(object: $object, uuid: $options->uuid);
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->storedVersions();
			}
		};
	}//end objectService()

	/**
	 * A FileService double that answers the shared files and records copies.
	 *
	 * @return object
	 */
	private function fileService(): object {
		$test = $this;
		return new class ($test) {
			/**
			 * @param PublicationVersionServiceTest $test The test holding the state.
			 */
			public function __construct(private readonly PublicationVersionServiceTest $test) {
			}

			/**
			 * @param ObjectEntity $object The object.
			 * @param bool $sharedFilesOnly Whether only shared files are listed.
			 *
			 * @return list<object>
			 */
			public function getFiles(ObjectEntity $object, bool $sharedFilesOnly = false): array {
				return $this->test->sharedFiles(object: $object, sharedOnly: $sharedFilesOnly);
			}

			/**
			 * @param ObjectEntity $sourceObject The source.
			 * @param int $fileId The file.
			 * @param ObjectEntity $targetObject The target.
			 *
			 * @return object The copy.
			 */
			public function copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): object {
				return $this->test->recordCopy(fileId: $fileId, target: (string)$targetObject->getUuid());
			}
		};
	}//end fileService()

	/**
	 * Record a save and answer it as a stored entity.
	 *
	 * @param array<string, mixed> $object The saved object.
	 * @param string|null $uuid The uuid saved under.
	 *
	 * @return ObjectEntity
	 */
	public function recordSave(array $object, ?string $uuid): ObjectEntity {
		$this->saved[] = $object;
		$entity = new ObjectEntity();
		$entity->setUuid($uuid ?? 'v0000000-0000-4000-8000-00000000000' . count($this->saved));
		$entity->setObject($object);
		return $entity;
	}//end recordSave()

	/**
	 * The stored versions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function storedVersions(): array {
		return $this->stored;
	}//end storedVersions()

	/**
	 * The shared files, only when asked for shared files.
	 *
	 * @param ObjectEntity $object The object.
	 * @param bool $sharedOnly Whether only shared files are listed.
	 *
	 * @return list<object>
	 */
	public function sharedFiles(ObjectEntity $object, bool $sharedOnly): array {
		self::assertTrue(condition: $sharedOnly, message: 'Only public (shared) files belong in a version.');
		return $this->files;
	}//end sharedFiles()

	/**
	 * Record a copy and answer the new file.
	 *
	 * @param int $fileId The copied file.
	 * @param string $target The target uuid.
	 *
	 * @return object
	 */
	public function recordCopy(int $fileId, string $target): object {
		$this->copies[] = [$fileId, $target];
		return $this->file(id: (1000 + $fileId), name: 'copy', mime: 'application/pdf');
	}//end recordCopy()

	/**
	 * A file node double.
	 *
	 * @param int $id The file id.
	 * @param string $name The name.
	 * @param string $mime The mimetype.
	 *
	 * @return object
	 */
	private function file(int $id, string $name, string $mime): object {
		return new class ($id, $name, $mime) {
			/**
			 * @param int $id The id.
			 * @param string $name The name.
			 * @param string $mime The mimetype.
			 */
			public function __construct(private readonly int $id, private readonly string $name, private readonly string $mime) {
			}

			/** @return int */
			public function getId(): int {
				return $this->id;
			}

			/** @return string */
			public function getName(): string {
				return $this->name;
			}

			/** @return string */
			public function getMimetype(): string {
				return $this->mime;
			}
		};
	}//end file()

	/**
	 * A publication entity.
	 *
	 * @param array<string, mixed> $data The properties.
	 *
	 * @return ObjectEntity
	 */
	private function publication(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::PUBLICATION);
		$entity->setRegister('19');
		$entity->setSchema('7');
		$entity->setObject($data);
		return $entity;
	}//end publication()

	/**
	 * A public publication's properties.
	 *
	 * @param array<string, mixed> $extra Properties to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function public(array $extra = []): array {
		return $extra + ['title' => 'Besluit parkeren 2026', 'status' => 'published', 'publicationDate' => '2026-01-05T09:00:00+00:00'];
	}//end public()

	/**
	 * The snapshot stores what the public read returned and never a property it hides.
	 *
	 * @return void
	 */
	public function testTheSnapshotHoldsOnlyTheFieldsThePublicReadReturned(): void {
		$old = $this->publication(data: self::public(['internalNote' => 'not for readers']));

		$version = $this->service()->snapshot(old: $old, now: new DateTimeImmutable('2026-11-23T10:00:00+00:00'));

		$this->assertNotNull(actual: $version);
		$this->assertCount(expectedCount: 1, haystack: $this->saved);
		$fields = $this->saved[0]['fields'];
		$this->assertSame(expected: 'Besluit parkeren 2026', actual: $fields['title']);
		$this->assertArrayNotHasKey(key: 'internalNote', array: $fields);
		$this->assertArrayNotHasKey(key: '@self', array: $fields);
		$this->assertSame(expected: self::PUBLICATION, actual: $this->saved[0]['publication']);
		$this->assertSame(expected: 1, actual: $this->saved[0]['version']);
		$this->assertSame(expected: '2026-11-23T10:00:00+00:00', actual: $this->saved[0]['supersededAt']);
	}//end testTheSnapshotHoldsOnlyTheFieldsThePublicReadReturned()

	/**
	 * A draft, scheduled or withdrawn publication has no public version to keep.
	 *
	 * @return void
	 */
	public function testANonPublicPublicationIsNotSnapshotted(): void {
		$cases = [
			['title' => 'Concept', 'status' => 'draft'],
			self::public(['publicationDate' => '2999-01-01T00:00:00+00:00']),
			self::public(['depublicationDate' => '2026-02-01T00:00:00+00:00']),
		];
		foreach ($cases as $data) {
			$this->assertNull(actual: $this->service()->snapshot(old: $this->publication(data: $data)));
		}

		$this->assertSame(expected: [], actual: $this->saved);
	}//end testANonPublicPublicationIsNotSnapshotted()

	/**
	 * Each public file is copied onto the version and listed with its title and format.
	 *
	 * @return void
	 */
	public function testThePublicFilesAreCopiedOntoTheVersion(): void {
		$this->files = [$this->file(id: 5, name: 'besluit.pdf', mime: 'application/pdf')];

		$version = $this->service()->snapshot(old: $this->publication(data: self::public()));

		$this->assertCount(expectedCount: 2, haystack: $this->saved);
		$this->assertSame(expected: [[5, 'v0000000-0000-4000-8000-000000000001']], actual: $this->copies);
		$this->assertSame(
			expected: [['title' => 'besluit.pdf', 'format' => 'application/pdf', 'fileId' => 1005]],
			actual: $this->saved[1]['documents']
		);
		$this->assertSame(expected: [['title' => 'besluit.pdf', 'format' => 'application/pdf', 'fileId' => 1005]], actual: $version['documents']);
	}//end testThePublicFilesAreCopiedOntoTheVersion()

	/**
	 * The next version follows the highest stored for the same publication.
	 *
	 * @return void
	 */
	public function testTheNextVersionFollowsTheLatest(): void {
		$this->stored = [
			$this->publication(data: ['publication' => self::PUBLICATION, 'version' => 2]),
			['publication' => 'someone-else', 'version' => 9],
			['publication' => self::PUBLICATION, 'version' => 1],
		];

		$this->service()->snapshot(old: $this->publication(data: self::public()));

		$this->assertSame(expected: 3, actual: $this->saved[0]['version']);
	}//end testTheNextVersionFollowsTheLatest()

	/**
	 * Without the version schema configured the snapshot throws, so the listener refuses the correction.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredVersionSchemaThrows(): void {
		$this->expectException(exception: RuntimeException::class);

		$this->service(config: ['register' => '19', 'schema' => ''])->snapshot(old: $this->publication(data: self::public()));
	}//end testAnUnconfiguredVersionSchemaThrows()

	/**
	 * A changed public field, or a changed set of public files, replaces the public version.
	 *
	 * @return void
	 */
	public function testAChangedPublicFieldReplacesThePublicVersion(): void {
		$service = $this->service();
		$old = $this->publication(data: self::public());

		$this->assertTrue(condition: $service->replacesPublicVersion(old: $old, new: $this->publication(data: self::public(['title' => 'Besluit parkeren binnenstad 2026']))));
		$this->assertFalse(condition: $service->replacesPublicVersion(old: $old, new: $this->publication(data: self::public(['internalNote' => 'edited']))));
		$this->assertFalse(
			condition: $service->replacesPublicVersion(old: $old, new: $this->publication(data: array_reverse(self::public(), true))),
			message: 'The same fields saved in another order are the same version.'
		);
		$this->files = [$this->file(id: 5, name: 'besluit.pdf', mime: 'application/pdf')];
		$this->assertFalse(condition: $service->replacesPublicVersion(old: $old, new: $this->publication(data: self::public())));
	}//end testAChangedPublicFieldReplacesThePublicVersion()

	/**
	 * A withdrawal or a draft never asks for a version.
	 *
	 * @return void
	 */
	public function testAWithdrawalOrADraftDoesNotReplaceAPublicVersion(): void {
		$service = $this->service();

		$this->assertFalse(condition: $service->replacesPublicVersion(
			old: $this->publication(data: self::public()),
			new: $this->publication(data: self::public(['title' => 'Ingetrokken', 'depublicationDate' => '2026-02-01T00:00:00+00:00']))
		));
		$this->assertFalse(condition: $service->replacesPublicVersion(
			old: $this->publication(data: ['title' => 'Concept', 'status' => 'draft']),
			new: $this->publication(data: self::public())
		));
	}//end testAWithdrawalOrADraftDoesNotReplaceAPublicVersion()
}//end class
