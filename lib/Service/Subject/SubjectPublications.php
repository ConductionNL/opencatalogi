<?php

/**
 * OpenCatalogi Subject Publications.
 *
 * The publications filed under a subject (a `theme` object), read as an
 * anonymous caller so a draft, scheduled, withdrawn or non-public publication is
 * never listed or counted, whoever calls the public route
 * (subjects-as-first-class-records, REQ-SUB-002, REQ-SUB-003).
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
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-lists-what-is-filed-under-it-req-sub-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Subject;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCP\AppFramework\Db\Entity;

/**
 * Lists and counts the public publications filed under a subject.
 *
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-lists-what-is-filed-under-it-req-sub-003
 */
class SubjectPublications {

	/**
	 * The most publications one page lists.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $registers Resolves the publication register pair and OpenRegister's ObjectService.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
	) {

	}//end __construct()

	/**
	 * The public publications filed under a subject, newest first, in the
	 * envelope of the public publications list.
	 *
	 * @param string $subjectId The subject's uuid.
	 * @param int    $limit     Rows per page, 1 to MAX_LIMIT.
	 * @param int    $page      The page, from 1.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: int, offset: int, page: int, pages: int}
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-lists-what-is-filed-under-it-req-sub-003
	 */
	public function listPublic(string $subjectId, int $limit, int $page): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$page = max(1, $page);
		$result = $this->publicSearch(
			subjectId: $subjectId,
			paging: [
				'_limit' => $limit,
				'_page' => $page,
				'_order' => ['publicationDate' => 'DESC'],
			]
		);

		$total = (int)($result['total'] ?? 0);
		return [
			'results' => array_map(
				static function (mixed $row): mixed {
					if ($row instanceof Entity) {
						return $row->jsonSerialize();
					}

					return $row;
				},
				array_values(($result['results'] ?? []))
			),
			'total' => $total,
			'limit' => $limit,
			'offset' => (($page - 1) * $limit),
			'page' => $page,
			'pages' => max(1, (int)ceil($total / $limit)),
		];
	}//end listPublic()

	/**
	 * The number of public publications filed under a subject.
	 *
	 * @param string $subjectId The subject's uuid.
	 *
	 * @return int The count.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-the-featured-subjects-are-served-for-the-portal-req-sub-002
	 */
	public function countPublic(string $subjectId): int {
		$result = $this->publicSearch(subjectId: $subjectId, paging: ['_limit' => 1, '_page' => 1]);
		return (int)($result['total'] ?? 0);
	}//end countPublic()

	/**
	 * Search the publication schema for the subject as an anonymous caller.
	 *
	 * Fails closed: without OpenRegister's anonymous evaluation scope, or without
	 * a subject id, nothing is listed rather than what the session may see.
	 *
	 * @param string               $subjectId The subject's uuid.
	 * @param array<string, mixed> $paging    Paging and order keys.
	 *
	 * @return array<string, mixed> OpenRegister's paginated search result.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 */
	private function publicSearch(string $subjectId, array $paging): array {
		$objectService = $this->registers->installedObjectService();
		if ($subjectId === '' || is_object($objectService) === false || method_exists($objectService, 'runAsAnonymous') === false) {
			return ['results' => [], 'total' => 0];
		}

		$pair = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		$query = array_merge(
			$paging,
			[
				'@self' => ['register' => $pair['register'], 'schema' => $pair['schema']],
				'themes' => $subjectId,
			]
		);

		$result = $objectService->runAsAnonymous(
			static fn (): array => $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false)
		);

		if (is_array($result) === false) {
			return ['results' => [], 'total' => 0];
		}

		return $result;
	}//end publicSearch()
}//end class
