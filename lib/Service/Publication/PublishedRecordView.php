<?php

/**
 * OpenCatalogi Published Record View.
 *
 * Applies the configured published collections to stored records where an
 * anonymous reader reads them: a record of a covered type is public only when
 * it meets its collection's conditions, and then carries only the properties
 * in the collection's anonymous set. The same view backs the API read and the
 * public search, so a property withheld from one is withheld from the other.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;

/**
 * The anonymous view of stored records under the configured published collections.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
 */
class PublishedRecordView implements PublishedRecordViewInterface {

	/**
	 * How many stored records one collection contributes to a search.
	 *
	 * @var integer
	 */
	public const SEARCH_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param PublishedCollectionsServiceInterface $collections The configured published collections.
	 * @param PublicationRuleServiceInterface $rules Evaluates a collection's conditions and its anonymous set.
	 * @param OpenRegisterLookupInterface $openRegister Reaches OpenRegister's ObjectService for the stored records.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublishedCollectionsServiceInterface $collections,
		private readonly PublicationRuleServiceInterface $rules,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * A stored record as an anonymous reader may read it under the configured published collections.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return array<string, mixed>|null The anonymous view, or null when the record is not public.
	 *
	 * @throws UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function view(array $record): ?array {
		$byType = $this->rules->indexByRecordType(rules: $this->collections->collections());
		$collection = ($byType[self::recordTypeOf(record: $record)] ?? null);
		if ($collection === null) {
			// No collection covers this type, so the configuration decides nothing about it.
			return $record;
		}

		return $this->viewUnder(record: $record, collection: $collection);

	}//end view()

	/**
	 * The anonymous views of a list of stored records, leaving out every record that is not public.
	 *
	 * @param array<int, mixed> $records The stored records; an entity is read through its serialised
	 *                                   properties, anything else that is not an array is left out.
	 *
	 * @return array<int, array<string, mixed>> The anonymous views, in the order given.
	 *
	 * @throws UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function viewList(array $records): array {
		$views = [];
		foreach (self::asRecords(objects: $records) as $record) {
			$view = $this->view(record: $record);
			if ($view !== null) {
				$views[] = $view;
			}
		}

		return $views;

	}//end viewList()

	/**
	 * Search the stored records of every configured published collection in plain words.
	 *
	 * @param string $terms The words searched for.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @throws UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	public function searchStored(string $terms): array {
		$found = [];
		foreach ($this->collections->collections() as $collection) {
			$found = array_merge($found, $this->searchCollection(collection: $collection, terms: trim($terms)));
		}

		return $found;

	}//end searchStored()

	/**
	 * The stored records of one collection that it publishes and that hold the search words.
	 *
	 * The collection read these records, so it is the collection that governs
	 * them, whatever type the record itself names. A collection that names no
	 * register is not searched.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string $terms The trimmed search words.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @throws UnreadableRuleException When a condition cannot be read.
	 */
	private function searchCollection(array $collection, string $terms): array {
		$register = trim((string)($collection['register'] ?? ''));
		$collection['recordType'] = trim((string)($collection['recordType'] ?? ''));
		if ($register === '' || $collection['recordType'] === '') {
			return [];
		}

		$found = [];
		foreach ($this->storedRecords(register: $register, schema: $collection['recordType'], terms: $terms) as $record) {
			$view = $this->matchingView(record: $record, collection: $collection, needle: mb_strtolower($terms));
			if ($view !== null) {
				$found[] = $view;
			}
		}

		return $found;

	}//end searchCollection()

	/**
	 * A record's anonymous view when its collection publishes it and the view holds the search words.
	 *
	 * @param array<string, mixed> $record The stored record.
	 * @param array<string, mixed> $collection The collection that read it.
	 * @param string $needle The lower-cased search words.
	 *
	 * @return array<string, mixed>|null The view, or null.
	 *
	 * @throws UnreadableRuleException When a condition cannot be read.
	 */
	private function matchingView(array $record, array $collection, string $needle): ?array {
		$view = $this->viewUnder(record: $record, collection: $collection);
		if ($view === null || self::matches(view: $view, needle: $needle) === false) {
			return null;
		}

		return $view;

	}//end matchingView()

