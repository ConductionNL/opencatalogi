<?php

/**
 * OpenCatalogi Hidden Category Check.
 *
 * Counts the publications filed under an information category, so a category
 * that still has publications cannot be hidden: hiding never takes a published
 * record out of sight, it only stops new ones from being filed under it. A
 * publication may store the category as its code, its label or its TOOI URI,
 * and a local category publishes under the member it maps to, so every one of
 * those values is counted.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use Throwable;

/**
 * How many publications are filed under one information category.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
class HiddenCategoryCheck {

	/**
	 * The value list the information categories are.
	 *
	 * @var string
	 */
	public const SCHEME = 'informatiecategorieen';

	/**
	 * Constructor.
	 *
	 * @param WooCategoryRegistry         $categories   The set of categories, unordered and unfiltered.
	 * @param OpenRegisterLookupInterface $openRegister Where the publications are counted.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly WooCategoryRegistry $categories,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * The number of publications filed under the category an entry URI names, or null when it cannot be counted.
	 *
	 * @param string $entry The TOOI URI of the category.
	 *
	 * @return int|null The count; null when OpenRegister or the publication schema cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function publicationsUnder(string $entry): ?int {
		$values = $this->valuesOf(entry: $entry);
		if ($values === []) {
			return 0;
		}

		try {
			$target = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
			$count = 0;
			foreach ($values as $value) {
				$found = $this->openRegister->objectService()->searchObjectsPaginated(
					query: ['@self' => $target, 'wooCategory' => $value, '_limit' => 1],
					_rbac: false,
					_multitenancy: false
				);
				$count += (int)($found['total'] ?? count((array)($found['results'] ?? [])));
			}
		} catch (Throwable $e) {
			return null;
		}

		return $count;

	}//end publicationsUnder()

	/**
	 * Every value a publication may store for the category: the codes and labels of the categories under it, and its URI.
	 *
	 * @param string $entry The TOOI URI of the category.
	 *
	 * @return list<string> The values, or none when no category has that URI.
	 */
	private function valuesOf(string $entry): array {
		$values = [];
		foreach ($this->categories->all() as $code => $record) {
			if ((string)($record['tooiUri'] ?? '') === $entry) {
				$values[] = (string)$code;
				$values[] = (string)($record['label'] ?? '');
			}
		}

		if ($values === []) {
			return [];
		}

		$values[] = $entry;

		return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));

	}//end valuesOf()
}//end class
