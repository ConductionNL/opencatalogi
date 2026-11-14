<?php

/**
 * OpenCatalogi Document References.
 *
 * The OpenRegister reads and writes behind document references: the
 * references of a publication that are inside their window, as file entries,
 * every reference inside its window for the daily check, and the check's
 * result written back.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and updates documentReference objects in the publication register.
 *
 * A reference is public only inside its own window. The window is checked
 * here as well as by the schema's public read rule, because the sitemap, DCAT
 * and attachments reads run on the server's behalf.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */
class DocumentReferences {
	use ChecksPublicationWindow;
	use ReadsObjectProperties;

	/**
	 * The app config key of the documentReference schema.
	 */
	public const SCHEMA_KEY = 'document_reference_schema';

	/**
	 * The app config key of the publication register.
	 */
	public const REGISTER_KEY = 'publication_register';

	/**
	 * Rows read per search.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Holds the register and schema ids.
	 * @param OpenRegisterLookup $openRegister OpenRegister's ObjectService.
	 * @param DocumentReferenceMapper $mapper Turns a reference into a file entry.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookup $openRegister,
		private readonly DocumentReferenceMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The references of a publication that are inside their window, as file entries.
	 *
	 * Empty when the schema is not configured or OpenRegister cannot be read:
	 * a missing reference list must never break the files around it.
	 *
	 * @param string $publicationId The publication's id.
	 *
	 * @return list<array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
	 */
	public function publishedFileEntries(string $publicationId): array {
		if ($publicationId === '') {
			return [];
		}

		$entries = [];
		foreach ($this->search(filters: ['publication' => $publicationId], page: 1) as $reference) {
			if ((string)($reference['publication'] ?? '') !== $publicationId || $this->isPublic(reference: $reference) === false) {
				continue;
			}

			$entries[] = $this->mapper->asFileEntry(reference: $reference);
		}

		return $entries;
	}//end publishedFileEntries()

	/**
	 * Files with the publication's public references appended.
	 *
	 * @param array<int, array<string, mixed>> $files The publication's file entries.
	 * @param string $publicationId The publication's id.
	 *
	 * @return array<int, array<string, mixed>> The files, then the references.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
	 */
	public function appendTo(array $files, string $publicationId): array {
		return array_merge(array_values($files), $this->publishedFileEntries(publicationId: $publicationId));
	}//end appendTo()

	/**
	 * Every reference inside its window, up to a cap, for the daily check.
	 *
	 * @param int $cap The most references to return.
	 *
	 * @return list<array<string, mixed>> The references, with `id`.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function inWindow(int $cap): array {
		$found = [];
		for ($page = 1; count($found) < $cap; $page++) {
			$rows = $this->search(filters: [], page: $page);
			foreach ($rows as $reference) {
				if ($this->isPublic(reference: $reference) === true && count($found) < $cap) {
					$found[] = $reference;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}

		return $found;
	}//end inWindow()

	/**
	 * Write the result of a check onto a reference.
	 *
	 * @param array<string, mixed> $reference The reference as read, with `id`.
	 * @param bool $reachable Whether the source answered.
	 * @param int $status The HTTP status, 0 without an answer.
	 * @param string $checkedAt When it was checked (ISO 8601).
	 *
	 * @return bool Whether it was written.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function recordCheck(array $reference, bool $reachable, int $status, string $checkedAt): bool {
		$scope = $this->scope();
		$id = (string)($reference['id'] ?? '');
		if ($scope === null || $id === '') {
			return false;
		}

		$data = $reference;
		unset($data['id'], $data['@self']);
		$data['reachable'] = $reachable;
		$data['lastStatus'] = $status;
		$data['lastCheckedAt'] = $checkedAt;

		try {
			$this->openRegister->objectService()->saveObject(
				object: $data,
				register: $scope['register'],
				schema: $scope['schema'],
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DocumentReferences] the check of reference ' . $id . ' could not be saved: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end recordCheck()

	/**
	 * Whether a reference is listable and inside its window.
	 *
	 * @param array<string, mixed> $reference The reference.
	 *
	 * @return bool True when public.
	 */
	private function isPublic(array $reference): bool {
		return $this->mapper->isListable(reference: $reference) === true && $this->isObjectPublished(objectData: $reference) === true;
	}//end isPublic()

	/**
	 * One page of references on the server's behalf.
	 *
	 * @param array<string, mixed> $filters Property filters.
	 * @param int $page The 1-based page.
	 *
	 * @return list<array<string, mixed>> The references, each with `id`.
	 */
	private function search(array $filters, int $page): array {
		$scope = $this->scope();
		if ($scope === null) {
			return [];
		}

		try {
			$result = $this->openRegister->objectService()->searchObjectsPaginated(
				query: array_merge(['@self' => $scope, '_limit' => self::PAGE_SIZE, '_page' => $page], $filters),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DocumentReferences] the references could not be read: ' . $e->getMessage());
			return [];
		}

		return array_values(
			array_filter(array_map(fn (mixed $row): array => $this->asRecord(object: $row), array_values((array)($result['results'] ?? []))))
		);
	}//end search()

	/**
	 * The register and schema, or null when either is not configured or OpenRegister is missing.
	 *
	 * @return array{register: string, schema: string}|null The scope.
	 */
	private function scope(): ?array {
		$register = trim($this->config->getValueString('opencatalogi', self::REGISTER_KEY, ''));
		$schema = trim($this->config->getValueString('opencatalogi', self::SCHEMA_KEY, ''));
		if ($register === '' || $schema === '' || $this->openRegister->isInstalled() === false) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end scope()
}//end class
