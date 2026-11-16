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

use OCA\OpenCatalogi\Contract\AttachmentReadingQueueInterface;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Publication\EmbeddedTitle;
use OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter;
use OCA\OpenCatalogi\Service\WooCategory;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\App\IAppManager;
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
	 * @param IRootFolder $rootFolder Resolves document references.
	 * @param ContainerInterface $container Resolves OpenRegister.
	 * @param PublicationLinker $linker The public link to the publication.
	 * @param IL10N $l10n The refusal message.
	 * @param IAppManager|null $appManager Whether OpenRegister is installed.
	 * @param EmbeddedTitleWriter|null $titles Writes the official title into each attached copy (REQ-PFF-002).
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ContainerInterface $container,
		private readonly PublicationLinker $linker,
		private readonly IL10N $l10n,
		private readonly ?IAppManager $appManager = null,
		private readonly ?EmbeddedTitleWriter $titles = null,
	) {

	}//end __construct()

	/**
	 * The documents of a batch that may be published: every `openbaar` one as
	 * it is, every `deels_openbaar` one as its redacted version. `niet_openbaar`
	 * stays out.
	 *
	 * @param array<int, array<string, mixed>> $assessments The batch's assessments.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function listings(array $assessments): array {
		$publishable = array_values(
			array_filter(
				$assessments,
				static fn (array $a): bool => in_array((string)($a['assessment'] ?? ''), ['openbaar', 'deels_openbaar'], true)
			)
		);

		$listings = [];
		foreach ($publishable as $assessment) {
			$isPartial = ((string)($assessment['assessment'] ?? '') === 'deels_openbaar');

			$document = (string)($assessment['documentReference'] ?? '');
			$listing = [
				'title' => (string)($assessment['fileName'] ?? ''),
				'assessment' => (string)($assessment['assessment'] ?? ''),
				'document' => $document,
			];
			if ($isPartial === true) {
				// Only the verified redacted version, never the original.
				$listing['document'] = (string)($assessment['anonymizedDocument'] ?? '');
				$listing['original'] = $document;
				$listing['redactionStatus'] = (string)($assessment['redactionStatus'] ?? '');
				$listing['redactionHash'] = (string)($assessment['anonymizedDocumentHash'] ?? '');
				$listing['redactionVerifiedBy'] = (string)($assessment['redactionVerifiedBy'] ?? '');
			}

			$listings[] = $listing;
		}

		return $listings;
	}//end listings()

	/**
	 * Resolve every document, refusing the publish when one cannot be found.
	 *
	 * @param array<int, array<string, mixed>> $listings The publishable documents ({title, document}).
	 * @param string $owner The batch's creator.
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
				$name = (string)($listing['title'] ?? '');
				if ($name === '') {
					$name = (string)($listing['document'] ?? '');
				}

				$missing[] = $name;
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
	 * @param string $owner The batch's creator.
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
			} elseif (str_starts_with($reference, '/') === true) {
				$node = $this->rootFolder->get($reference);
			} elseif ($owner !== '') {
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
	 * Create the batch's publication and attach its documents.
	 *
	 * Every document is found first, so a missing one stops the publish before
	 * anything is written. On a failure part way, `keepProgress` stores the
	 * batch with the publication and the files attached so far (its status
	 * unchanged), so publishing again continues instead of making a second
	 * publication.
	 *
	 * @param array<string, mixed> $batch The batch.
	 * @param array<int, array<string, mixed>> $listings The publishable documents.
	 * @param string $now The moment of publishing.
	 * @param callable $keepProgress Stores the batch with its progress.
	 *
	 * @return array{id: string, url: string, wooCategory: string, attached: array<int, string>, embeddedTitles: array<string, mixed>}
	 *
	 * @throws RuntimeException When a document is missing or an attachment fails.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-the-approval-gate-stays-and-a-missing-document-stops-the-publish-req-wbp-002
	 */
	public function publishDocuments(array $batch, array $listings, string $now, callable $keepProgress): array {
		$files = $this->resolveAll(listings: $listings, owner: (string)($batch['createdBy'] ?? ''));
		$publication = $this->publication(batch: $batch, now: $now);
		$progress = self::progressOf(batch: $batch, publicationId: $publication['id']);

		foreach ($files as $file) {
			if (in_array($file->getName(), $progress['attached'], true) === true) {
				continue;
			}

			try {
				$title = $this->attach(publication: $publication, file: $file);
			} catch (Throwable $e) {
				$batch['wooPublication'] = array_merge((array)($batch['wooPublication'] ?? []), $progress);
				unset($batch['documentSummary']);
				$keepProgress($batch);
				throw new RuntimeException($this->l10n->t('Publishing stopped at %s. Publish again to continue.', [$file->getName()]), 0, $e);
			}

			$progress['attached'][] = $file->getName();
			if ($title !== null) {
				$progress['embeddedTitles'][$file->getName()] = $title;
			}
		}//end foreach

		return [
			'id' => $publication['id'],
			'url' => $this->url(id: $publication['id']),
			'wooCategory' => $this->category(batch: $batch),
			'attached' => $progress['attached'],
			'embeddedTitles' => $progress['embeddedTitles'],
		];

	}//end publishDocuments()

	/**
	 * How far an earlier attempt got: the publication, the files attached and the titles written into them.
	 *
	 * @param array<string, mixed> $batch         The batch.
	 * @param string               $publicationId The publication the documents are attached to.
	 *
	 * @return array{publication: string, attached: array<int, string>, embeddedTitles: array<string, mixed>}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	private static function progressOf(array $batch, string $publicationId): array {
		return [
			'publication' => $publicationId,
			'attached' => array_values(array_map('strval', (array)($batch['wooPublication']['attached'] ?? []))),
			'embeddedTitles' => (array)($batch['wooPublication']['embeddedTitles'] ?? []),
		];
	}//end progressOf()

	/**
	 * Create the batch's publication, or take the one an earlier attempt made.
	 *
	 * @param array<string, mixed> $batch The batch.
	 * @param string $now The moment of publishing.
	 *
	 * @return array{id: string, entity: mixed, title: string}
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function publication(array $batch, string $now): array {
		$this->requireOpenRegister();
		$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$earlier = (string)($batch['wooPublication']['publication'] ?? '');
		if ($earlier !== '') {
			try {
				$entity = $objects->find(
					id: $earlier,
					options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA),
					_rbac: false,
					_multitenancy: false
				);
				if ($entity !== null) {
					return ['id' => $earlier, 'entity' => $entity, 'title' => $this->titleOf(entity: $entity)];
				}
			} catch (Throwable) {
				// Gone since the last attempt: make a new one below.
			}
		}

		$payload = $this->payload(batch: $batch, now: $now);
		$entity = $objects->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA)),
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => $this->idOf(entity: $entity), 'entity' => $entity, 'title' => $payload['title']];
	}//end publication()

	/**
	 * The publication a batch becomes.
	 *
	 * @param array<string, mixed> $batch The batch.
	 * @param string $now The moment of publishing.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function payload(array $batch, string $now): array {
		$caseReference = (string)($batch['caseReference'] ?? '');
		$title = trim((string)($batch['title'] ?? ''));
		if ($title === '') {
			$title = trim('Woo-publicatie ' . $caseReference);
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
	 * Attach one file to the publication, write the official title into the
	 * attached copy, and publish it.
	 *
	 * The batch's documents reach this only after the publish gate passed
	 * (`DocumentRedactor::assertPublishable()` in WooService::publishBatch()).
	 * The title goes into the copy OpenRegister made for the publication, never
	 * into the officer's own document, so a verified redacted file keeps its
	 * hash and stays the exact prefix of the published PDF.
	 *
	 * @param array{id: string, entity: mixed, title?: string} $publication The publication.
	 * @param File $file The document.
	 *
	 * @return array<string, mixed>|null What was written into the copy (`embeddedTitle`), null without a writer.
	 *
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function attach(array $publication, File $file): ?array {
		$this->requireOpenRegister();
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

		$embedded = null;
		if ($this->titles !== null && $created instanceof File) {
			$facts = new EmbeddedTitle(title: (string)($publication['title'] ?? ''), identifier: $publication['id']);
			$embedded = $this->titles->write(file: $created, facts: $facts);
		}

		$files->publishFile(object: $target, file: $created->getId());
		$this->readingQueue()?->queue(fileId: (int)$created->getId(), publicationId: (string)$publication['id']);

		return $embedded;
	}//end attach()

	/**
	 * The queue that reads each attached document, or null when it cannot be resolved.
	 *
	 * Resolved from the container on use rather than injected, so this
	 * writer's constructor keeps its five collaborators.
	 *
	 * @return AttachmentReadingQueueInterface|null
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	private function readingQueue(): ?AttachmentReadingQueueInterface {
		$queue = null;
		try {
			$queue = $this->container->get(AttachmentReadingQueueInterface::class);
		} catch (\Throwable) {
			// No queue: the document is published unread, as without OCR.
		}

		if ($queue instanceof AttachmentReadingQueueInterface === false) {
			return null;
		}

		return $queue;
	}//end readingQueue()

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
	 * ADR-083: establish OpenRegister before any lookup of it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function requireOpenRegister(): void {
		if ($this->appManager !== null && $this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('OpenRegister is not installed');
		}

	}//end requireOpenRegister()

	/**
	 * The title of a saved publication entity or array.
	 *
	 * @param mixed $entity The saved publication.
	 *
	 * @return string
	 */
	private function titleOf(mixed $entity): string {
		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			return (string)(((array)$entity->getObject())['title'] ?? '');
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$entity = $entity->jsonSerialize();
		}

		return (string)(((array)$entity)['title'] ?? '');
	}//end titleOf()

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
