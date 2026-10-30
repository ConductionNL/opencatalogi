<?php

/**
 * The withheldDocument objects of a publication, read and written as the system.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * OpenRegister access for WithheldDocuments: whether the publication exists,
 * and replacing its stored entries.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */
class WithheldDocumentStore {
	use ReadsObjectProperties;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     Holds the register and schema ids.
	 * @param ContainerInterface $container  Resolves OpenRegister's ObjectService.
	 * @param IAppManager        $appManager Says whether OpenRegister is there.
	 * @param LoggerInterface    $logger     Records an unavailable OpenRegister.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister's ObjectService, or a refusal naming why it is not there.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function requireObjects(): object {
		$objects = $this->objects();
		if ($objects === null) {
			throw new RuntimeException('OpenRegister is not available, so the withheld documents cannot be written.');
		}

		return $objects;
	}//end requireObjects()

	/**
	 * Write the accepted entries of the publication, then remove the entries they replace.
	 *
	 * The new entries are written first and the superseded ones deleted afterwards, so a
	 * write that fails halfway leaves the previous complete list: the entries already written
	 * by this call are removed again and the error is passed on. A failed delete of the
	 * superseded rows leaves them next to the new ones until the next record().
	 *
	 * @param string                                                           $publicationId The publication.
	 * @param list<array{position: int, grounds: list<array<string, string>>}> $accepted      The entries to store.
	 * @param string                                                           $source        The recording app.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister or the register configuration is not there.
	 * @throws Throwable        When a write fails; the previous list is kept.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function replace(string $publicationId, array $accepted, string $source): void {
		$objects = $this->requireObjects();
		[$register, $schema] = $this->target(key: 'withheld_document_schema');

		$superseded = $this->storedIds(objects: $objects, register: $register, schema: $schema, publicationId: $publicationId);

		$now = (new DateTimeImmutable())->format('c');
		$written = [];
		try {
			foreach ($accepted as $entry) {
				$saved = $objects->saveObject(
					object: [
						'publication' => $publicationId,
						'position' => $entry['position'],
						'grounds' => $entry['grounds'],
						'source' => $source,
						'recordedAt' => $now,
					],
					register: $register,
					schema: $schema,
					_rbac: false,
					_multitenancy: false
				);
				$written[] = (string)($this->asRecord(object: $saved)['id'] ?? '');
			}
		} catch (Throwable $e) {
			$this->discard(objects: $objects, register: $register, schema: $schema, ids: $written);
			throw $e;
		}

		foreach ($superseded as $id) {
			$objects->deleteObject(uuid: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		}
	}//end replace()

	/**
	 * The ids of the entries stored for a publication.
	 *
	 * @param object $objects       OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $schema        The schema.
	 * @param string $publicationId The publication.
	 *
	 * @return list<string>
	 */
	private function storedIds(object $objects, string $register, string $schema, string $publicationId): array {
		$result = $objects->searchObjectsPaginated(
			query: ['@self' => ['register' => $register, 'schema' => $schema], 'publication' => $publicationId, '_limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		$ids = [];
		foreach ((array)($result['results'] ?? []) as $row) {
			$stored = $this->asRecord(object: $row);
			if ((string)($stored['publication'] ?? '') !== $publicationId) {
				continue;
			}

			$ids[] = (string)($stored['id'] ?? '');
		}

		return $ids;
	}//end storedIds()

	/**
	 * Remove entries a failed replace had already written, so the previous list stands alone.
	 *
	 * @param object       $objects  OpenRegister's ObjectService.
	 * @param string       $register The register.
	 * @param string       $schema   The schema.
	 * @param list<string> $ids      The ids written by the failed call.
	 *
	 * @return void
	 */
	private function discard(object $objects, string $register, string $schema, array $ids): void {
		foreach ($ids as $id) {
			if ($id === '') {
				$this->logger->error('WithheldDocuments: an entry of a failed replace came back without an id and could not be removed');
				continue;
			}

			try {
				$objects->deleteObject(uuid: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->error('WithheldDocuments: an entry of a failed replace could not be removed', ['id' => $id, 'exception' => $e->getMessage()]);
			}
		}
	}//end discard()

	/**
	 * Whether the publication exists, or null when that cannot be told.
	 *
	 * Only OpenRegister saying the object does not exist answers false. When
	 * OpenRegister or the register configuration is not there, or the read fails
	 * otherwise, that is not an answer about the publication: it answers null, so
	 * the caller is not told a publication is missing that a retry or an
	 * administrator would find.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function publicationExists(string $publicationId): ?bool {
		if (trim($publicationId) === '') {
			return false;
		}

		$objects = $this->objects();
		if ($objects === null) {
			return null;
		}

		try {
			[$register, $schema] = $this->target(key: 'publication_schema');
			$found = $objects->find(id: $publicationId, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning(
				'WithheldDocuments: the publication could not be looked up',
				['publication' => $publicationId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $found !== null && $found !== [];
	}//end publicationExists()

	/**
	 * OpenRegister's ObjectService, or null when OpenRegister is not there.
	 *
	 * @return object|null
	 */
	private function objects(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('WithheldDocuments: OpenRegister is unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($objects) === false) {
			return null;
		}

		return $objects;
	}//end objects()

	/**
	 * The publication register and a schema id from the app config.
	 *
	 * @param string $key The schema config key.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws RuntimeException When either is not configured.
	 */
	private function target(string $key): array {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', $key, ''));
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Register configuration is not set for publication_register / ' . $key . '.');
		}

		return [$register, $schema];
	}//end target()
}//end class
