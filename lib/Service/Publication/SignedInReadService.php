<?php

/**
 * OpenCatalogi SignedInReadService.
 *
 * The trusted read of a publication limited to signed-in readers
 * (publications-for-signed-in-readers, REQ-PSR-003). A portal that holds a
 * feed consumer key with `actForSignedInReaders`, for the publication's own
 * catalogue, sends the key and the login level of its reader
 * (`X-Reader-Assurance`). When that level is at least the publication's
 * `minimumAssurance` and the publication's window is open, the one object is
 * read with RBAC off and the read is written to OpenRegister's audit trail.
 * Every other request gets nothing, so the caller answers 404, the same as for
 * a publication that does not exist.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-trusted-portal-reads-it-for-a-reader-at-the-required-level-req-psr-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a signed-in publication for a trusted portal, or refuses.
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-trusted-portal-reads-it-for-a-reader-at-the-required-level-req-psr-003
 */
class SignedInReadService {

	/**
	 * The request header that carries the reader's login level.
	 */
	public const ASSURANCE_HEADER = 'X-Reader-Assurance';

	/**
	 * The audit trail action of a trusted read.
	 */
	public const AUDIT_ACTION = 'read.signedInReader';

	/**
	 * The login levels, lowest first, with their rank.
	 */
	public const LEVELS = ['substantial' => 1, 'high' => 2];

	/**
	 * OpenRegister's audit trail mapper, resolved lazily: OpenRegister is optional.
	 */
	private const AUDIT_MAPPER = 'OCA\OpenRegister\Db\AuditTrailMapper';

	/**
	 * Constructor.
	 *
	 * @param FeedConsumerServiceInterface     $consumers    Checks the key.
	 * @param SettingsServiceInterface         $settings     OpenRegister's ObjectService.
	 * @param PublicationQueryServiceInterface $publications The publication window.
	 * @param ContainerInterface               $container    Resolves the audit trail mapper.
	 * @param LoggerInterface                  $logger       Records a refused or unaudited read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FeedConsumerServiceInterface $consumers,
		private readonly SettingsServiceInterface $settings,
		private readonly PublicationQueryServiceInterface $publications,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The publication for a trusted portal's signed-in reader, or null.
	 *
	 * @param string                 $catalogId The catalogue the URL names.
	 * @param array<int, int|string> $registers The catalogue's registers.
	 * @param array<int, int|string> $schemas   The catalogue's schemas.
	 * @param string                 $id        The publication's UUID.
	 * @param string|null            $key       The feed consumer key the request carries.
	 * @param string|null            $level     The reader's login level the portal sends.
	 *
	 * @return object|null The publication's ObjectEntity, read and audited; null for every refusal.
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-trusted-portal-reads-it-for-a-reader-at-the-required-level-req-psr-003
	 */
	public function read(string $catalogId, array $registers, array $schemas, string $id, ?string $key, ?string $level): ?object {
		$level = strtolower(trim((string)$level));
		if ($key === null || $key === '' || isset(self::LEVELS[$level]) === false || $catalogId === '' || $id === '') {
			return null;
		}

		$consumer = $this->trustedConsumer(key: $key, catalogId: $catalogId);
		if ($consumer === null) {
			return null;
		}

		$object = $this->find(registers: $registers, schemas: $schemas, id: $id);
		if ($object === null || $this->admits(publication: $this->data(object: $object), level: $level) === false) {
			return null;
		}

		if ($this->audit(object: $object, consumer: $consumer, level: $level) === false) {
			return null;
		}

		return $object;
	}//end read()

	/**
	 * Whether a publication may be served at a login level: it is limited to
	 * signed-in readers, its window is open, and the level reaches its minimum.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string               $level       The reader's level, a key of LEVELS.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-trusted-portal-reads-it-for-a-reader-at-the-required-level-req-psr-003
	 */
	public function admits(array $publication, string $level): bool {
		if (($publication['audience'] ?? null) !== PublicationValidationService::SIGNED_IN || isset(self::LEVELS[$level]) === false) {
			return false;
		}

		// The window is the public one; only the audience differs.
		if ($this->publications->isObjectPublic(objectData: ['audience' => 'public'] + $publication) === false) {
			return false;
		}

		$minimum = (string)($publication['minimumAssurance'] ?? 'substantial');
		if (isset(self::LEVELS[$minimum]) === false) {
			// An unknown minimum is read as the highest: refuse rather than guess.
			$minimum = 'high';
		}

		return self::LEVELS[$level] >= self::LEVELS[$minimum];
	}//end admits()

	/**
	 * The consumer behind a key that may act for signed-in readers of this catalogue.
	 *
	 * @param string $key       The key as sent.
	 * @param string $catalogId The catalogue.
	 *
	 * @return array<string, mixed>|null The consumer, or null.
	 */
	private function trustedConsumer(string $key, string $catalogId): ?array {
		try {
			$match = $this->consumers->authenticate($key);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogi: a signed-in read key could not be checked', ['reason' => $e->getMessage()]);
			return null;
		}

		$consumer = ($match['consumer'] ?? null);
		if (is_array($consumer) === false
			|| (string)($consumer['catalog'] ?? '') !== $catalogId
			|| ($consumer['actForSignedInReaders'] ?? false) !== true
		) {
			return null;
		}

		return $consumer;
	}//end trustedConsumer()

	/**
	 * The publication by UUID in the catalogue's registers and schemas, RBAC off.
	 *
	 * @param array<int, int|string> $registers The registers.
	 * @param array<int, int|string> $schemas   The schemas.
	 * @param string                 $id        The UUID.
	 *
	 * @return object|null
	 */
	private function find(array $registers, array $schemas, string $id): ?object {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return null;
		}

		foreach (array_filter($registers, 'is_numeric') as $register) {
			foreach (array_filter($schemas, 'is_numeric') as $schema) {
				try {
					$object = $objectService->find(
						id: $id,
						options: new ObjectFindOptions(register: (int)$register, schema: (int)$schema),
						_rbac: false,
						_multitenancy: false
					);
				} catch (Throwable $e) {
					$object = null;
				}

				if (is_object($object) === true) {
					return $object;
				}
			}
		}

		return null;
	}//end find()

	/**
	 * Write the trusted read to OpenRegister's audit trail.
	 *
	 * A read that cannot be audited is not served.
	 *
	 * @param object               $object   The publication's ObjectEntity.
	 * @param array<string, mixed> $consumer The consumer.
	 * @param string               $level    The reader's level.
	 *
	 * @return bool Whether the entry was written.
	 */
	private function audit(object $object, array $consumer, string $level): bool {
		try {
			$this->container->get(self::AUDIT_MAPPER)->createAuditTrailEntry(
				object: $object,
				action: self::AUDIT_ACTION,
				context: [
					'consumer' => (string)($consumer['id'] ?? ($consumer['@self']['id'] ?? '')),
					'consumerName' => (string)($consumer['name'] ?? ''),
					'publication' => (string)$object->getUuid(),
					'level' => $level,
					'at' => gmdate('c'),
				],
				actorId: (string)($consumer['account'] ?? ''),
				actorName: (string)($consumer['name'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: a signed-in read could not be audited and was refused', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end audit()

	/**
	 * The object's own fields.
	 *
	 * @param object $object The ObjectEntity.
	 *
	 * @return array<string, mixed>
	 */
	private function data(object $object): array {
		if (method_exists($object, 'getObject') === true) {
			return (array)($object->getObject() ?? []);
		}

		return [];
	}//end data()
}//end class