	/**
	 * A record under one collection: null when it fails the conditions, else its anonymous view.
	 *
	 * @param array<string, mixed> $record The stored record.
	 * @param array<string, mixed> $collection The collection that covers its type.
	 *
	 * @return array<string, mixed>|null The view, or null when the record is not public.
	 *
	 * @throws UnreadableRuleException When a condition cannot be read.
	 */
	private function viewUnder(array $record, array $collection): ?array {
		// A configured collection publishes; `enabled` is a property of a rule, not of the set.
		$collection['enabled'] = true;
		// The rule evaluator reads `@type` before `recordType`; both name the collection's type.
		$typed = $record;
		$typed['@type'] = (string)$collection['recordType'];
		$typed['recordType'] = $typed['@type'];
		if ($this->rules->publishes(record: $typed, rule: $collection) === false) {
			return null;
		}

		return array_merge(
			self::envelopeOf(record: $record),
			$this->rules->projectForAnonymous(record: $record, rule: $collection),
			self::dossierOf(record: $record)
		);

	}//end viewUnder()

	/**
	 * The record's `id` and `@self`, which every view keeps.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private static function envelopeOf(array $record): array {
		$envelope = [];
		foreach (['id', '@self'] as $key) {
			if (array_key_exists($key, $record) === true) {
				$envelope[$key] = $record[$key];
			}
		}

		return $envelope;

	}//end envelopeOf()

	/**
	 * The dossier the record names, which every view names too.
	 *
	 * A document read out of its dossier is the commonest way a published
	 * answer is read wrongly (D11). It is the record's reference, never the
	 * dossier's own contents.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return array<string, mixed> The dossier reference, or nothing.
	 */
	private static function dossierOf(array $record): array {
		if (($record['dossier'] ?? null) === null) {
			return [];
		}

		return ['dossier' => $record['dossier']];

	}//end dossierOf()

	/**
	 * The stored records of one register and schema, narrowed by the search words.
	 *
	 * OpenRegister's own access rules still apply to the read; this only narrows
	 * further. OpenRegister not installed reads no records.
	 *
	 * @param string $register The collection's register.
	 * @param string $schema The collection's record type, as the schema.
	 * @param string $terms The search words, or empty for every record.
	 *
	 * @return array<int, array<string, mixed>> The stored records.
	 */
	private function storedRecords(string $register, string $schema, string $terms): array {
		if ($this->openRegister->isInstalled() === false) {
			return [];
		}

		$query = ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::SEARCH_LIMIT];
		if ($terms !== '') {
			$query['_search'] = $terms;
		}

		return self::asRecords(
			objects: $this->openRegister->objectService()->searchObjects(query: $query, _rbac: true, _multitenancy: false)
		);

	}//end storedRecords()

	/**
	 * Records as arrays: an entity is read through its serialised properties, anything else left out.
	 *
	 * @param iterable<mixed> $objects The records or entities.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private static function asRecords(iterable $objects): array {
		$records = [];
		foreach ($objects as $object) {
			if ($object instanceof \JsonSerializable) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$records[] = $object;
			}
		}

		return $records;

	}//end asRecords()

	/**
	 * Whether a view's anonymous properties hold the search words.
	 *
	 * The `@self` envelope is left out of the match: the words are searched in
	 * what the collection publishes, not in the record's metadata.
	 *
	 * @param array<string, mixed> $view The anonymous view.
	 * @param string $needle The lower-cased search words; empty matches every view.
	 *
	 * @return boolean True when the view matches.
	 */
	private static function matches(array $view, string $needle): bool {
		if ($needle === '') {
			return true;
		}

		unset($view['@self']);

		return str_contains(mb_strtolower((string)json_encode($view, JSON_UNESCAPED_UNICODE)), $needle);

	}//end matches()

	/**
	 * The record type a stored record names: its `@type`, its `recordType`, else its schema.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return string The record type, or an empty string.
	 */
	private static function recordTypeOf(array $record): string {
		$type = ($record['@type'] ?? ($record['recordType'] ?? null));
		if ($type === null && is_array($record['@self'] ?? null) === true) {
			$type = ($record['@self']['schema'] ?? null);
		}

		if (is_scalar($type) === false) {
			return '';
		}

		return trim((string)$type);

	}//end recordTypeOf()
}//end class
