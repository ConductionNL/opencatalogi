<?php

/**
 * One public document of a publication, through its two routes.
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
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Controller\PublicDocumentController;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\PublicDocuments;
use OCA\OpenCatalogi\Service\SitemapDocumentMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `GET /api/{catalogSlug}/{id}/documents/{documentId}` and its `/diwoo` twin.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicDocumentController
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicDocuments
 * @uses   \OCA\OpenCatalogi\Service\Publication\DocumentFacts
 * @uses   \OCA\OpenCatalogi\Service\Publication\AttachmentOrder
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Http\XMLResponse
 */
class PublicDocumentControllerTest extends TestCase {

	private const PUBLICATION = [
		'id' => 'pub-1',
		'title' => 'Besluit op Woo-verzoek',
		'documentsoort' => 'besluit',
		'creationDate' => '2026-10-01',
		'@self' => ['id' => 'pub-1', 'name' => 'Besluit op Woo-verzoek', 'published' => '2026-10-02T09:00:00+00:00'],
	];

	private const FILE = [
		'id' => 42,
		'title' => 'besluit.pdf',
		'path' => '/admin/files/besluit.pdf',
		'hash' => 'abc',
		'extension' => 'PDF',
		'type' => 'application/pdf',
		'size' => 2048,
		'published' => '2026-10-02T09:00:00+00:00',
		'downloadUrl' => 'https://example.nl/s/xyz/download',
	];

	/** @var CatalogiServiceInterface&MockObject */
	private CatalogiServiceInterface $catalogs;

	/** @var PublicationQueryServiceInterface&MockObject */
	private PublicationQueryServiceInterface $query;

	/** @var MockObject */
	private MockObject $files;

	/** @var MockObject */
	private MockObject $objects;

	/** @var SitemapDocumentMapper&MockObject */
	private SitemapDocumentMapper $mapper;

	private PublicDocumentController $controller;

	/**
	 * A double that only configures the methods the class really has, adding the rest.
	 *
	 * Same helper as SitemapServiceTest::environmentAwareDouble().
	 *
	 * @param class-string $class The class to double.
	 * @param array<int, string> $methods The methods to configure.
	 *
	 * @return MockObject The double.
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = [];
		$absent = [];
		foreach ($methods as $method) {
			if (method_exists($class, $method) === true) {
				$existing[] = $method;
			} else {
				$absent[] = $method;
			}
		}

		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if ($existing !== []) {
			$builder->onlyMethods($existing);
		}

		if ($absent !== []) {
			$builder->addMethods($absent);
		}

		return $builder->getMock();
	}//end environmentAwareDouble()

	/**
	 * Wire the controller to the real document service over doubled boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->query = $this->createMock(PublicationQueryServiceInterface::class);
		$this->query->method('applyCatalogReadRuleGuard')->willReturnArgument(0);
		$this->objects = $this->environmentAwareDouble(ObjectService::class, ['runAsAnonymous']);
		$this->objects->method('runAsAnonymous')->willReturnCallback(static fn (callable $read): mixed => $read());
		$this->files = $this->environmentAwareDouble(FileService::class, ['getFiles', 'formatFile']);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('installedObjectService')->willReturn($this->objects);
		$lookup->method('installedService')->willReturn($this->files);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => 'https://example.nl/' . $route . '?' . http_build_query($parameters)
		);
		$this->mapper = $this->createMock(SitemapDocumentMapper::class);

		$documents = new PublicDocuments(
			catalogs: $this->catalogs,
			query: $this->query,
			openRegister: $lookup,
			urls: $urls,
			mapper: $this->mapper,
			logger: $this->createMock(LoggerInterface::class)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('*');

		$this->controller = new PublicDocumentController(
			appName: 'opencatalogi',
			request: $request,
			documents: $documents,
			l10n: $l10n,
			appConfig: $appConfig
		);
	}//end setUp()

	/**
	 * The catalogue holds the publication and lists these files.
	 *
	 * @param array<string, mixed>       $publication The publication.
	 * @param array<int, array<string, mixed>> $formatted The formatted files, by node.
	 *
	 * @return void
	 */
	private function holds(array $publication, array $formatted): void {
		$entity = new class($publication) {
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
			static fn (string $slug): ?array => ($slug === 'woo' ? ['id' => 'c1', 'registers' => [1], 'schemas' => [2]] : null)
		);
		$this->query->method('findObjectInCatalog')->willReturnCallback(
			static fn (array $catalog, string $id): ?object => ($id === 'pub-1' ? $entity : null)
		);

		$nodes = [];
		$byNode = [];
		foreach ($formatted as $index => $file) {
			$node = $this->createMock(File::class);
			$node->method('getId')->willReturn((int) $file['id']);
			$node->method('getUploadTime')->willReturn(1000 + $index);
			$nodes[] = $node;
			$byNode[spl_object_id($node)] = $file;
		}

		$this->files->method('getFiles')->willReturn($nodes);
		$this->files->method('formatFile')->willReturnCallback(static fn (File $node): array => $byNode[spl_object_id($node)]);
	}//end holds()

