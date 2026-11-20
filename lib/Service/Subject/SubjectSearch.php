<?php

/**
 * OpenCatalogi Subject Search.
 *
 * The subjects (`theme` objects) a public search term matches, shaped as
 * search results of their own type, each with the count of its public
 * publications (subjects-as-first-class-records, REQ-SUB-004). The caller runs
 * it inside the anonymous scope of the public search, so only what an
 * anonymous reader may see is matched.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Subject
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
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-public-search-returns-subjects-as-their-own-result-type-req-sub-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Subject;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCP\AppFramework\Db\Entity;

/**
 * Finds the subjects a search term matches.
 *
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-public-search-returns-subjects-as-their-own-result-type-req-sub-004
 */
class SubjectSearch {

	/**
	 * The `resultType` of a subject result.
	 *
	 * @var string
	 */
	public const RESULT_TYPE = 'subject';

	/**
	 * The most subjects one search answers with.
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $registers    Resolves the theme register and schema.
	 * @param SubjectPublications         $publications Counts the public publications under a subject.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
		private readonly SubjectPublications $publications,
	) {

	}//end __construct()

	/**
	 * The subjects matching a search term, as subject results.
	 *
	 * An empty term, a theme register or schema that is not configured, or an
	 * object service without the search method answers no subjects.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $term          The search term.
	 *
	 * @return array<int, array<string, mixed>> The subject results: resultType, id, slug, title,
	 *                                          summary, image, publicationCount and url.
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-public-search-returns-subjects-as-their-own-result-type-req-sub-004
	 */
	public function search(object $objectService, string $term): array {
		$term = trim($term);
		if ($term === '' || method_exists($objectService, 'searchObjectsPaginated') === false) {
			return [];
		}

		$pair = $this->registers->registerConfiguration(registerKey: 'theme_register', schemaKey: 'theme_schema');
		if (empty($pair['register']) === true || empty($pair['schema']) === true) {
			return [];
		}

		$result = $objectService->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $pair['register'], 'schema' => $pair['schema']],
				'_search' => $term,
				'_limit' => self::LIMIT,
			],
			_rbac: true,
			_multitenancy: false
		);

		$subjects = [];
		foreach (($result['results'] ?? []) as $row) {
			$subject = $this->subjectResult(row: $row);
			if ($subject !== null) {
				$subjects[] = $subject;
			}
		}

		return $subjects;
	}//end search()

	/**
	 * A theme row as a subject result, or null when it carries no id.
	 *
	 * @param mixed $row An OpenRegister row: an entity or an array.
	 *
	 * @return array<string, mixed>|null The subject result.
	 */
	private function subjectResult(mixed $row): ?array {
		if ($row instanceof Entity) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		$subjectId = (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
		if ($subjectId === '') {
			return null;
		}

		return [
			'resultType' => self::RESULT_TYPE,
			'id' => $subjectId,
			'slug' => ($row['slug'] ?? null),
			'title' => ($row['title'] ?? null),
			'summary' => ($row['summary'] ?? null),
			'image' => ($row['image'] ?? null),
			'publicationCount' => $this->publications->countPublic(subjectId: $subjectId),
			'url' => '/index.php/apps/opencatalogi/api/themes/'.rawurlencode($subjectId),
		];
	}//end subjectResult()
}//end class
