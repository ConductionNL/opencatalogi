<?php

/**
 * OpenCatalogi Saved Search Service.
 *
 * Saves, pauses and deletes a resident's saved searches (hydra
 * `woo-citizen-journey`, contract C2). The owner is the subject reference from
 * portaliq's verified assertion; another subject's saved search answers 404.
 * The job-written fields (`lastRunAt`, `lastNotifiedAt`, `lastMatches`,
 * `matchCount`) are never taken from a request.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCA\OpenCatalogi\Contract\SavedSearchServiceInterface;
use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\WooCategory;

/**
 * Keeps a resident's saved searches.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */
class SavedSearchService implements SavedSearchServiceInterface {

	/**
	 * The saved-search schema.
	 */
	public const SCHEMA = 'savedSearch';

	/**
	 * The frequencies, the first one the default.
	 */
	public const FREQUENCIES = ['daily', 'immediate', 'weekly'];

	/**
	 * The most saved searches one resident keeps.
	 */
	public const MAX_PER_OWNER = 25;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore $store Reads and writes as the system.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
	) {

	}//end __construct()

	/**
	 * Save a search for this resident.
	 *
	 * @param string $owner The subject reference.
	 * @param array<string, mixed> $input title, query (object or JSON), frequency.
	 *
	 * @return array<string, mixed> The saved search.
	 *
	 * @throws PortalInputException When the input is refused.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function save(string $owner, array $input): array {
		$title = $this->title(input: $input);
		$frequency = $this->frequency(input: $input);
		$query = $this->query(value: ($input['query'] ?? null));
		$this->assertRoomFor(owner: $owner);

		return $this->store->save(
			schema: self::SCHEMA,
			data: [
				'title' => $title,
				'owner' => $owner,
				'query' => $query,
				'frequency' => $frequency,
				'active' => true,
			]
		);

	}//end save()

	/**
	 * The saved search's title, trimmed.
	 *
	 * @param array<string, mixed> $input The input.
	 *
	 * @return string The title.
	 *
	 * @throws PortalInputException When it is missing, not text or longer than 200 characters.
	 */
	private function title(array $input): string {
		$title = $input['title'] ?? '';
		if (is_string($title) === false || trim($title) === '' || mb_strlen(trim($title)) > 200) {
			throw new PortalInputException(message: 'A saved search needs a title of at most 200 characters');
		}

		return trim($title);
	}//end title()

	/**
	 * The notice frequency, the first one when none is given.
	 *
	 * @param array<string, mixed> $input The input.
	 *
	 * @return string The frequency.
	 *
	 * @throws PortalInputException When it is not one of the frequencies.
	 */
	private function frequency(array $input): string {
		$frequency = ($input['frequency'] ?? '');
		if ($frequency === '') {
			$frequency = self::FREQUENCIES[0];
		}

		if (in_array($frequency, self::FREQUENCIES, true) === false) {
			throw new PortalInputException(message: 'frequency must be immediate, daily or weekly');
		}

		return $frequency;
	}//end frequency()

	/**
	 * Refuse a new saved search for an owner who has the most there can be.
	 *
	 * @param string $owner The subject reference.
	 *
	 * @return void
	 *
	 * @throws PortalInputException When the owner has no room left.
	 */
	private function assertRoomFor(string $owner): void {
		if (count($this->store->findByOwner(schema: self::SCHEMA, owner: $owner)) >= self::MAX_PER_OWNER) {
			throw new PortalInputException(message: 'You have the most saved searches you can have');
		}
	}//end assertRoomFor()

	/**
	 * Stop the notices of a saved search: one click from the notice.
	 *
	 * @param string $owner The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array{active: bool}
	 *
	 * @throws PortalNotFoundException When it is not the owner's.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function pause(string $owner, string $savedSearchId): array {
		$saved = $this->owned(owner: $owner, savedSearchId: $savedSearchId);
		$saved['active'] = false;
		$this->store->save(schema: self::SCHEMA, data: $saved, id: $savedSearchId);

		return ['active' => false];
	}//end pause()

	/**
	 * Delete a saved search.
	 *
	 * @param string $owner The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array{deleted: bool}
	 *
	 * @throws PortalNotFoundException When it is not the owner's.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function delete(string $owner, string $savedSearchId): array {
		$this->owned(owner: $owner, savedSearchId: $savedSearchId);
		return ['deleted' => $this->store->delete(schema: self::SCHEMA, id: $savedSearchId)];
	}//end delete()

	/**
	 * The saved search when this owner owns it.
	 *
	 * @param string $owner The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PortalNotFoundException Otherwise.
	 */
	private function owned(string $owner, string $savedSearchId): array {
		$saved = $this->store->find(schema: self::SCHEMA, id: $savedSearchId);
		if ($owner === '' || $saved === null || ($saved['owner'] ?? null) !== $owner) {
			throw new PortalNotFoundException(message: 'Not found');
		}

		return $saved;
	}//end owned()

	/**
	 * The query in the contract's shape, with every key present.
	 *
	 * @param mixed $value An array, or the same as JSON.
	 *
	 * @return array{
	 *     text: string,
	 *     filters: array{informatiecategorie: array<int, string>, organisation: array<int, string>, periodFrom: string, periodTo: string},
	 *     catalog: string
	 * }
	 *
	 * @throws PortalInputException When it is not the contract's shape.
	 */
	private function query(mixed $value): array {
		$query = $this->queryObject(value: $value);
		$filters = ($query['filters'] ?? []);
		if (is_array($filters) === false) {
			throw new PortalInputException(message: 'query.filters must be an object');
		}

		$text = $this->string(value: ($query['text'] ?? ''), max: 500, field: 'query.text');
		$catalog = $this->string(value: ($query['catalog'] ?? ''), max: 255, field: 'query.catalog');

		return [
			'text' => $text,
			'filters' => $this->filters(filters: $filters),
			'catalog' => $catalog,
		];

	}//end query()

	/**
	 * The query as an array, decoding it when it arrived as JSON.
	 *
	 * @param mixed $value An array, or the same as JSON.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @throws PortalInputException When it is not an object.
	 */
	private function queryObject(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			throw new PortalInputException(message: 'query must be an object');
		}

		return $value;
	}//end queryObject()

	/**
	 * The query's filters in the contract's shape: known information
	 * categories, organisations and a period of dates.
	 *
	 * @param array<string, mixed> $filters The filters as given.
	 *
	 * @return array{informatiecategorie: array<int, string>, organisation: array<int, string>, periodFrom: string, periodTo: string}
	 *
	 * @throws PortalInputException When a filter is not the contract's shape.
	 */
	private function filters(array $filters): array {
		$categories = $this->categories(filters: $filters);
		$periodFrom = $this->periodBound(filters: $filters, bound: 'periodFrom');
		$periodTo = $this->periodBound(filters: $filters, bound: 'periodTo');

		return [
			'informatiecategorie' => $categories,
			'organisation' => $this->list(value: ($filters['organisation'] ?? []), field: 'organisation'),
			'periodFrom' => $periodFrom,
			'periodTo' => $periodTo,
		];
	}//end filters()

	/**
	 * The information categories filter, each one a known category.
	 *
	 * @param array<string, mixed> $filters The filters as given.
	 *
	 * @return array<int, string> The categories.
	 *
	 * @throws PortalInputException When it is not a list of known categories.
	 */
	private function categories(array $filters): array {
		$categories = $this->list(value: ($filters['informatiecategorie'] ?? []), field: 'informatiecategorie');
		foreach ($categories as $category) {
			if (array_key_exists($category, WooCategory::ALL) === false) {
				throw new PortalInputException(message: 'Unknown information category: ' . $category);
			}
		}

		return $categories;
	}//end categories()

	/**
	 * One bound of the period filter: '' or a date like 2026-01-31.
	 *
	 * @param array<string, mixed> $filters The filters as given.
	 * @param string $bound periodFrom or periodTo.
	 *
	 * @return string The bound.
	 *
	 * @throws PortalInputException When it is not a date.
	 */
	private function periodBound(array $filters, string $bound): string {
		$date = $this->string(value: ($filters[$bound] ?? ''), max: 10, field: $bound);
		if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new PortalInputException(message: $bound . ' must be a date like 2026-01-31');
		}

		return $date;
	}//end periodBound()

	/**
	 * A trimmed string within a length.
	 *
	 * @param mixed $value The value.
	 * @param int $max The most characters.
	 * @param string $field The field, for the message.
	 *
	 * @return string
	 *
	 * @throws PortalInputException Otherwise.
	 */
	private function string(mixed $value, int $max, string $field): string {
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false || mb_strlen(trim($value)) > $max) {
			throw new PortalInputException(message: $field . ' must be text of at most ' . $max . ' characters');
		}

		return trim($value);
	}//end string()

	/**
	 * A list of short strings.
	 *
	 * @param mixed $value The value.
	 * @param string $field The field, for the message.
	 *
	 * @return array<int, string>
	 *
	 * @throws PortalInputException Otherwise.
	 */
	private function list(mixed $value, string $field): array {
		if (is_array($value) === false || array_is_list($value) === false || count($value) > 50) {
			throw new PortalInputException(message: $field . ' must be a list');
		}

		$out = [];
		foreach ($value as $entry) {
			$out[] = $this->listEntry(entry: $entry, field: $field);
		}

		return $out;
	}//end list()

	/**
	 * One entry of a list of short strings.
	 *
	 * @param mixed $entry The entry.
	 * @param string $field The field, for the message.
	 *
	 * @return string The entry.
	 *
	 * @throws PortalInputException When it is not a text of 1 to 255 characters.
	 */
	private function listEntry(mixed $entry, string $field): string {
		if (is_string($entry) === false || $entry === '' || mb_strlen($entry) > 255) {
			throw new PortalInputException(message: $field . ' must hold text values');
		}

		return $entry;
	}//end list()
}//end class
