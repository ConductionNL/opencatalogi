<?php

/**
 * The set of Woo information categories a catalogue can publish under.
 *
 * The categories used to be two PHP constants: a list of 17 codes in
 * `WooCategory::ALL` and a map of 17 sitemap file names in
 * `SitemapService::INFO_CAT`. An eighteenth category therefore needed a code
 * change, and the two constants could disagree. This registry replaces both.
 *
 * It has two sources, and keeps them apart on purpose:
 *
 * - The 18 members of the `scw_woo_informatiecategorieen` waardelijst, read from
 *   {@see TooiVocabularyService}. These are a controlled vocabulary owned by KOOP.
 *   They are not operator data and are not copied into storage, because a second
 *   copy of a controlled vocabulary is how one of the two goes stale.
 * - The categories an operator added, stored as objects of the
 *   `informationCategory` schema. Adding one needs no code.
 *
 * A locally added category MUST name the waardelijst member it publishes as. One
 * that does not is refused by {@see all()} and reported by {@see rejected()}: it
 * gets no sitemap file, so its publications reach no national index at all. That
 * is deliberate. A silent leak of a non-TOOI category into the national index is
 * worse than refusing to publish.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\WooCategory;
use Psr\Log\LoggerInterface;

/**
 * The information categories a sitemap, a robots.txt and the editor are built from.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class WooCategoryRegistry {

	/**
	 * The schema an operator adds a local information category to.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'informationCategory';

	/**
	 * The origin of a category that is a waardelijst member.
	 *
	 * @var string
	 */
	public const ORIGIN_VALUE_LIST = 'waardelijst';

	/**
	 * The origin of a category an operator added as data.
	 *
	 * @var string
	 */
	public const ORIGIN_LOCAL = 'lokaal';

	/**
	 * The fixed prefix of a category sitemap file name.
	 *
	 * The publicatievoorwaarden permit two sitemap variants and we use variant 2,
	 * one index per category. The national harvester matches the file name, so a
	 * name that departs from this convention is read by nobody.
	 *
	 * @var string
	 */
	public const SITEMAP_PREFIX = 'sitemapindex-diwoo-';

	/**
	 * The fixed suffix of a category sitemap file name.
	 *
	 * @var string
	 */
	public const SITEMAP_SUFFIX = '.xml';

	/**
	 * The shape a category code must have to get a sitemap file.
	 *
	 * Lowercase letters, digits and single hyphens. It is deliberately narrow: the
	 * code becomes part of a URL path and of a file name the harvester matches.
	 *
	 * @var string
	 */
	public const CODE_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,46}[a-z0-9])?$/';

	/**
	 * Per-request cache of the merged category set, keyed by nothing: one per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $categories = null;

	/**
	 * Per-request record of the local categories this registry refused, code → reason.
	 *
	 * @var array<string, string>
	 */
	private array $refused = [];

	/**
	 * Constructor for WooCategoryRegistry.
	 *
	 * @param TooiVocabularyService $tooiVocabulary The waardelijst resolver, which owns the bundled 18.
	 * @param SettingsService $settingsService The settings service, used to reach OpenRegister.
	 * @param LoggerInterface $logger The logger, which records every refused local category.
	 * @param LocalCategoryAdmission $admission The decision about one stored category.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly LocalCategoryAdmission $admission,
	) {

	}//end __construct()

	/**
	 * The sitemap file name a category code is served at.
	 *
	 * @param string $code The category code, e.g. `infocat012`.
	 *
	 * @return string The file name, e.g. `sitemapindex-diwoo-infocat012.xml`.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public static function sitemapFileFor(string $code): string {
		return self::SITEMAP_PREFIX . $code . self::SITEMAP_SUFFIX;

	}//end sitemapFileFor()

	/**
	 * The category code in a sitemap file name, or null when the name does not fit.
	 *
	 * @param string $sitemapFile The file name, e.g. `sitemapindex-diwoo-infocat012.xml`.
	 *
	 * @return string|null The code, e.g. `infocat012`, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public static function codeOf(string $sitemapFile): ?string {
		if (str_starts_with($sitemapFile, self::SITEMAP_PREFIX) === false
			|| str_ends_with($sitemapFile, self::SITEMAP_SUFFIX) === false
		) {
			return null;
		}

		$code = substr(
			$sitemapFile,
			strlen(self::SITEMAP_PREFIX),
			(strlen($sitemapFile) - strlen(self::SITEMAP_PREFIX) - strlen(self::SITEMAP_SUFFIX))
		);

		if (preg_match(self::CODE_PATTERN, $code) !== 1) {
			return null;
		}

		return $code;

	}//end codeOf()

	/**
	 * Every category a catalogue may publish under, keyed by code.
	 *
	 * Each record carries a `tooiUri`, always. A record without one never enters
	 * this list, which is what makes an unmapped local category unable to reach a
	 * sitemap: there is no record for it, so there is no sitemap file for it and no
	 * query that lists its publications.
	 *
	 * @return array<string, array<string, mixed>> The categories, each with `code`,
	 *   `label`, `labelEn`, `tooiUri`, `tooiLabel`, `mapsTo`, `origin`,
	 *   `sitemapFile` and `schemas`.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function all(): array {
		if ($this->categories !== null) {
			return $this->categories;
		}

		$this->refused = [];
		$categories = $this->bundled();

		foreach ($this->storedCategories() as $stored) {
			$decision = $this->admission->admit(stored: $stored, bundled: $categories);
			if ($decision['record'] === null) {
				$this->refuse(code: $decision['code'], reason: (string)$decision['reason']);
				continue;
			}

			$categories[$decision['record']['code']] = $decision['record'];
		}

		$this->categories = $categories;

		return $this->categories;

	}//end all()

	/**
	 * The local categories this registry refused, code → reason.
	 *
	 * Resolved by {@see all()}, so call that first. A refusal is a configuration
	 * fault an operator must see, which is why it is reported rather than silently
	 * dropped.
	 *
	 * @return array<string, string> The refusals.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function rejected(): array {
		$this->all();

		return $this->refused;

	}//end rejected()

	/**
	 * The sitemap file names and the title each index carries.
	 *
	 * The drop-in replacement for the former `SitemapService::INFO_CAT` constant.
	 *
	 * @return array<string, string> File name → category title.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function sitemapFiles(): array {
		$files = [];
		foreach ($this->all() as $record) {
			$files[$record['sitemapFile']] = $record['label'];
		}

		return $files;

	}//end sitemapFiles()

	/**
	 * One category by its code.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<string, mixed>|null The record, or null when the code is unknown.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function find(string $code): ?array {
		return ($this->all()[$code] ?? null);

	}//end find()

	/**
	 * One category by the sitemap file name it is served at.
	 *
	 * @param string $sitemapFile The file name.
	 *
	 * @return array<string, mixed>|null The record, or null when nothing is served there.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function findBySitemapFile(string $sitemapFile): ?array {
		$code = self::codeOf(sitemapFile: $sitemapFile);
		if ($code === null) {
			return null;
		}

		return $this->find(code: $code);

	}//end findBySitemapFile()

	/**
	 * The schema slugs a category restricts its sitemap to, if it names any.
	 *
	 * An empty list means the category does not restrict, so the catalogue's whole
	 * schema scope is searched as before.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<int, string> The schema slugs.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function schemasFor(string $code): array {
		return (array)($this->find(code: $code)['schemas'] ?? []);

	}//end schemasFor()

	/**
	 * The waardelijst member a stored category value publishes as.
	 *
	 * Tries the waardelijst resolver first, so a code, a label, a bare `c_…` id and
	 * a full kern URI all keep resolving exactly as they did. Then tries a locally
	 * added category's own code and title, and returns the member that category maps
	 * onto. Returns null when the value maps to no member, which is the fail-closed
	 * path the renderer already takes: the axis is omitted, never emitted as free text.
	 *
	 * @param string|null $value The publication's stored category value.
	 *
	 * @return array{uri: string, label: string}|null The waardelijst member, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function resolveForDocument(?string $value): ?array {
		$member = $this->tooiVocabulary->resolveInformatiecategorie($value);
		if ($member !== null) {
			return $member;
		}

		if ($value === null || trim($value) === '') {
			return null;
		}

		$needle = strtolower(trim($value));
		foreach ($this->all() as $record) {
			if ($record['origin'] !== self::ORIGIN_LOCAL) {
				continue;
			}

			if ($needle !== strtolower($record['code']) && $needle !== strtolower($record['label'])) {
				continue;
			}

			return ['uri' => $record['tooiUri'], 'label' => $record['tooiLabel']];
		}

		return null;

	}//end resolveForDocument()

	/**
	 * Record and log one refusal.
	 *
	 * A refusal is a configuration fault an operator must see, so it is logged as a
	 * warning and reported by {@see rejected()}, never dropped.
	 *
	 * @param string $code The category code, or a placeholder when it had none.
	 * @param string $reason Why the category was refused.
	 *
	 * @return void
	 *
	 * @spec exclude Bookkeeping for the refusals LocalCategoryAdmission decides.
	 */
	private function refuse(string $code, string $reason): void {
		$this->refused[$code] = $reason;
		$this->logger->warning(
			'OpenCatalogi refused an information category, so it publishes to no sitemap: ' . $code . ': ' . $reason,
			['app' => 'opencatalogi']
		);
	}//end refuse()

	/**
	 * The 18 waardelijst members as category records.
	 *
	 * @return array<string, array<string, mixed>> The records, keyed by code.
	 *
	 * @spec exclude Shapes the already-resolved waardelijst into the record this class returns.
	 */
	private function bundled(): array {
		$english = WooCategory::ALL;
		$records = [];
		foreach ($this->tooiVocabulary->informatiecategorieList() as $code => $member) {
			$records[$code] = [
				'code' => $code,
				'label' => $member['label'],
				'labelEn' => (string)($english[$code]['en'] ?? $member['label']),
				'tooiUri' => $member['uri'],
				'tooiLabel' => $member['label'],
				'mapsTo' => $code,
				'origin' => self::ORIGIN_VALUE_LIST,
				'sitemapFile' => self::sitemapFileFor(code: $code),
				'schemas' => [],
			];
		}

		return $records;

	}//end bundled()




	/**
	 * The stored local information categories, or an empty list when there are none.
	 *
	 * Reads through OpenRegister. Anything that goes wrong, including OpenRegister
	 * not being installed and the schema not being imported yet, yields an empty
	 * list: the registry then serves the 18 waardelijst members, which is the
	 * behaviour the two constants had.
	 *
	 * @return array<int, array<string, mixed>> The stored objects as arrays.
	 *
	 * @spec exclude Storage read; the admission rules that carry the behavior are in admit().
	 */
	private function storedCategories(): array {
		try {
			$objectService = $this->settingsService->getObjectService();
			$schemaMapper = $this->settingsService->getSchemaMapper();
			if ($objectService === null || $schemaMapper === null) {
				return [];
			}

			$schema = $schemaMapper->find(self::SCHEMA_SLUG, _rbac: false, _multitenancy: false);
			if ($schema === null) {
				return [];
			}

			$found = $objectService->searchObjectsPaginated(
				query: ['@self' => ['schema' => $schema->getId()], '_limit' => 500],
				_rbac: false,
				_multitenancy: false,
				deleted: false
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenCatalogi could not read the local information categories: ' . $e->getMessage(),
				['app' => 'opencatalogi']
			);

			return [];
		}//end try

		$rows = [];
		foreach (($found['results'] ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$rows[] = (array)$row->jsonSerialize();
				continue;
			}

			$rows[] = (array)$row;
		}

		return $rows;

	}//end storedCategories()


}//end class
