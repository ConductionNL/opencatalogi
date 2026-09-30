<?php

/**
 * OpenCatalogi Woo Batch Publication Writer.
 *
 * Turns a published Woo batch into a real publication (hydra
 * `woo-citizen-journey`, C6): one `publication` with `publicationKind:
 * actief`, and every disclosable document attached to it as a published file
 * (`attachments-are-files`). Before this, publishing a batch only wrote a
 * summary onto the batch object and nothing became public.
 *
 * Every document is resolved to a Nextcloud file before anything is written.
 * A reference is a file id, an absolute Nextcloud path (`/alice/files/x.pdf`)
 * or a path in the files of the batch's creator. One that resolves to nothing,
 * or to a folder, stops the publish with its name.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\WooCategory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates the publication of a Woo batch and attaches its documents.
 *
 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
 */
class BatchPublicationWriter {

	/**
	 * The register and schema of a publication.
	 */
	public const REGISTER = 'publication';

	public const SCHEMA = 'publication';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder        $rootFolder Resolves document references.
	 * @param ContainerInterface $container  Resolves OpenRegister.
	 * @param PublicationLinker  $linker     The public link to the publication.
	 * @param IL10N              $l10n       The refusal message.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ContainerInterface $container,
		private readonly PublicationLinker $linker,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Resolve every document, refusing the publish when one cannot be found.
	 *
	 * @param array<int, array<string, mixed>> $listings The publishable documents ({title, document}).
	 * @param string                           $owner    The batch's creator.
	 *
	 * @return array<int, File> The files, in the listings' order.
	 *
	 * @throws RuntimeException Naming every document that cannot be found.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function resolveAll(array $listings, string $owner): array {
		$files = [];
		$missing = [];
		foreach ($listings as $listing) {
			$file = $this->resolve(reference: (string)($listing['document'] ?? ''), owner: $owner);
			if ($file === null) {
				$missing[] = ((string)($listing['title'] ?? '') !== '' ? (string)$listing['title'] : (string)($listing['document'] ?? ''));
				continue;
			}

			$files[] = $file;
		}

		if ($missing !== []) {
			throw new RuntimeException($this->l10n->t('Publishing is blocked: these documents cannot be found: %s', [implode(', ', $missing)]));
		}

		return $files;

	}//end resolveAll()

	/**
	 * One document reference as a file, or null.
	 *
	 * @param string $reference A file id, an absolute path, or a path in the owner's files.
	 * @param string $owner     The batch's creator.
	 *
	 * @return File|null
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function resolve(string $reference, string $owner): ?File {
		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		try {
			$node = null;
			if (preg_match('/^\d+$/', $reference) === 1) {
				$node = $this->rootFolder->getFirstNodeById((int)$reference);
			} else if (str_starts_with($reference, '/') === true) {
				$node = $this->rootFolder->get($reference);
			} else if ($owner !== '') {
				$node = $this->rootFolder->getUserFolder($owner)->get($reference);
			}
		} catch (Throwable) {
			return null;
		}

		if ($node instanceof File) {
			return $node;
		}

		return null;

	}//end resolve()

	/**
	 * Create the batch's publication, or take the one an earlier attempt made.
	 *
	 * @param array<string, mixed> $batch The batch.
	 * @param string               $now   The moment of publishing.
	 *
	 * @return array{id: string, entity: mixed}
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function publication(array $batch, string $now): array {
		$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$earlier = (string)($batch['wooPublication']['publication'] ?? '');
		if ($earlier !== '') {
			try {
				$entity = $objects->find(id: $earlier, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
				if ($entity !== null) {
					return ['id' => $earlier, 'entity' => $entity];
				}
			} catch (Throwable) {
				// Gone since the last attempt: make a new one below.
			}
		}

		$entity = $objects->saveObject(
			object: $this->payload(batch: $batch, now: $now),
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => $this->idOf(entity: $entity), 'entity' => $entity];

	}//end publication()

	/**
	 * The publication a batch becomes.
	 *
	 * @param array<string, mixed> $batch The batch.
	 * @param string               $now   The moment of publishing.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function payload(array $batch, string $now): array {
		$caseReference = (string)($batch['caseReference'] ?? '');
		$title = trim((string)($batch['title'] ?? ''));
		if ($title === '') {
			$title = trim('Woo-publicatie '.$caseReference);
		}

		$payload = [
			'title' => $title,
			'wooCategory' => $this->category(batch: $batch),
			'publicationKind' => 'actief',
			'publicationDate' => $now,
			'status' => 'published',
		];
		if ($caseReference !== '') {
			$payload['caseReference'] = $caseReference;
		}

		return $payload;

	}//end payload()

	/**
	 * The batch's information category, else the Woo request category.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function category(array $batch): string {
		$category = (string)($batch['wooCategory'] ?? '');
		if (array_key_exists($category, WooCategory::ALL) === true) {
			return $category;
		}

		return WooCategory::WOO_REQUEST;

	}//end category()

	/**
	 * Attach one file to the publication and publish it.
	 *
	 * @param array{id: string, entity: mixed} $publication The publication.
	 * @param File                             $file        The document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function attach(array $publication, File $file): void {
		$files = $this->container->get('OCA\OpenRegister\Service\FileService');
		$target = $publication['entity'];
		if (is_object($target) === false) {
			$target = $publication['id'];
		}

		$stream = $file->fopen('r');
		try {
			$created = $files->addFile(
				objectEntity: $target,
				fileName: $file->getName(),
				content: $stream,
				share: false,
				_register: self::REGISTER,
				_schema: self::SCHEMA
			);
		} finally {
			if (is_resource($stream) === true) {
				fclose($stream);
			}
		}

		$files->publishFile(object: $target, file: $created->getId());

	}//end attach()

	/**
	 * The public link to the publication.
	 *
	 * @param string $id The publication uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function url(string $id): string {
		return $this->linker->url(id: $id);

	}//end url()

	/**
	 * The uuid of a saved entity or array.
	 *
	 * @param mixed $entity The saved publication.
	 *
	 * @return string
	 */
	private function idOf(mixed $entity): string {
		if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
			return (string)$entity->getUuid();
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$entity = $entity->jsonSerialize();
		}

		return (string)($entity['@self']['id'] ?? ($entity['id'] ?? ''));

	}//end idOf()
}//end class
