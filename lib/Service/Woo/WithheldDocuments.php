<?php

/**
 * Records which documents of a Woo decision were withheld, and on which grounds.
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
use Throwable;

/**
 * The one door dossiq records a decision's withheld documents through.
 *
 * Replace semantics: a call states the whole list for a publication, so the
 * stored entries are removed and the accepted ones written. A call with no
 * entries clears the list (a withdrawal). Each entry is exactly
 * `{position, grounds}`; every other key, a title first of all, is dropped
 * before anything is stored, so this path cannot leak what was withheld.
 *
 * The grounds are looked up in dossiq's own list (`WooRefusalGrounds::byCode()`)
 * and stored as dossiq answered, so the public read never guesses a label.
 * When that list cannot be reached, nothing is stored or removed and the
 * vendored snapshot is NOT read: a label from a stale copy is worse than none.
 */
class WithheldDocuments {

	/**
	 * dossiq's class that owns the refusal grounds list.
	 *
	 * @var string
	 */
	public const GROUNDS_CLASS = 'OCA\Dossiq\Woo\WooRefusalGrounds';

	/**
	 * The exception dossiq throws when its list cannot be read.
	 *
	 * @var string
	 */
	public const GROUNDS_UNAVAILABLE_CLASS = 'OCA\Dossiq\Woo\WooRefusalGroundsUnavailable';

	/**
	 * The keys an entry is reduced to before it is read.
	 *
	 * @var list<string>
	 */
	public const ENTRY_KEYS = ['position', 'grounds'];

	/**
	 * The keys stored per ground, read from dossiq's `byCode()` answer.
	 *
	 * @var list<string>
	 */
	public const GROUND_KEYS = ['code', 'article', 'label'];

	/**
	 * The keys of the answer.
	 *
	 * @var list<string>
	 */
	public const ANSWER_KEYS = ['recorded', 'refused'];

	/**
	 * The keys of one refused item.
	 *
	 * @var list<string>
	 */
	public const REFUSED_KEYS = ['position', 'code', 'reason'];

	public const REASON_UNKNOWN_GROUND = 'unknown-ground';

	public const REASON_GROUNDS_UNAVAILABLE = 'grounds-unavailable';

	public const REASON_NO_PUBLICATION = 'no-publication';

