<?php

/**
 * Unit tests for CompletenessService: complete only when the publication is
 * public, every file on it has a public share, and at least as many files as
 * expected are attached; a failed lookup throws.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\CompletenessService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\CompletenessService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationStateService
 */
final class CompletenessServiceTest extends TestCase {

	/**
	 * The moment every test evaluates at.
	 *
	 * @var string
	 */
	private const NOW = '2026-11-14T10:00:00+00:00';

	/**
	 * Public with expectedDocuments 3 and one shared file: incomplete.
	 *
	 * @return void
	 */
	public function testFewerDocumentsThanExpectedIsIncomplete(): void {
		$service = $this->service(files: [['share_token' => 'abc']]);

		self::assertSame('incomplete', $service->evaluate(publication: $this->publication(expected: 3), object: new ObjectEntity(), now: $this->now()));
		self::assertSame('complete', $this->service(files: [['share_token' => 'a'], ['share_token' => 'b'], ['share_token' => 'c']])
			->evaluate(publication: $this->publication(expected: 3), object: new ObjectEntity(), now: $this->now()));
	}//end testFewerDocumentsThanExpectedIsIncomplete()

	/**
	 * Two files of which one has no share token: incomplete.
	 *
	 * @return void
	 */
	public function testAFileWithoutAShareIsIncomplete(): void {
		foreach ([null, '', '  '] as $token) {
			$service = $this->service(files: [['share_token' => 'abc'], ['share_token' => $token]]);
			self::assertSame('incomplete', $service->evaluate(publication: $this->publication(), object: new ObjectEntity(), now: $this->now()));
		}

		self::assertSame('incomplete', $this->service(files: [['name' => 'no token at all']])
			->evaluate(publication: $this->publication(), object: new ObjectEntity(), now: $this->now()));
	}//end testAFileWithoutAShareIsIncomplete()

	/**
	 * A draft, a scheduled, a withdrawn and an archived publication are incomplete, and their files are not read.
	 *
	 * @return void
	 */
	public function testANonPublicPublicationIsIncomplete(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$service = new CompletenessService(states: new PublicationStateService(), container: $container);

		$cases = [
			'draft'     => [],
			'scheduled' => ['publicationDate' => '2026-11-15T00:00:00+00:00'],
			'withdrawn' => ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-11-14T09:00:00+00:00'],
			'archived'  => ['publicationDate' => '2026-11-01T00:00:00+00:00', 'status' => 'archived'],
		];
		foreach ($cases as $case => $publication) {
			self::assertSame('incomplete', $service->evaluate(publication: $publication, object: new ObjectEntity(), now: $this->now()), $case);
		}
	}//end testANonPublicPublicationIsIncomplete()

	/**
	 * Public, every file shared, enough files: complete; no files and no expectation is complete too.
	 *
	 * @return void
	 */
	public function testEveryFileSharedAndPublicIsComplete(): void {
		$object = new ObjectEntity();
		$mapper = $this->createMock(FileMapper::class);
		$mapper->expects(self::once())->method('getFilesForObject')->with(self::identicalTo($object))
			->willReturn([['share_token' => 'a'], ['share_token' => 'b']]);

		$service = new CompletenessService(states: new PublicationStateService(), container: $this->containerWith(mapper: $mapper));

		self::assertSame('complete', $service->evaluate(publication: $this->publication(expected: 2), object: $object, now: $this->now()));
		self::assertSame('complete', $this->service(files: [])->evaluate(publication: $this->publication(), object: new ObjectEntity(), now: $this->now()));
	}//end testEveryFileSharedAndPublicIsComplete()

	/**
	 * A failing lookup, and OpenRegister without a file mapper, throw instead of answering.
	 *
	 * @return void
	 */
	public function testALookupFailureThrows(): void {
		$mapper = $this->createMock(FileMapper::class);
		$mapper->method('getFilesForObject')->willThrowException(new RuntimeException('database gone'));
		$service = new CompletenessService(states: new PublicationStateService(), container: $this->containerWith(mapper: $mapper));

		try {
			$service->evaluate(publication: $this->publication(), object: new ObjectEntity(), now: $this->now());
			self::fail('no exception');
		} catch (RuntimeException $e) {
			self::assertSame('The files of the publication could not be read: database gone', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister cannot list the files of a publication on this server.');
		(new CompletenessService(states: new PublicationStateService(), container: $this->containerWith(mapper: null)))
			->evaluate(publication: $this->publication(), object: new ObjectEntity(), now: $this->now());
	}//end testALookupFailureThrows()

	/**
	 * completeAt is set when it becomes complete, kept while complete, and kept as history when incomplete.
	 *
	 * @return void
	 */
	public function testTheStoredPropertiesFollowTheValue(): void {
		$service = $this->service(files: []);

		self::assertSame(
			['completeness' => 'complete', 'completeAt' => '2026-11-14T10:00:00+00:00'],
			$service->propertiesFor(value: 'complete', previous: ['completeness' => 'incomplete'], now: $this->now())
		);
		self::assertSame(
			['completeness' => 'complete', 'completeAt' => '2026-11-01T08:00:00+00:00'],
			$service->propertiesFor(value: 'complete', previous: ['completeness' => 'complete', 'completeAt' => '2026-11-01T08:00:00+00:00'], now: $this->now())
		);
		self::assertSame(
			['completeness' => 'incomplete', 'completeAt' => '2026-11-01T08:00:00+00:00'],
			$service->propertiesFor(value: 'incomplete', previous: ['completeness' => 'complete', 'completeAt' => '2026-11-01T08:00:00+00:00'], now: $this->now())
		);
		self::assertSame(['completeness' => 'incomplete'], $service->propertiesFor(value: 'incomplete', previous: [], now: $this->now()));
		self::assertSame(['completeness' => 'incomplete'], $service->storedProperties(previous: []));
		self::assertSame(['completeness' => 'incomplete'], $service->storedProperties(previous: ['completeness' => 'bogus']));
		self::assertSame(
			['completeness' => 'complete', 'completeAt' => '2026-11-01T08:00:00+00:00'],
			$service->storedProperties(previous: ['completeness' => 'complete', 'completeAt' => '2026-11-01T08:00:00+00:00'])
		);
	}//end testTheStoredPropertiesFollowTheValue()

	/**
	 * A public publication.
	 *
	 * @param int|null $expected The expected documents, or null for none.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function publication(?int $expected = null): array {
		$publication = ['title' => 'Besluit', 'publicationDate' => '2026-11-01T00:00:00+00:00'];
		if ($expected !== null) {
			$publication['expectedDocuments'] = $expected;
		}

		return $publication;
	}//end publication()

	/**
	 * The service over a file mapper answering these files.
	 *
	 * @param array<int, array<string, mixed>> $files The files.
	 *
	 * @return CompletenessService The service.
	 */
	private function service(array $files): CompletenessService {
		$mapper = $this->createMock(FileMapper::class);
		$mapper->method('getFilesForObject')->willReturn($files);

		return new CompletenessService(states: new PublicationStateService(), container: $this->containerWith(mapper: $mapper));
	}//end service()

	/**
	 * A container holding this file mapper, or none.
	 *
	 * @param object|null $mapper The mapper.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(?object $mapper): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $mapper !== null && $id === CompletenessService::FILE_MAPPER);
		$container->method('get')->willReturn($mapper);

		return $container;
	}//end containerWith()

	/**
	 * The test moment.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::NOW);
	}//end now()
}//end class
