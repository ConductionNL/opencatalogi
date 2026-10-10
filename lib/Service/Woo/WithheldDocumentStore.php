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
use OCP\App\IAppManager;
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
	 * Remove the stored entries of the publication and write the accepted ones.
	 *
	 * @param string                                                           $publicationId The publication.
	 * @param list<array{position: int, grounds: list<array<string, string>>}> $accepted      The entries to store.
	 * @param string                                                           $source        The recording app.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister or the register configuration is not there.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function replace(string $publicationId, array $accepted, string $source): void {
		$objects = $this->requireObjects();
		[$register, $schema] = $this->target(key: 'withheld_document_schema');

		$result = $objects->searchObjectsPaginated(
			query: ['@self' => ['register' => $register, 'schema' => $schema], 'publication' => $publicationId, '_limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);
		foreach ((array)($result['results'] ?? []) as $row) {
			$stored = $this->asArray(object: $row);
			if ((string)($stored['publication'] ?? '') !== $publicationId) {
				continue;
			}

			$objects->deleteObject(uuid: (string)($stored['id'] ?? ''), register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		}

		$now = (new DateTimeImmutable())->format('c');
		foreach ($accepted as $entry) {
			$objects->saveObject(
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
		}
	}//end replace()

	/**
	 * Whether the publication exists.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function publicationExists(string $publicationId): bool {
		$objects = $this->objects();
		if ($objects === null || trim($publicationId) === '') {
			return false;
		}

		try {
			[$register, $schema] = $this->target(key: 'publication_schema');
			$found = $objects->find(id: $publicationId, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return false;
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

	/**
	 * An OpenRegister object as a flat array with its id.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$object = $object['object'];
		}

		$object['id'] = $id;

		return $object;
	}//end asArray()
}//end class
