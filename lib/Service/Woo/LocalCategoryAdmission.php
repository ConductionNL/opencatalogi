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
	 *
	 * Returns: The code, the record when admitted, and the reason when refused.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function admit(array $stored, array $bundled): array {
		$code = strtolower(trim((string)($stored['code'] ?? '')));
		if ($code === '') {
			return $this->refused(code: self::CODE_MISSING, reason: 'the category stores no code');
		}

		$refusal = $this->codeRefusal(code: $code, bundled: $bundled);
		if ($refusal !== null) {
			return $this->refused(code: $code, reason: $refusal);
		}

		$member = $this->tooiVocabulary->resolveInformatiecategorie($this->text(value: ($stored['mapsTo'] ?? null)));
		if ($member === null) {
			return $this->refused(code: $code, reason: 'mapsTo names no member of the informatiecategorieen waardelijst');
		}

		$record = ['code' => $code] + $this->labels(stored: $stored, member: $member) + $this->memberFields(member: $member, bundled: $bundled);

		return ['code' => $code, 'reason' => null, 'record' => $record + $this->localFields(stored: $stored, code: $code)];

	}//end admit()

	/**
	 * Why a category code cannot be admitted: it cannot be part of a sitemap
	 * file name, or it is already a waardelijst member.
	 *
	 * @param string $code The normalised code.
	 * @param array<string, array<string, mixed>> $bundled The waardelijst records.
	 *
	 * @return string|null The reason, or null when the code is fine.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WooCategoryRegistry::sitemapFileFor() and
	 *   ::codeOf() are pure string functions over the file-name convention. The point
	 *   of calling them here is that the admission and the route cannot disagree
	 *   about that convention, which a second copy of it would allow.
	 */
	private function codeRefusal(string $code, array $bundled): ?string {
		if (WooCategoryRegistry::codeOf(sitemapFile: WooCategoryRegistry::sitemapFileFor(code: $code)) !== $code) {
			return 'the code cannot be part of a sitemap file name';
		}

		if (isset($bundled[$code]) === true) {
			return 'the code is already a waardelijst member';
		}

		return null;
	}//end codeRefusal()

	/**
	 * A refused category.
	 *
	 * @param string $code The code, or CODE_MISSING.
	 * @param string $reason Why it is refused.
	 *
	 * @return array{code: string, record: null, reason: string}
	 */
	private function refused(string $code, string $reason): array {
		return ['code' => $code, 'record' => null, 'reason' => $reason];
	}//end refused()

	/**
	 * The admitted record's labels: the stored title, else the member's label,
	 * and the stored English title, else that label.
	 *
	 * @param array<string, mixed> $stored The stored object.
	 * @param array{uri: string, label: string} $member The waardelijst member it maps to.
	 *
	 * @return array{label: string, labelEn: string}
	 */
	private function labels(array $stored, array $member): array {
		$title = ($this->text(value: ($stored['title'] ?? null)) ?? $member['label']);

		return [
			'label' => $title,
			'labelEn' => ($this->text(value: ($stored['titleEn'] ?? null)) ?? $title),
		];
	}//end labels()

	/**
	 * The admitted record's waardelijst fields: the member's URI and label, and
	 * the code of the bundled record that carries it.
	 *
	 * @param array{uri: string, label: string} $member The waardelijst member.
	 * @param array<string, array<string, mixed>> $bundled The waardelijst records.
	 *
	 * @return array{tooiUri: string, tooiLabel: string, mapsTo: string}
	 */
	private function memberFields(array $member, array $bundled): array {
		return [
			'tooiUri' => $member['uri'],
			'tooiLabel' => $member['label'],
			'mapsTo' => $this->codeOfMember(uri: $member['uri'], bundled: $bundled),
		];
	}//end memberFields()

	/**
	 * The admitted record's local fields: its origin, sitemap file and schemas.
	 *
	 * @param array<string, mixed> $stored The stored object.
	 * @param string $code The normalised code.
	 *
	 * @return array{origin: string, sitemapFile: string, schemas: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WooCategoryRegistry's file-name convention, shared with the route.
	 */
	private function localFields(array $stored, string $code): array {
		return [
			'origin' => WooCategoryRegistry::ORIGIN_LOCAL,
			'sitemapFile' => WooCategoryRegistry::sitemapFileFor(code: $code),
			'schemas' => $this->schemaSlugs(value: ($stored['schemas'] ?? [])),
		];
	}//end localFields()

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
