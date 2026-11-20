<?php

/**
 * A publication's documents as the public reads them: one id, one metadata route, one DiWoo record.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicDocumentsInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\SitemapDocumentMapper;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Node;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives every attachment a stable `documentId` and serves one document on its own.
 *
 * Every read runs inside OpenRegister's anonymous scope when the installed
 * OpenRegister has it, through the catalogue's read-rule guard, and lists
 * shared files only. A document of a draft or depublished publication, a
 * withdrawn file and a file of another publication are not found.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DocumentFacts and AttachmentOrder are pure functions.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads catalogues, OpenRegister objects and files, and renders DiWoo.
 */
class PublicDocuments implements PublicDocumentsInterface {

	/**
	 * Constructor.
	 *
	 * @param CatalogiServiceInterface         $catalogs     Resolves a catalogue by slug.
	 * @param PublicationQueryServiceInterface $query        The catalogue read-rule guard and the scoped object read.
	 * @param OpenRegisterLookup               $openRegister Reaches OpenRegister's object and file services.
	 * @param IURLGenerator                    $urls         Builds the absolute links.
	 * @param SitemapDocumentMapper            $mapper       Renders one file as a DiWoo document.
	 * @param LoggerInterface                  $logger       Logger.
	 */
	public function __construct(
		private readonly CatalogiServiceInterface $catalogs,
		private readonly PublicationQueryServiceInterface $query,
		private readonly OpenRegisterLookup $openRegister,
		private readonly IURLGenerator $urls,
		private readonly SitemapDocumentMapper $mapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * An attachment list with each document's id, metadata and links added.
	 *
	 * @param JSONResponse $response      The attachment list as the publication service answered it.
	 * @param string|null  $catalogSlug   The catalogue it was read through, or null to find it.
	 * @param string       $publicationId The publication.
	 *
	 * @return JSONResponse The list, unchanged when it is not a local answer.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public function describe(JSONResponse $response, ?string $catalogSlug, string $publicationId): JSONResponse {
		$page = $response->getData();
		if ($response->getStatus() !== 200 || is_array($page) === false
			|| isset($page['sources']) === true || is_array($page['results'] ?? null) === false
		) {
			return $response;
		}

		$located = $this->located(catalogSlug: $catalogSlug, publicationId: $publicationId);
		$links = null;
		$publication = [];
		if ($located !== null) {
			$publication = $located['publication'];
			$slug = $located['slug'];
			$links = fn (string $documentId): array => $this->links(catalogSlug: $slug, publicationId: $publicationId, documentId: $documentId);
		}

		$response->setData(DocumentFacts::onPage(page: $page, publication: $publication, links: $links));

		return $response;

	}//end describe()

	/**
	 * One public document of a publicly readable publication.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $documentId    The Nextcloud file id.
	 *
	 * @return array{publication: array<string, mixed>, document: array<string, mixed>, slug: string}|null The document, or null.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public function find(string $catalogSlug, string $publicationId, string $documentId): ?array {
		try {
			$objects = $this->openRegister->installedObjectService();
			$read = fn (): ?array => $this->readDocument(
				objects: $objects,
				catalogSlug: $catalogSlug,
				publicationId: $publicationId,
				documentId: $documentId
			);
			if (method_exists($objects, 'runAsAnonymous') === true) {
				return $objects->runAsAnonymous($read);
			}

			return $read();
		} catch (Throwable $e) {
			// Fail closed: a document that cannot be read is not found.
			$this->logger->warning(
				'OpenCatalogi: a public document could not be read',
				['publication' => $publicationId, 'document' => $documentId, 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end find()

	/**
	 * A found document's metadata with the publication it belongs to.
	 *
	 * @param array{publication: array<string, mixed>, document: array<string, mixed>, slug: string} $found What find() answered.
	 *
	 * @return array<string, mixed> The public keys of the document and `publication: {id, title, url}`.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public function metadata(array $found): array {
		$publication = $found['publication'];
		$id = (string) ($publication['@self']['id'] ?? $publication['id'] ?? '');

		return DocumentFacts::publicView(document: $found['document']) + [
			'publication' => [
				'id' => $id,
				'title' => (string) ($publication['@self']['name'] ?? $publication['title'] ?? ''),
				'url' => $this->urls->linkToRouteAbsolute(
					'opencatalogi.publications.show',
					['catalogSlug' => $found['slug'], 'id' => $id]
				),
			],
		];

	}//end metadata()

	/**
	 * A found document as one `diwoo:Document`, ready for XMLResponse.
	 *
	 * @param array{publication: array<string, mixed>, document: array<string, mixed>, slug: string} $found What find() answered.
	 *
	 * @return array<string, mixed> The XML data, rooted at `diwoo:Document`.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public function diwoo(array $found): array {
		$entry = $this->mapper->map(publication: $found['publication'], file: $found['document']);

		return [
			'@root' => 'diwoo:Document',
			'@attributes' => ['xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE],
		] + $entry['diwoo:Document'];

	}//end diwoo()

	/**
	 * The document of a live publication in the catalogue, with its facts, position and links.
	 *
	 * @param object $objects       OpenRegister's ObjectService.
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $documentId    The file id.
	 *
	 * @return array{publication: array<string, mixed>, document: array<string, mixed>, slug: string}|null The document, or null.
	 */
	private function readDocument(object $objects, string $catalogSlug, string $publicationId, string $documentId): ?array {
		$entity = $this->entityIn(catalogSlug: $catalogSlug, publicationId: $publicationId, objects: $objects);
		$now = new DateTimeImmutable();
		if ($entity === null) {
			return null;
		}

		$publication = self::asArray(object: $entity);
		if (DocumentFacts::isLive(publication: $publication, now: $now) === false) {
			return null;
		}

		$files = $this->openRegister->installedService('OCA\OpenRegister\Service\FileService', 'OpenRegister FileService is not available.');
		$nodes = AttachmentOrder::sort(
			files: $files->getFiles(object: $publicationId, sharedFilesOnly: true),
			order: AttachmentOrder::storedOrder(publication: $publication)
		);
		foreach (array_values($nodes) as $index => $node) {
			if (($node instanceof Node) === false || (string) $node->getId() !== $documentId) {
				continue;
			}

			$file = $files->formatFile($node);
			if (DocumentFacts::isWithdrawn(file: $file, now: $now) === true) {
				return null;
			}

			$document = DocumentFacts::forFile(file: $file, publication: $publication) + ['position' => ($index + 1)];
			$document += $this->links(catalogSlug: $catalogSlug, publicationId: $publicationId, documentId: $documentId);

			return ['publication' => $publication, 'document' => $document, 'slug' => $catalogSlug];
		}

		return null;

	}//end readDocument()

	/**
	 * The publication and the catalogue it is read through, for the links on a list.
	 *
	 * @param string|null $catalogSlug   The catalogue, or null to take the first that holds the publication.
	 * @param string      $publicationId The publication.
	 *
	 * @return array{publication: array<string, mixed>, slug: string}|null The publication and slug, or null when neither resolves.
	 */
	private function located(?string $catalogSlug, string $publicationId): ?array {
		try {
			$objects = $this->openRegister->installedObjectService();
			$slugs = $this->catalogs->getCatalogSlugs();
			if ($catalogSlug !== null) {
				$slugs = [$catalogSlug];
			}

			foreach ($slugs as $slug) {
				$entity = $this->entityIn(catalogSlug: (string) $slug, publicationId: $publicationId, objects: $objects);
				if ($entity !== null) {
					return ['publication' => self::asArray(object: $entity), 'slug' => (string) $slug];
				}
			}
		} catch (Throwable $e) {
			// The list is still answered, without the links.
			$this->logger->info('OpenCatalogi: no catalogue resolved for the document links', ['error' => $e->getMessage()]);
		}

		return null;

	}//end located()

	/**
	 * The publication as read through the guarded catalogue, or null.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param object $objects       OpenRegister's ObjectService.
	 *
	 * @return object|null The object entity.
	 */
	private function entityIn(string $catalogSlug, string $publicationId, object $objects): ?object {
		$catalog = $this->catalogs->getCatalogBySlug($catalogSlug);
		if ($catalog === null) {
			return null;
		}

		return $this->query->findObjectInCatalog(
			catalog: $this->query->applyCatalogReadRuleGuard(catalog: $catalog),
			id: $publicationId,
			objectService: $objects
		);

	}//end entityIn()

	/**
	 * The two links of one document.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $documentId    The file id.
	 *
	 * @return array{metadataUrl: string, diwooUrl: string}
	 */
	private function links(string $catalogSlug, string $publicationId, string $documentId): array {
		$parameters = ['catalogSlug' => $catalogSlug, 'id' => $publicationId, 'documentId' => $documentId];

		return [
			'metadataUrl' => $this->urls->linkToRouteAbsolute('opencatalogi.publicDocument.show', $parameters),
			'diwooUrl' => $this->urls->linkToRouteAbsolute('opencatalogi.publicDocument.diwoo', $parameters),
		];

	}//end links()

	/**
	 * An entity as an array.
	 *
	 * @param object $object The entity.
	 *
	 * @return array<string, mixed> Its serialisation, or an empty array.
	 */
	private static function asArray(object $object): array {
		if (method_exists($object, 'jsonSerialize') === false) {
			return [];
		}

		$data = $object->jsonSerialize();
		if (is_array($data) === false) {
			return [];
		}

		return $data;

	}//end asArray()
}//end class
