<?php

/**
 * The document id, metadata and links on a publication's attachment lists.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DocumentFacts;
use OCA\OpenCatalogi\Service\Publication\PublicDocuments;
use OCA\OpenCatalogi\Service\SitemapDocumentMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicDocuments
 * @covers \OCA\OpenCatalogi\Service\Publication\DocumentFacts
 */
class PublicDocumentsTest extends TestCase {

	private const PUBLICATION = [
		'id' => 'pub-1',
		'documentsoort' => 'besluit',
		'fileDocumentsoorten' => ['7' => 'bijlage'],
		'creationDate' => '2026-10-01',
	];

	/** @var CatalogiServiceInterface&MockObject */
	private CatalogiServiceInterface $catalogs;

	/** @var PublicationQueryServiceInterface&MockObject */
	private PublicationQueryServiceInterface $query;

	private PublicDocuments $documents;

	/**
	 * The service over doubled catalogue, query, OpenRegister and URL boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->query = $this->createMock(PublicationQueryServiceInterface::class);
		$this->query->method('applyCatalogReadRuleGuard')->willReturnArgument(0);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('installedObjectService')->willReturn(new \stdClass());
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => $route . ':' . implode('/', $parameters)
		);

		$this->documents = new PublicDocuments(
			catalogs: $this->catalogs,
			query: $this->query,
			openRegister: $lookup,
			urls: $urls,
			mapper: $this->createMock(SitemapDocumentMapper::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Catalogue `woo` holds publication pub-1.
	 *
	 * @return void
	 */
	private function wooHoldsThePublication(): void {
		$entity = new class(self::PUBLICATION) {
			/**
			 * @param array<string, mixed> $data The serialisation.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
		$this->catalogs->method('getCatalogBySlug')->willReturnCallback(
			static fn (string $slug): ?array => ['slug' => $slug]
		);
		$this->query->method('findObjectInCatalog')->willReturnCallback(
			static fn (array $catalog): ?object => ($catalog['slug'] === 'woo' ? $entity : null)
		);
	}//end wooHoldsThePublication()

	/**
	 * A two-file list as the publication service answers it.
	 *
	 * @return JSONResponse
	 */
	private function list(): JSONResponse {
		return new JSONResponse(
			[
				'results' => [
					['id' => 42, 'title' => 'besluit.pdf', 'extension' => 'PDF', 'position' => 1],
					['id' => 7, 'title' => 'bijlage', 'type' => 'text/plain', 'position' => 2],
				],
				'total' => 2,
			]
		);
	}//end list()

	/**
	 * Each document on the catalogue list carries its id, format, type, creation date and links.
	 *
	 * @return void
	 */
	public function testTheCatalogueListCarriesTheDocumentFacts(): void {
		$this->wooHoldsThePublication();

		$results = $this->documents->describe(response: $this->list(), catalogSlug: 'woo', publicationId: 'pub-1')->getData()['results'];

		$this->assertSame('42', $results[0]['documentId']);
		$this->assertSame('pdf', $results[0]['format']);
		$this->assertSame('besluit', $results[0]['documentsoort']);
		$this->assertSame('2026-10-01', $results[0]['creationDate']);
		$this->assertSame('opencatalogi.publicDocument.show:woo/pub-1/42', $results[0]['metadataUrl']);
		$this->assertSame('opencatalogi.publicDocument.diwoo:woo/pub-1/42', $results[0]['diwooUrl']);
		$this->assertSame('7', $results[1]['documentId']);
		$this->assertSame('text/plain', $results[1]['format']);
		$this->assertSame('bijlage', $results[1]['documentsoort']);
		$this->assertSame(2, $results[1]['position']);
	}//end testTheCatalogueListCarriesTheDocumentFacts()

	/**
	 * Without a slug the first catalogue that holds the publication gives the links.
	 *
	 * @return void
	 */
	public function testTheFederationListFindsTheCatalogue(): void {
		$this->catalogs->method('getCatalogSlugs')->willReturn(['other', 'woo']);
		$this->wooHoldsThePublication();

		$results = $this->documents->describe(response: $this->list(), catalogSlug: null, publicationId: 'pub-1')->getData()['results'];

		$this->assertSame('opencatalogi.publicDocument.show:woo/pub-1/42', $results[0]['metadataUrl']);
	}//end testTheFederationListFindsTheCatalogue()

	/**
	 * A publication no catalogue resolves still gets its document ids, without links or stored metadata.
	 *
	 * @return void
	 */
	public function testAnUnresolvedCatalogueGivesIdsWithoutLinks(): void {
		$this->catalogs->method('getCatalogSlugs')->willReturn([]);

		$results = $this->documents->describe(response: $this->list(), catalogSlug: null, publicationId: 'pub-1')->getData()['results'];

		$this->assertSame('42', $results[0]['documentId']);
		$this->assertArrayNotHasKey('metadataUrl', $results[0]);
		$this->assertArrayNotHasKey('documentsoort', $results[0]);
	}//end testAnUnresolvedCatalogueGivesIdsWithoutLinks()

	/**
	 * A remote answer and a refusal are passed through untouched.
	 *
	 * @return void
	 */
	public function testARemoteListOrARefusalIsUnchanged(): void {
		$this->catalogs->expects($this->never())->method('getCatalogSlugs');
		$remote = new JSONResponse(['results' => [['id' => 1]], 'sources' => ['https://elders.nl']]);
		$refused = new JSONResponse(['error' => 'Not Found'], 404);

		$this->assertSame([['id' => 1]], $this->documents->describe(response: $remote, catalogSlug: null, publicationId: 'x')->getData()['results']);
		$this->assertSame(['error' => 'Not Found'], $this->documents->describe(response: $refused, catalogSlug: 'woo', publicationId: 'x')->getData());
	}//end testARemoteListOrARefusalIsUnchanged()

	/**
	 * A publication is live only between its publication moment and the end of its window.
	 *
	 * @return void
	 */
	public function testOnlyAPublishedPublicationIsLive(): void {
		$now = new DateTimeImmutable('2026-11-16T12:00:00Z');

		$this->assertTrue(DocumentFacts::isLive(publication: ['@self' => ['published' => '2026-11-01']], now: $now));
		$this->assertFalse(DocumentFacts::isLive(publication: ['@self' => ['published' => null]], now: $now));
		$this->assertFalse(DocumentFacts::isLive(publication: ['@self' => ['published' => '2026-12-01']], now: $now));
		$this->assertFalse(DocumentFacts::isLive(publication: ['@self' => ['published' => '2026-11-01', 'depublished' => '2026-11-10']], now: $now));
		$this->assertFalse(DocumentFacts::isLive(publication: ['@self' => ['published' => 'not a date']], now: $now));
		$this->assertFalse(DocumentFacts::isLive(publication: [], now: $now));
	}//end testOnlyAPublishedPublicationIsLive()
}//end class