	public const REASON_INVALID_ENTRY = 'invalid-entry';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     Holds the register and schema ids.
	 * @param ContainerInterface $container  Resolves OpenRegister's ObjectService and dossiq's list.
	 * @param IAppManager        $appManager Says whether OpenRegister and dossiq are there.
	 * @param LoggerInterface    $logger     Records a store failure.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Replace the withheld documents recorded for a publication.
	 *
	 * @param string                           $publicationId The publication of the Woo decision.
	 * @param array<int, array<string, mixed>> $entries       One `{position, grounds}` per withheld document.
	 * @param string                           $source        The recording app, such as `dossiq`.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function record(string $publicationId, array $entries, string $source): array {
		$entries = array_map(fn (mixed $entry): array => $this->reduce(entry: $entry), array_values($entries));

		$objects = $this->objects();
		if ($objects === null || $this->publicationExists(objects: $objects, publicationId: $publicationId) === false) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_NO_PUBLICATION);
		}

		$grounds = $this->grounds();
		if ($grounds === null && $entries !== []) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_GROUNDS_UNAVAILABLE);
		}

		$accepted = [];
		$refused = [];
		try {
			foreach ($entries as $entry) {
				$resolved = $this->resolve(grounds: $grounds, entry: $entry);
				if (isset($resolved['refused']) === true) {
					$refused[] = $resolved['refused'];
					continue;
				}

				$accepted[] = $resolved['entry'];
			}
		} catch (Throwable $e) {
			if ($this->isUnavailable(error: $e) === false) {
				throw $e;
			}

			return $this->refuseAll(entries: $entries, reason: self::REASON_GROUNDS_UNAVAILABLE);
		}

		$this->replace(objects: $objects, publicationId: $publicationId, accepted: $accepted, source: $source);

		return ['recorded' => count($accepted), 'refused' => $refused];
	}//end record()

	/**
	 * Reduce an entry to `{position, grounds}`; a title or anything else is dropped here.
	 *
	 * @param mixed $entry The raw entry.
	 *
	 * @return array{position: int, grounds: list<string>}
	 */
	private function reduce(mixed $entry): array {
		if (is_array($entry) === false) {
			return ['position' => 0, 'grounds' => []];
		}

		$grounds = [];
		foreach ((array)($entry['grounds'] ?? []) as $code) {
			if (is_string($code) === true && trim($code) !== '') {
				$grounds[] = trim($code);
			}
		}

		$position = $entry['position'] ?? 0;

		return ['position' => is_numeric($position) === true ? (int)$position : 0, 'grounds' => $grounds];
	}//end reduce()

	/**
	 * Resolve one entry's codes through dossiq's list.
	 *
	 * @param object|null                                 $grounds dossiq's WooRefusalGrounds.
	 * @param array{position: int, grounds: list<string>} $entry   The reduced entry.
	 *
	 * @return array<string, mixed> `['entry' => ...]` or `['refused' => ...]`.
	 */
	private function resolve(?object $grounds, array $entry): array {
		if ($entry['position'] < 1 || $entry['grounds'] === [] || $grounds === null) {
			return ['refused' => ['position' => $entry['position'], 'code' => (string)($entry['grounds'][0] ?? ''), 'reason' => self::REASON_INVALID_ENTRY]];
		}

		$stored = [];
		foreach ($entry['grounds'] as $code) {
			$ground = $grounds->byCode($code);
			if (is_array($ground) === false) {
				return ['refused' => ['position' => $entry['position'], 'code' => $code, 'reason' => self::REASON_UNKNOWN_GROUND]];
			}

			$stored[] = [
				'code' => (string)($ground['code'] ?? $code),
				'article' => (string)($ground['article'] ?? ''),
				'label' => (string)($ground['label'] ?? ''),
			];
		}

		return ['entry' => ['position' => $entry['position'], 'grounds' => $stored]];
	}//end resolve()

	/**
	 * Remove the stored entries of the publication and write the accepted ones.
	 *
	 * @param object                                         $objects       OpenRegister's ObjectService.
	 * @param string                                         $publicationId The publication.
	 * @param list<array{position: int, grounds: list<array<string, string>>}> $accepted The entries to store.
	 * @param string                                         $source        The recording app.
	 *
	 * @return void
	 */
	private function replace(object $objects, string $publicationId, array $accepted, string $source): void {
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
	 * @param object $objects       OpenRegister's ObjectService.
	 * @param string $publicationId The publication.
	 *
	 * @return bool
	 */
	private function publicationExists(object $objects, string $publicationId): bool {
		if (trim($publicationId) === '') {
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
	 * dossiq's grounds list, or null when dossiq or the class is not there.
	 *
	 * @return object|null
	 */
	private function grounds(): ?object {
		if ($this->appManager->isInstalled('dossiq') === false || class_exists(self::GROUNDS_CLASS) === false) {
			return null;
		}

		try {
			$grounds = $this->container->get(self::GROUNDS_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		return is_object($grounds) === true && method_exists($grounds, 'byCode') === true ? $grounds : null;
	}//end grounds()

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

		return is_object($objects) === true ? $objects : null;
	}//end objects()

	/**
	 * The publication register and a schema id from the app config.
	 *
	 * @param string $key The schema config key.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws \RuntimeException When either is not configured.
	 */
	private function target(string $key): array {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', $key, ''));
		if ($register === '' || $schema === '') {
			throw new \RuntimeException('Register configuration is not set for publication_register / ' . $key . '.');
		}

		return [$register, $schema];
	}//end target()

	/**
	 * Whether an error is dossiq saying its list cannot be read.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return bool
	 */
	private function isUnavailable(Throwable $error): bool {
		$class = self::GROUNDS_UNAVAILABLE_CLASS;

		return class_exists($class) === true && $error instanceof $class;
	}//end isUnavailable()

	/**
	 * Refuse every entry with one reason.
	 *
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 * @param string                                            $reason  The reason.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}
	 */
	private function refuseAll(array $entries, string $reason): array {
		$refused = [];
		foreach ($entries as $entry) {
			$refused[] = ['position' => $entry['position'], 'code' => (string)($entry['grounds'][0] ?? ''), 'reason' => $reason];
		}

		return ['recorded' => 0, 'refused' => $refused];
	}//end refuseAll()

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
