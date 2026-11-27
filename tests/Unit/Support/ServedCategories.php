<?php

/**
 * An information category registry that serves a fixed set of categories.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;

/**
 * Serves the value-list members the recorded TOOI register holds, plus any
 * operator-added codes a test names, so a caller that asks the registry
 * whether a category exists can be tested without OpenRegister.
 */
class ServedCategories implements InformationCategoryRegistryInterface {

	/**
	 * The categories, keyed by code.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $records = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $added      Operator-added category codes.
	 * @param bool               $unreadable Whether the register cannot be read, so no category is served.
	 */
	public function __construct(array $added = [], bool $unreadable = false) {
		if ($unreadable === true) {
			return;
		}

		foreach (RecordedTooiRegister::service()->informatiecategorieList() as $code => $member) {
			$this->records[$code] = [
				'code' => $code,
				'label' => $member['label'],
				'labelEn' => $member['labelEn'],
				'tooiUri' => $member['uri'],
				'tooiLabel' => $member['label'],
				'mapsTo' => $code,
				'origin' => WooCategoryRegistry::ORIGIN_VALUE_LIST,
				'sitemapFile' => WooCategoryRegistry::sitemapFileFor(code: $code),
				'schemas' => [],
			];
		}

		foreach ($added as $code) {
			$this->records[$code] = [
				'code' => $code,
				'label' => $code,
				'labelEn' => $code,
				'tooiUri' => '',
				'tooiLabel' => '',
				'mapsTo' => 'infocat018',
				'origin' => WooCategoryRegistry::ORIGIN_LOCAL,
				'sitemapFile' => WooCategoryRegistry::sitemapFileFor(code: $code),
				'schemas' => [],
			];
		}
	}//end __construct()

	/**
	 * Every category, keyed by code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->records;
	}//end all()

	/**
	 * No refusals.
	 *
	 * @return array<string, string>
	 */
	public function rejected(): array {
		return [];
	}//end rejected()

	/**
	 * File name to label.
	 *
	 * @return array<string, string>
	 */
	public function sitemapFiles(): array {
		return array_column($this->records, 'label', 'sitemapFile');
	}//end sitemapFiles()

	/**
	 * One category by code.
	 *
	 * @param string $code The code.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $code): ?array {
		return ($this->records[$code] ?? null);
	}//end find()

	/**
	 * One category by sitemap file.
	 *
	 * @param string $sitemapFile The file name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findBySitemapFile(string $sitemapFile): ?array {
		return $this->find(code: (string)WooCategoryRegistry::codeOf(sitemapFile: $sitemapFile));
	}//end findBySitemapFile()

	/**
	 * No schema restriction.
	 *
	 * @param string $code The code.
	 *
	 * @return array<int, string>
	 */
	public function schemasFor(string $code): array {
		return [];
	}//end schemasFor()

	/**
	 * The member a value names, by code only.
	 *
	 * @param string|null $value The value.
	 *
	 * @return array{uri: string, label: string}|null
	 */
	public function resolveForDocument(?string $value): ?array {
		$record = $this->find(code: (string)$value);
		if ($record === null) {
			return null;
		}

		return ['uri' => (string)$record['tooiUri'], 'label' => (string)$record['tooiLabel']];
	}//end resolveForDocument()
}//end class
