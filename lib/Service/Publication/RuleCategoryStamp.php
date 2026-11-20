<?php

/**
 * OpenCatalogi Rule Category Stamp.
 *
 * Decides which Woo information category a record is filed under when a
 * configured published collection publishes it. The category sitemaps search
 * stored records on `wooCategory`, so a record a rule publishes only lands in
 * its category's sitemap when the category is written on the record itself.
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
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;

/**
 * The category a published collection files a record under.
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 */
class RuleCategoryStamp {

	/**
	 * Constructor.
	 *
	 * @param PublishedCollectionsServiceInterface $collections The configured published collections.
	 * @param PublicationRuleServiceInterface $rules Evaluates a collection's conditions.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublishedCollectionsServiceInterface $collections,
		private readonly PublicationRuleServiceInterface $rules,
	) {

	}//end __construct()

	/**
	 * The record types the configured collections govern, in configuration order and without repeats.
	 *
	 * @return array<int, string> The record types.
	 *
	 * @throws UnreadableRuleException When the configuration cannot be read.
	 *
	 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
	 */
	public function recordTypes(): array {
		$types = [];
		foreach ($this->collections->collections() as $collection) {
			$type = trim((string)($collection['recordType'] ?? ''));
			if ($type !== '' && in_array($type, $types, true) === false) {
				$types[] = $type;
			}
		}

		return $types;

	}//end recordTypes()

	/**
	 * The category to write on a record, or null when nothing is to be written.
	 *
	 * The first collection that governs one of the record's type names,
	 * publishes the record and names a served category decides it, as it does
	 * for the record's anonymous view. Nothing is written when the record
	 * already carries that category.
	 *
	 * @param array<string, mixed> $record The record as it would be written.
	 * @param array<int, string> $typeNames The names the record's schema goes by, such as its id and its slug.
	 *
	 * @return string|null The category code, such as `infocat004`.
	 *
	 * @throws UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
	 */
	public function categoryFor(array $record, array $typeNames): ?string {
		foreach ($this->collections->collections() as $collection) {
			$category = $this->categoryUnder(record: $record, typeNames: $typeNames, collection: $collection);
			if ($category === null) {
				continue;
			}

			if ($category === ($record['wooCategory'] ?? null)) {
				return null;
			}

			return $category;
		}

		return null;

	}//end categoryFor()

	/**
	 * The category one collection files the record under, or null when it does not publish it.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param array<int, string> $typeNames The names the record's schema goes by.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string|null The category code.
	 *
	 * @throws UnreadableRuleException When a condition cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PublicationRuleService::categoryOf() is a pure static function.
	 */
	private function categoryUnder(array $record, array $typeNames, array $collection): ?string {
		$type = trim((string)($collection['recordType'] ?? ''));
		if ($type === '' || in_array($type, $typeNames, true) === false) {
			return null;
		}

		// A configured collection publishes; `enabled` is a property of a rule, not of the set.
		$collection['enabled'] = true;
		$collection['recordType'] = $type;
		$typed = $record;
		$typed['@type'] = $type;
		$typed['recordType'] = $type;
		if ($this->rules->publishes(record: $typed, rule: $collection) === false) {
			return null;
		}

		return PublicationRuleService::categoryOf(rule: $collection);

	}//end categoryUnder()
}//end class
