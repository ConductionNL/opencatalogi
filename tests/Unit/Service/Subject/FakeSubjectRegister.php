<?php

/**
 * An in-memory OpenRegister ObjectService for the subject tests.
 *
 * Holds themes and publications. A publication search inside runAsAnonymous()
 * returns only what the publication schema's public read rule admits (a
 * publication date in the past, not withdrawn); outside it, every publication
 * comes back, as it would for a signed-in administrator.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Subject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;

/**
 * An in-memory OpenRegister ObjectService for the subject tests.
 */
class FakeSubjectRegister extends ObjectService {

	public const THEME_SCHEMA = '10';
	public const PUBLICATION_SCHEMA = '20';

	/**
	 * Every search the code made, with whether it ran anonymously.
	 *
	 * @var array<int, array{query: array<string, mixed>, anonymous: bool}>
	 */
	public array $searches = [];

	private bool $anonymous = false;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $themes       The theme objects.
	 * @param array<int, array<string, mixed>> $publications The publication objects.
	 */
	public function __construct(
		private array $themes = [],
		private array $publications = [],
	) {
	}

	public function runAsAnonymous(callable $operation): mixed {
		$this->anonymous = true;
		try {
			return $operation();
		} finally {
			$this->anonymous = false;
		}
	}

	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?ObjectEntity {
		foreach ($this->themes as $theme) {
			if ((string)$theme['id'] === (string)$id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$theme['id']);
				$entity->setSchema(self::THEME_SCHEMA);
				$entity->setObject(array_diff_key($theme, ['id' => true, '@self' => true]));
				return $entity;
			}
		}

		return null;
	}

	public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
	): array {
		$this->searches[] = ['query' => $query, 'anonymous' => $this->anonymous];
		$schema = (string)($query['@self']['schema'] ?? '');
		$rows = $schema === self::THEME_SCHEMA ? $this->matchingThemes(query: $query) : $this->matchingPublications(query: $query);
		$total = count($rows);
		$limit = (int)($query['_limit'] ?? 20);
		$page = (int)($query['_page'] ?? 1);
		return ['results' => array_slice($rows, ($page - 1) * $limit, $limit), 'total' => $total];
	}

	private function matchingThemes(array $query): array {
		return array_values(
			array_filter(
				$this->themes,
				static fn (array $theme): bool => (isset($query['featured']) === false || ($theme['featured'] ?? false) === $query['featured'])
					&& (isset($query['slug']) === false || ($theme['slug'] ?? null) === $query['slug'])
			)
		);
	}

	private function matchingPublications(array $query): array {
		$now = gmdate('c');
		$rows = array_values(
			array_filter(
				$this->publications,
				fn (array $publication): bool => in_array($query['themes'] ?? null, ($publication['themes'] ?? []), true)
					&& ($this->anonymous === false
					|| (isset($publication['publicationDate']) === true && $publication['publicationDate'] <= $now && isset($publication['depublicationDate']) === false))
			)
		);
		if (($query['_order']['publicationDate'] ?? null) === 'DESC') {
			usort($rows, static fn (array $a, array $b): int => strcmp((string)($b['publicationDate'] ?? ''), (string)($a['publicationDate'] ?? '')));
		}

		return $rows;
	}
}
