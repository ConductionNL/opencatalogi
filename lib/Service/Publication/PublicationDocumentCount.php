<?php

/**
 * OpenCatalogi Publication Document Count.
 *
 * How many documents a publication carries: the files OpenRegister holds for
 * it plus the documentReference objects that point at it. Publish now asks
 * this before it makes a publication public, so a record with nothing to read
 * is not published by accident (REQ-PSG-004).
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
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\DocumentReferencesInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts the files and document references of one publication.
 *
 * A part that cannot be read counts as zero: the publish route then asks for
 * the confirmation instead of publishing a record whose documents nobody saw.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
 */
class PublicationDocumentCount {

	/**
	 * OpenRegister's file mapper, read straight from the database without dispatching events.
	 */
	public const FILE_MAPPER = 'OCA\OpenRegister\Files\Db\FileMapper';

	/**
	 * The most references read for one publication.
	 */
	private const REFERENCE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface          $container    Resolves OpenRegister's file mapper.
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService.
	 * @param IAppConfig                  $config       Holds the register and the document reference schema.
	 * @param LoggerInterface             $logger       The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The number of documents the publication carries.
	 *
	 * @param string      $id          The publication id.
	 * @param object|null $publication The publication entity when the caller already loaded it.
	 *
	 * @return int Its files plus the references pointing at it; an unreadable part counts as zero.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
	 */
	public function count(string $id, ?object $publication=null): int {
		if ($id === '') {
			return 0;
		}

		return $this->files(id: $id, publication: $publication) + $this->references(id: $id);
	}//end count()

	/**
	 * The number of files OpenRegister holds for the publication.
	 *
	 * @param string      $id          The publication id.
	 * @param object|null $publication The publication entity, loaded by id when null.
	 *
	 * @return int The file count, zero when the files cannot be read.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
	 */
	private function files(string $id, ?object $publication): int {
		try {
			if ($this->container->has(self::FILE_MAPPER) === false) {
				return 0;
			}

			$entity = ($publication ?? $this->openRegister->objectService()->find($id));
			if ($entity === null) {
				return 0;
			}

			return count((array)$this->container->get(self::FILE_MAPPER)->getFilesForObject($entity));
		} catch (Throwable $e) {
			$this->logger->warning('[PublicationDocumentCount] the files of publication ' . $id . ' could not be read: ' . $e->getMessage());
			return 0;
		}
	}//end files()

	/**
	 * The number of documentReference objects that point at the publication.
	 *
	 * @param string $id The publication id.
	 *
	 * @return int The reference count, zero while the schema is absent or unreadable.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.1
	 */
	private function references(string $id): int {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', DocumentReferencesInterface::SCHEMA_KEY, ''));
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			if ($this->openRegister->isInstalled() === false) {
				return 0;
			}

			$result = $this->openRegister->objectService()->searchObjectsPaginated(
				query: [
					'@self'       => ['register' => $register, 'schema' => $schema],
					'publication' => $id,
					'_limit'      => self::REFERENCE_LIMIT,
					'_page'       => 1,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[PublicationDocumentCount] the references of publication ' . $id . ' could not be read: ' . $e->getMessage());
			return 0;
		}

		$rows = array_values((array)($result['results'] ?? []));

		return count(array_filter($rows, fn (mixed $row): bool => $this->pointsAt(row: $row, id: $id)));
	}//end references()

	/**
	 * Whether a reference row names the publication.
	 *
	 * @param mixed  $row The row as OpenRegister returned it.
	 * @param string $id  The publication id.
	 *
	 * @return bool True when its `publication` is the id.
	 */
	private function pointsAt(mixed $row, string $id): bool {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return is_array($row) === true && (string)($row['publication'] ?? '') === $id;
	}//end pointsAt()
}//end class