	/**
	 * The metadata route answers the document's facts and links back to its publication.
	 *
	 * @return void
	 */
	public function testADocumentAnswersItsMetadataAndItsPublication(): void {
		$other = ['id' => 7, 'title' => 'bijlage.pdf', 'extension' => 'pdf'] + self::FILE;
		$this->holds(publication: self::PUBLICATION + ['attachmentOrder' => ['7', '42']], formatted: [self::FILE, $other]);

		$response = $this->controller->show(catalogSlug: 'woo', id: 'pub-1', documentId: '42');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('42', $data['documentId']);
		$this->assertSame(2, $data['position']);
		$this->assertSame('besluit.pdf', $data['title']);
		$this->assertSame('pdf', $data['format']);
		$this->assertSame(2048, $data['size']);
		$this->assertSame('besluit', $data['documentsoort']);
		$this->assertSame('2026-10-01', $data['creationDate']);
		$this->assertSame('https://example.nl/s/xyz/download', $data['downloadUrl']);
		$this->assertSame(
			'https://example.nl/opencatalogi.publicDocument.show?catalogSlug=woo&id=pub-1&documentId=42',
			$data['metadataUrl']
		);
		$this->assertSame(
			'https://example.nl/opencatalogi.publicDocument.diwoo?catalogSlug=woo&id=pub-1&documentId=42',
			$data['diwooUrl']
		);
		$this->assertSame(
			[
				'id' => 'pub-1',
				'title' => 'Besluit op Woo-verzoek',
				'url' => 'https://example.nl/opencatalogi.publications.show?catalogSlug=woo&id=pub-1',
			],
			$data['publication']
		);
		$this->assertArrayNotHasKey('path', $data);
		$this->assertArrayNotHasKey('hash', $data);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
	}//end testADocumentAnswersItsMetadataAndItsPublication()

	/**
	 * A file whose own window has ended is not served.
	 *
	 * @return void
	 */
	public function testAWithdrawnFileIs404(): void {
		$this->holds(publication: self::PUBLICATION, formatted: [['depublished' => '2026-01-01T00:00:00+00:00'] + self::FILE]);

		$this->assertSame(404, $this->controller->show(catalogSlug: 'woo', id: 'pub-1', documentId: '42')->getStatus());
		$this->assertSame(404, $this->controller->diwoo(catalogSlug: 'woo', id: 'pub-1', documentId: '42')->getStatus());
	}//end testAWithdrawnFileIs404()

	/**
	 * A document of a publication without a publication moment is not served.
	 *
	 * @return void
	 */
	public function testADocumentOfADraftIs404(): void {
		$draft = self::PUBLICATION;
		$draft['@self']['published'] = null;
		$this->holds(publication: $draft, formatted: [self::FILE]);

		$this->files->expects($this->never())->method('getFiles');

		$this->assertSame(404, $this->controller->show(catalogSlug: 'woo', id: 'pub-1', documentId: '42')->getStatus());
	}//end testADocumentOfADraftIs404()

	/**
	 * A file of the publication that is not listed, another publication and an unknown catalogue are 404.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentPublicationOrCatalogueIs404(): void {
		$this->holds(publication: self::PUBLICATION, formatted: [self::FILE]);

		$this->assertSame(404, $this->controller->show(catalogSlug: 'woo', id: 'pub-1', documentId: '43')->getStatus());
		$this->assertSame(404, $this->controller->show(catalogSlug: 'woo', id: 'pub-2', documentId: '42')->getStatus());
		$this->assertSame(404, $this->controller->show(catalogSlug: 'other', id: 'pub-1', documentId: '42')->getStatus());
	}//end testAnUnknownDocumentPublicationOrCatalogueIs404()

	/**
	 * The DiWoo route answers exactly one diwoo:Document of the mapped file.
	 *
	 * @return void
	 */
	public function testTheDiwooRecordIsOneDocument(): void {
		$this->holds(publication: self::PUBLICATION, formatted: [self::FILE]);
		$this->mapper->expects($this->once())->method('map')
			->with(
				$this->callback(static fn (array $publication): bool => $publication['id'] === 'pub-1'),
				$this->callback(static fn (array $file): bool => $file['documentId'] === '42' && $file['downloadUrl'] === self::FILE['downloadUrl'])
			)
			->willReturn(
				[
					'loc' => self::FILE['downloadUrl'],
					'lastmod' => '2026-10-02',
					'diwoo:Document' => ['diwoo:DiWoo' => ['diwoo:titelcollectie' => ['diwoo:officieleTitel' => 'besluit.pdf']]],
				]
			);

		$response = $this->controller->diwoo(catalogSlug: 'woo', id: 'pub-1', documentId: '42');

		$this->assertSame(200, $response->getStatus());
		$xml = $response->render();
		$this->assertSame(1, substr_count($xml, '<diwoo:Document'));
		$this->assertStringContainsString('<diwoo:officieleTitel>besluit.pdf</diwoo:officieleTitel>', $xml);
		$this->assertStringContainsString('xmlns:diwoo="https://standaarden.overheid.nl/diwoo/metadata/"', $xml);
		$this->assertStringNotContainsString('<loc>', $xml);
	}//end testTheDiwooRecordIsOneDocument()

	/**
	 * The preflight answers both routes.
	 *
	 * @return void
	 */
	public function testThePreflightAllowsTheRead(): void {
		$response = $this->controller->preflightedCors();

		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
	}//end testThePreflightAllowsTheRead()
}//end class
