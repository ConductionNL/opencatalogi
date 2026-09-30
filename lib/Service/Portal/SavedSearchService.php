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

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\WooCategory;

/**
 * Keeps a resident's saved searches.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */
class SavedSearchService {

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
	 * @param string               $owner The subject reference.
	 * @param array<string, mixed> $input title, query (object or JSON), frequency.
	 *
	 * @return array<string, mixed> The saved search.
	 *
	 * @throws PortalInputException When the input is refused.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function save(string $owner, array $input): array {
		$title = $input['title'] ?? '';
		if (is_string($title) === false || trim($title) === '' || mb_strlen(trim($title)) > 200) {
			throw new PortalInputException('A saved search needs a title of at most 200 characters');
		}

		$frequency = ($input['frequency'] ?? '');
		if ($frequency === '' || $frequency === null) {
			$frequency = self::FREQUENCIES[0];
		}

		if (in_array($frequency, self::FREQUENCIES, true) === false) {
			throw new PortalInputException('frequency must be immediate, daily or weekly');
		}

		$query = $this->query(value: ($input['query'] ?? null));

		if (count($this->store->findByOwner(schema: self::SCHEMA, owner: $owner)) >= self::MAX_PER_OWNER) {
			throw new PortalInputException('You have the most saved searches you can have');
		}

		return $this->store->save(
			schema: self::SCHEMA,
			data: [
				'title' => trim($title),
				'owner' => $owner,
				'query' => $query,
				'frequency' => $frequency,
				'active' => true,
			]
		);

	}//end save()

	/**
	 * Stop the notices of a saved search: one click from the notice.
	 *
	 * @param string $owner         The subject reference.
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
	 * @param string $owner         The subject reference.
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
	 * @param string $owner         The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PortalNotFoundException Otherwise.
	 */
	private function owned(string $owner, string $savedSearchId): array {
		$saved = $this->store->find(schema: self::SCHEMA, id: $savedSearchId);
		if ($owner === '' || $saved === null || ($saved['owner'] ?? null) !== $owner) {
			throw new PortalNotFoundException('Not found');
		}

		return $saved;

	}//end owned()

	/**
	 * The query in the contract's shape, with every key present.
	 *
	 * @param mixed $value An array, or the same as JSON.
	 *
	 * @return array{text: string, filters: array{informatiecategorie: array<int, string>, organisation: array<int, string>, periodFrom: string, periodTo: string}, catalog: string}
	 *
	 * @throws PortalInputException When it is not the contract's shape.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One check per field of the contract shape.
	 */
	private function query(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			throw new PortalInputException('query must be an object');
		}

		$filters = ($value['filters'] ?? []);
		if (is_array($filters) === false) {
			throw new PortalInputException('query.filters must be an object');
		}

		$text = $this->string(value: ($value['text'] ?? ''), max: 500, field: 'query.text');
		$catalog = $this->string(value: ($value['catalog'] ?? ''), max: 255, field: 'query.catalog');
		$categories = $this->list(value: ($filters['informatiecategorie'] ?? []), field: 'informatiecategorie');
		foreach ($categories as $category) {
			if (array_key_exists($category, WooCategory::ALL) === false) {
				throw new PortalInputException('Unknown information category: '.$category);
			}
		}

		$period = [];
		foreach (['periodFrom', 'periodTo'] as $bound) {
			$period[$bound] = $this->string(value: ($filters[$bound] ?? ''), max: 10, field: $bound);
			if ($period[$bound] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period[$bound]) !== 1) {
				throw new PortalInputException($bound.' must be a date like 2026-01-31');
			}
		}

		return [
			'text' => $text,
			'filters' => [
				'informatiecategorie' => $categories,
				'organisation' => $this->list(value: ($filters['organisation'] ?? []), field: 'organisation'),
				'periodFrom' => $period['periodFrom'],
				'periodTo' => $period['periodTo'],
			],
			'catalog' => $catalog,
		];

	}//end query()

	/**
	 * A trimmed string within a length.
	 *
	 * @param mixed  $value The value.
	 * @param int    $max   The most characters.
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
			throw new PortalInputException($field.' must be text of at most '.$max.' characters');
		}

		return trim($value);

	}//end string()

	/**
	 * A list of short strings.
	 *
	 * @param mixed  $value The value.
	 * @param string $field The field, for the message.
	 *
	 * @return array<int, string>
	 *
	 * @throws PortalInputException Otherwise.
	 */
	private function list(mixed $value, string $field): array {
		if (is_array($value) === false || array_is_list($value) === false || count($value) > 50) {
			throw new PortalInputException($field.' must be a list');
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false || $entry === '' || mb_strlen($entry) > 255) {
				throw new PortalInputException($field.' must hold text values');
			}

			$out[] = $entry;
		}

		return $out;

	}//end list()
}//end class
