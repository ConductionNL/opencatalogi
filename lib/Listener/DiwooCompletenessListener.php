<?php

/**
 * OpenCatalogi DiWoo Completeness Listener.
 *
 * Before OpenRegister writes a publication, this listener stores a document
 * type as the resource address of its member in the national list, refuses a
 * document type the list does not hold, refuses a validity that ends before it
 * starts, and refuses to make a Woo publication public (now or on a scheduled
 * date) while a field the DiWoo metadata XSD makes mandatory is missing. The
 * safe state is unpublished: the save is stopped with every missing field named.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/diwoo-metadata-on-the-publication
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DiwooCompletenessInterface;
use OCA\OpenCatalogi\Contract\DocumentTypeVocabularyInterface;
use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Normalises and checks a publication's DiWoo metadata before it is saved.
 *
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-woo-publication-without-the-metadata-the-woo-index-requires-does-not-go-public-req-dwp-003
 *
 * @template-implements IEventListener<Event>
 */
class DiwooCompletenessListener implements IEventListener {

	/**
	 * The start of the refusal message; the field names follow it.
	 */
	public const REFUSAL = 'This Woo publication cannot be made public, the DiWoo metadata is incomplete';

	/**
	 * Constructor.
	 *
	 * @param DocumentTypeVocabularyInterface $vocabulary The bundled value lists.
	 * @param DiwooCompletenessInterface $documents The DiWoo document mapping, which decides what is mandatory.
	 * @param InformationCategoryRegistryInterface $categories The Woo categories, which may name the schemas they cover.
	 * @param CatalogiServiceInterface $catalogi Reaches OpenRegister's schema mapper for a schema's slug.
	 * @param IAppConfig $appConfig Holds the publication schema.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly DocumentTypeVocabularyInterface $vocabulary,
		private readonly DiwooCompletenessInterface $documents,
		private readonly InformationCategoryRegistryInterface $categories,
		private readonly CatalogiServiceInterface $catalogi,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise the document types and stop the save when the metadata does not hold.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		$declared = $this->schemaIsDeclared(entity: $entity);
		if ($declared === false && $this->isPublicationSchema(entity: $entity) === false) {
			return;
		}

		$data = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$errors = [];
		$modified = $this->normalisedTypes(data: $data, errors: $errors);
		$data = array_merge($data, $modified);
		$errors = array_merge($errors, self::validityErrors(data: $data));
		if ($this->mustBeComplete(data: $data, declared: $declared) === true) {
			$errors = array_merge($errors, $this->mandatoryErrors(data: $data, entity: $entity));
		}

		if ($errors !== []) {
			$event->setErrors(['message' => self::REFUSAL . ': ' . implode(', ', array_keys($errors)), 'fields' => $errors]);
			$event->stopPropagation();
			return;
		}

		if ($modified !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $modified));
		}

	}//end handle()

	/**
	 * The object being written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()

	/**
	 * Whether the object is in OpenCatalogi's publication schema.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True for a publication.
	 */
	private function isPublicationSchema(ObjectEntity $entity): bool {
		$schema = trim($this->appConfig->getValueString('opencatalogi', 'publication_schema', ''));

		return $schema !== '' && (string)$entity->getSchema() === $schema;
	}//end isPublicationSchema()

	/**
	 * Whether a Woo category names the object's schema for its sitemap (REQ-WIC-003).
	 *
	 * Only asked of OpenRegister when some category names schemas at all. A schema
	 * whose slug cannot be read is not declared: that object is then judged by its
	 * own wooCategory, as any publication is.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True when a category declares the schema.
	 */
	private function schemaIsDeclared(ObjectEntity $entity): bool {
		$declared = [];
		foreach ($this->categories->all() as $code => $record) {
			foreach ($this->categories->schemasFor(code: (string)$code) as $slug) {
				$declared[] = strtolower((string)$slug);
			}
		}

		if ($declared === [] || $entity->getSchema() === null) {
			return false;
		}

		try {
			$slug = $this->catalogi->getSchemaMapper()?->find($entity->getSchema())->getSlug();
		} catch (\Throwable $e) {
			$this->logger->warning('OpenCatalogi: the schema of a saved object could not be read for the DiWoo check: ' . $e->getMessage());
			return false;
		}

		return is_string($slug) === true && in_array(strtolower($slug), $declared, true) === true;
	}//end schemaIsDeclared()

