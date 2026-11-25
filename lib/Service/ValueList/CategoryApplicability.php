<?php

/**
 * OpenCatalogi Category Applicability.
 *
 * The organisation type an installation publishes as, and the information
 * categories the Woo does not apply to for that type. The table is shipped in
 * `lib/Settings/woo-category-applicability.json`, each entry citing the scope
 * the Woo article gives the category. It is only a proposal: the administrator
 * confirms or changes it per category, and what they confirm is written as
 * `hidden` on the category's value-list choice, never into the shipped file, so
 * an upgrade cannot overwrite it.
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
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use InvalidArgumentException;
use OCP\IAppConfig;

/**
 * Reads the applicability table and keeps the organisation type.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
class CategoryApplicability {

	/**
	 * The app-config key of the organisation type.
	 *
	 * @var string
	 */
	public const TYPE_KEY = 'woo_organisation_type';

	/**
	 * The shipped table.
	 *
	 * @var string
	 */
	public const TABLE = __DIR__ . '/../../Settings/woo-category-applicability.json';

	/**
	 * The table, read once.
	 *
	 * @var array{types: list<string>, entries: list<array<string, mixed>>}|null
	 */
	private ?array $table = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Keeps the organisation type.
	 * @param string     $file   The table; the shipped one unless a test names another.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly string $file = self::TABLE,
	) {

	}//end __construct()

	/**
	 * The organisation types an administrator chooses from.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function types(): array {
		return $this->table()['types'];

	}//end types()

	/**
	 * The organisation type set for this installation, or null when none is.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function organisationType(): ?string {
		$type = $this->config->getValueString('opencatalogi', self::TYPE_KEY, '');
		if (in_array($type, $this->types(), true) === false) {
			return null;
		}

		return $type;

	}//end organisationType()

	/**
	 * Set the organisation type; an unknown type is refused.
	 *
	 * @param string $type One of {@see types()}.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException For an unknown type.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function setOrganisationType(string $type): void {
		if (in_array($type, $this->types(), true) === false) {
			throw new InvalidArgumentException('Unknown organisation type: ' . $type);
		}

		$this->config->setValueString('opencatalogi', self::TYPE_KEY, $type);

	}//end setOrganisationType()

	/**
	 * The categories proposed for hiding for a type, each with its legal basis.
	 *
	 * @param string $type The organisation type.
	 *
	 * @return list<array{category: string, tooiUri: string, article: string, basis: string, reason: string}>
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public function proposalFor(string $type): array {
		$proposal = [];
		foreach ($this->table()['entries'] as $entry) {
			if (in_array($type, (array)($entry['notApplicableTo'] ?? []), true) === true && self::cites(entry: $entry) === true) {
				$proposal[] = [
					'category' => (string)$entry['category'],
					'tooiUri' => (string)$entry['tooiUri'],
					'article' => (string)$entry['article'],
					'basis' => (string)$entry['basis'],
					'reason' => (string)($entry['reason'] ?? ''),
				];
			}
		}

		return $proposal;

	}//end proposalFor()

	/**
	 * Whether an entry names its category and cites its legal basis; one that does not is never proposed.
	 *
	 * @param array<string, mixed> $entry The table entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	public static function cites(array $entry): bool {
		foreach (['category', 'tooiUri', 'article', 'basis'] as $field) {
			if (trim((string)($entry[$field] ?? '')) === '') {
				return false;
			}
		}

		return true;

	}//end cites()

	/**
	 * The table; an unreadable one proposes nothing and offers no type.
	 *
	 * @return array{types: list<string>, entries: list<array<string, mixed>>}
	 */
	private function table(): array {
		if ($this->table !== null) {
			return $this->table;
		}

		$raw = is_readable($this->file) === true ? json_decode((string)file_get_contents($this->file), true) : null;
		$types = array_values(array_map('strval', (array)($raw['types'] ?? [])));
		$entries = array_values(array_filter((array)($raw['entries'] ?? []), 'is_array'));
		$this->table = ['types' => $types, 'entries' => $entries];

		return $this->table;

	}//end table()
}//end class
