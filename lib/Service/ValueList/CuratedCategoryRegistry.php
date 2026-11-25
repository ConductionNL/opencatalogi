<?php

/**
 * OpenCatalogi Curated Category Registry.
 *
 * The information categories in the order the administrator set. Wraps
 * {@see \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry}, which keeps the
 * set itself (the waardelijst members and the local categories), and orders
 * what {@see all()} answers by the `order` of each member's value-list choice:
 * ordered members first, lowest first, then the rest in the set's own order.
 * A local category follows the member it publishes under. Everything else is
 * the wrapped registry's answer, unchanged: the sitemaps, robots.txt and the
 * national index keep every category, so a hidden category's sitemap index is
 * still served (empty, since a category with publications cannot be hidden).
 * {@see offered()} is the officer's list: {@see all()} without the hidden ones.
 * Ordering and hiding decide what an officer is offered, never what is published.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use Throwable;

/**
 * Orders the information categories by the administrator's choices.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */
class CuratedCategoryRegistry implements InformationCategoryRegistryInterface {

	/**
	 * The value list the information categories are.
	 *
	 * @var string
	 */
	public const SCHEME = 'informatiecategorieen';

	/**
	 * Per-request cache of the ordered set.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $ordered = null;

	/**
	 * Per-request cache of the choices the order came from; empty when they could not be read.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $choiceCache = [];

	/**
	 * Constructor.
	 *
	 * @param WooCategoryRegistry  $registry The set of categories.
	 * @param ValueListChoiceStore $choices  The organisation's choices, which carry the order.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly WooCategoryRegistry $registry,
		private readonly ValueListChoiceStore $choices,
	) {

	}//end __construct()

	/**
	 * Every category, keyed by code, in the administrator's order.
	 *
	 * When the choices cannot be read the set is answered in its own order:
	 * an unreadable order never hides or drops a category.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
	 */
	public function all(): array {
		if ($this->ordered !== null) {
			return $this->ordered;
		}

		$set = $this->registry->all();
		try {
			$choices = $this->choices->forScheme(scheme: self::SCHEME);
		} catch (Throwable $e) {
			return $set;
		}

		$this->choiceCache = $choices;
		$this->ordered = self::inOrder(set: $set, choices: $choices);

		return $this->ordered;

	}//end all()

	/**
	 * The categories an officer is offered: {@see all()} without the ones the administrator hid.
	 *
	 * A local category goes with the member it publishes under. When the
	 * choices cannot be read nothing is hidden.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function offered(): array {
		$all = $this->all();

		return self::visible(set: $all, choices: $this->choiceCache);

	}//end offered()

	/**
	 * The local categories the set refused, code → reason.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function rejected(): array {
		return $this->registry->rejected();

	}//end rejected()

	/**
	 * The sitemap file names and the title each index carries.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function sitemapFiles(): array {
		return $this->registry->sitemapFiles();

	}//end sitemapFiles()

	/**
	 * One category by its code.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function find(string $code): ?array {
		return $this->registry->find(code: $code);

	}//end find()

	/**
	 * One category by the sitemap file name it is served at.
	 *
	 * @param string $sitemapFile The file name.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function findBySitemapFile(string $sitemapFile): ?array {
		return $this->registry->findBySitemapFile(sitemapFile: $sitemapFile);

	}//end findBySitemapFile()

	/**
	 * The schema slugs a category restricts its sitemap to.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function schemasFor(string $code): array {
		return $this->registry->schemasFor(code: $code);

	}//end schemasFor()

	/**
	 * The waardelijst member a stored category value publishes as.
	 *
	 * @param string|null $value The publication's stored category value.
	 *
	 * @return array{uri: string, label: string}|null
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function resolveForDocument(?string $value): ?array {
		return $this->registry->resolveForDocument(value: $value);

	}//end resolveForDocument()

	/**
	 * The set without the categories the administrator hid; a local category goes with the member it publishes under.
	 *
	 * @param array<string, array<string, mixed>>  $set     The categories, keyed by code.
	 * @param array<string, array<string, mixed>> $choices The choices by entry URI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function visible(array $set, array $choices): array {
		return array_filter(
			$set,
			static fn (array $record): bool => (($choices[(string)($record['tooiUri'] ?? '')]['hidden'] ?? false) !== true)
		);

	}//end visible()

	/**
	 * The set in the order of the choices: ordered first, lowest first, then the set's own order.
	 *
	 * @param array<string, array<string, mixed>>  $set     The categories, keyed by code.
	 * @param array<string, array<string, mixed>> $choices The choices by entry URI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function inOrder(array $set, array $choices): array {
		$keys = [];
		$position = 0;
		foreach ($set as $code => $record) {
			$order = ($choices[(string)($record['tooiUri'] ?? '')]['order'] ?? null);
			$unordered = (int)($order === null);
			$keys[$code] = [$unordered, (int)$order, $position];
			$position++;
		}

		uksort($set, static fn (int|string $left, int|string $right): int => $keys[$left] <=> $keys[$right]);

		return $set;

	}//end inOrder()
}//end class