	/**
	 * The document types rewritten to their list member's URI; an unknown one is an error.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param array<string, string> $errors The errors, appended to.
	 *
	 * @return array<string, mixed> The rewritten fields.
	 */
	private function normalisedTypes(array $data, array &$errors): array {
		$modified = [];
		$type = $data['documentsoort'] ?? null;
		if ($type !== null && $type !== '') {
			$uri = $this->documentsoortUri(type: $type);
			if ($uri === null) {
				$errors['documentsoort'] = 'is not a member of the national list of document types';
			} else if ($uri !== $type) {
				$modified['documentsoort'] = $uri;
			}
		}

		$perFile = $data['fileDocumentsoorten'] ?? null;
		if ($perFile === null || $perFile === []) {
			return $modified;
		}

		$rewritten = $this->normalisedFileTypes(perFile: $perFile);
		if ($rewritten === null) {
			$errors['fileDocumentsoorten'] = 'names a document type that is not a member of the national list of document types';
		} else if ($rewritten !== $perFile) {
			$modified['fileDocumentsoorten'] = $rewritten;
		}

		return $modified;
	}//end normalisedTypes()

	/**
	 * The per-file document types as URIs, or null when one does not resolve.
	 *
	 * @param mixed $perFile The stored value: file id to document type.
	 *
	 * @return array<string, string>|null The rewritten map, or null.
	 */
	private function normalisedFileTypes(mixed $perFile): ?array {
		if (is_array($perFile) === false) {
			return null;
		}

		$rewritten = [];
		foreach ($perFile as $fileId => $type) {
			$uri = $this->documentsoortUri(type: $type);
			if ($uri === null) {
				return null;
			}

			$rewritten[$fileId] = $uri;
		}

		return $rewritten;
	}//end normalisedFileTypes()

	/**
	 * The URI of the list member a document type names, or null when it names none.
	 *
	 * @param mixed $type The value given: a URI, code or label.
	 *
	 * @return string|null The member's URI.
	 */
	private function documentsoortUri(mixed $type): ?string {
		if (is_string($type) === false) {
			return null;
		}

		return ($this->vocabulary->resolveDocumentsoort($type)['uri'] ?? null);
	}//end documentsoortUri()

	/**
	 * A validity that ends before it starts, as an error on validUntil.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return array<string, string> The error, or none.
	 */
	private static function validityErrors(array $data): array {
		$from = self::dayOf(value: $data['validFrom'] ?? null);
		$until = self::dayOf(value: $data['validUntil'] ?? null);
		if ($from !== null && $until !== null && $until < $from) {
			return ['validUntil' => 'ends before validFrom'];
		}

		return [];
	}//end validityErrors()

	/**
	 * A date value as Y-m-d, or null when there is none or it does not parse.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The day.
	 */
	private static function dayOf(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$time = strtotime($value);
		if ($time === false) {
			return null;
		}

		return gmdate('Y-m-d', $time);
	}//end dayOf()

	/**
	 * Whether the save makes a Woo publication public, now or on a scheduled date.
	 *
	 * A Woo publication has a wooCategory or sits in a schema a category declares.
	 * A save without a publication date is a draft and may be incomplete.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param bool $declared Whether a category declares the schema.
	 *
	 * @return bool True when the mandatory fields must be present.
	 */
	private function mustBeComplete(array $data, bool $declared): bool {
		$publicationDate = $data['publicationDate'] ?? null;
		if (is_string($publicationDate) === false || trim($publicationDate) === '') {
			return false;
		}

		$category = $data['wooCategory'] ?? null;

		return $declared === true || (is_string($category) === true && trim($category) !== '');
	}//end mustBeComplete()

	/**
	 * The mandatory DiWoo fields the publication cannot fill.
	 *
	 * A check that cannot run refuses: the publication stays unpublished.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param ObjectEntity $entity The object, for its organisation.
	 *
	 * @return array<string, string> The missing fields and why.
	 */
	private function mandatoryErrors(array $data, ObjectEntity $entity): array {
		$data['@self'] = ['organisation' => $entity->getOrganisation()];
		try {
			return $this->documents->mandatoryGaps(publication: $data);
		} catch (\Throwable $e) {
			$this->logger->error('OpenCatalogi: the DiWoo metadata of a publication could not be checked: ' . $e->getMessage());
			return ['metadata' => 'could not be checked'];
		}
	}//end mandatoryErrors()
}//end class
