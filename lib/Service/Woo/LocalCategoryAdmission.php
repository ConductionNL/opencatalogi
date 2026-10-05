<?php

/**
 * Whether a stored information category may publish, and under which member.
 *
 * Split out of {@see WooCategoryRegistry} because the registry's job is to merge
 * two sources and this is the decision about one row. There are four refusals: no
 * usable code, a code shaped so it cannot be a sitemap file name, a code that
 * shadows a waardelijst member, and a `mapsTo` that names no member. Every one
 * means the category publishes nothing, which is the point: a non-TOOI category
 * leaking into the national index is worse than refusing to publish.
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
 */

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Service\TooiVocabularyService;

/**
 * Decides one stored information category: admitted with a URI, or refused.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
 */
class LocalCategoryAdmission {

	/**
	 * The code reported when a stored category carries none.
	 *
	 * @var string
	 */
	public const CODE_MISSING = '(no code)';

	/**
	 * Constructor for LocalCategoryAdmission.
	 *
	 * @param TooiVocabularyService $tooiVocabulary The waardelijst resolver.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
	) {

	}//end __construct()

	/**
	 * Decide one stored category.
	 *
	 * An admitted record always carries a `tooiUri`. That is the guarantee the
	 * sitemap rests on: it is built from admitted records only.
	 *
	 * @param array<string, mixed> $stored The stored object.
	 * @param array<string, array<string, mixed>> $bundled The waardelijst records, to catch shadowing.
	 *
	 * @return array{code: string, record: array<string, mixed>|null, reason: string|null}
	 *   The code, the record when admitted, and the reason when refused.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WooCategoryRegistry::sitemapFileFor() and
	 *   ::codeOf() are pure string functions over the file-name convention. The point
	 *   of calling them here is that the admission and the route cannot disagree
	 *   about that convention, which a second copy of it would allow.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function admit(array $stored, array $bundled): array {
		$code = strtolower(trim((string)($stored['code'] ?? '')));
		if ($code === '') {
			return ['code' => self::CODE_MISSING, 'record' => null, 'reason' => 'the category stores no code'];
		}

		if (WooCategoryRegistry::codeOf(sitemapFile: WooCategoryRegistry::sitemapFileFor(code: $code)) !== $code) {
			return [
				'code' => $code,
				'record' => null,
				'reason' => 'the code cannot be part of a sitemap file name',
			];
		}

		if (isset($bundled[$code]) === true) {
			return [
				'code' => $code,
				'record' => null,
				'reason' => 'the code is already a waardelijst member',
			];
		}

		$member = $this->tooiVocabulary->resolveInformatiecategorie($this->text(value: ($stored['mapsTo'] ?? null)));
		if ($member === null) {
			return [
				'code' => $code,
				'record' => null,
				'reason' => 'mapsTo names no member of the informatiecategorieen waardelijst',
			];
		}

		$title = ($this->text(value: ($stored['title'] ?? null)) ?? $member['label']);

		return [
			'code' => $code,
			'reason' => null,
			'record' => [
				'code' => $code,
				'label' => $title,
				'labelEn' => ($this->text(value: ($stored['titleEn'] ?? null)) ?? $title),
				'tooiUri' => $member['uri'],
				'tooiLabel' => $member['label'],
				'mapsTo' => $this->codeOfMember(uri: $member['uri'], bundled: $bundled),
				'origin' => WooCategoryRegistry::ORIGIN_LOCAL,
				'sitemapFile' => WooCategoryRegistry::sitemapFileFor(code: $code),
				'schemas' => $this->schemaSlugs(value: ($stored['schemas'] ?? [])),
			],
		];

	}//end admit()

	/**
	 * The waardelijst code behind a resolved member URI.
	 *
	 * @param string $uri The member URI.
	 * @param array<string, array<string, mixed>> $bundled The waardelijst records.
	 *
	 * @return string The code, or an empty string when no record carries that URI.
	 *
	 * @spec exclude Reverse lookup over records the caller already resolved.
	 */
	private function codeOfMember(string $uri, array $bundled): string {
		foreach ($bundled as $code => $record) {
			if (($record['tooiUri'] ?? null) === $uri) {
				return (string)$code;
			}
		}

		return '';

	}//end codeOfMember()

	/**
	 * A stored `schemas` value as a list of distinct non-empty slugs.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, string> The slugs.
	 *
	 * @spec exclude Input normalisation.
	 */
	private function schemaSlugs(mixed $value): array {
		$slugs = [];
		foreach ((array)$value as $slug) {
			$text = $this->text(value: $slug);
			if ($text === null) {
				continue;
			}

			$slugs[] = $text;
		}

		return array_values(array_unique($slugs));

	}//end schemaSlugs()

	/**
	 * A stored value as a trimmed non-empty string, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The string, or null.
	 *
	 * @spec exclude Input normalisation.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);

	}//end text()
}//end class
