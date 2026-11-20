<?php

/**
 * The most viewed publications across every catalogue the caller may read.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ranks the publications of the readable catalogues by counted views, and names them.
 *
 * A catalogue counts only when the caller may read the catalogue object, and a
 * publication only when the caller may read it under OpenRegister RBAC; either
 * check failing leaves it out (fail closed). Counts are the privacy-safe usage
 * counters, never the audit trail (ANA-005).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.3
 */
class MostViewedPublications {

	/**
	 * How many ranked publications are looked up per publication shown, at most.
	 *
	 * @var integer
	 */
	private const LOOKUPS_PER_ROW = 5;

	/**
	 * Constructor.
	 *
	 * @param UsageCounterServiceInterface $counters  The usage counters.
	 * @param CatalogiServiceInterface     $catalogs  The configured catalogues.
	 * @param ContainerInterface           $container Resolves OpenRegister's object service.
	 * @param IAppConfig                   $config    Names the publication register and schema.
	 * @param LoggerInterface              $logger    Records a failed read check.
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.3
	 */
	public function __construct(
		private readonly UsageCounterServiceInterface $counters,
		private readonly CatalogiServiceInterface $catalogs,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The most viewed readable publications in a window, most viewed first.
	 *
	 * @param string|null $from Inclusive start day (Y-m-d), or null for open.
	 * @param string|null $to   Inclusive end day (Y-m-d), or null for open.
	 * @param integer     $top  How many to return, at least 1.
	 *
	 * @return array<int, array{id: string, title: string, catalog: string, views: int, downloads: int}>
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.3
	 */
	public function top(?string $from, ?string $to, int $top): array {
		$top     = max(1, $top);
		$objects = $this->objectService();
		if ($objects === null) {
			return [];
		}

		[$rows, $catalogOf] = $this->readableCounters(objects: $objects, from: $from, to: $to);
		$ranked = $this->counters->aggregateCatalog(rows: $rows, top: PHP_INT_MAX)['topViewed'];

		$shown = [];
		foreach (array_slice($ranked, 0, ($top * self::LOOKUPS_PER_ROW)) as $entry) {
			$id    = (string)$entry['publication'];
			$title = $this->readableTitle(objects: $objects, id: $id);
			if ($title !== null) {
				$shown[] = [
					'id'        => $id,
					'title'     => $title,
					'catalog'   => $catalogOf[$id],
					'views'     => (int)$entry['views'],
					'downloads' => (int)$entry['downloads'],
				];
			}

			if (count($shown) === $top) {
				break;
			}
		}

		return $shown;
	}//end top()

	/**
	 * The counter rows of every readable catalogue, and the first catalogue each publication was counted in.
	 *
	 * @param object      $objects OpenRegister's object service.
	 * @param string|null $from    Inclusive start day.
	 * @param string|null $to      Inclusive end day.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
	 */
	private function readableCounters(object $objects, ?string $from, ?string $to): array {
		$rows      = [];
		$catalogOf = [];
		foreach ($this->catalogs->getCatalogSlugs() as $slug) {
			if ($this->catalogReadable(objects: $objects, slug: (string)$slug) === false) {
				continue;
			}

			foreach ($this->counters->getCountersForCatalog(catalog: (string)$slug, from: $from, to: $to) as $row) {
				$rows[] = $row;
				$catalogOf[(string)($row['publication'] ?? '')] ??= (string)$slug;
			}
		}

		return [$rows, $catalogOf];
	}//end readableCounters()

	/**
	 * Whether the caller may read a catalogue's own object.
	 *
	 * @param object $objects OpenRegister's object service.
	 * @param string $slug    The catalogue slug.
	 *
	 * @return boolean
	 */
	private function catalogReadable(object $objects, string $slug): bool {
		$catalog = $this->catalogs->getCatalogBySlug($slug);
		if ($catalog === null) {
			return false;
		}

		$self = (array)($catalog['@self'] ?? []);
		return $this->readable(
			objects: $objects,
			where: ['register' => (string)($self['register'] ?? ''), 'schema' => (string)($self['schema'] ?? ''), 'uuid' => (string)($catalog['id'] ?? '')]
		) !== null;
	}//end catalogReadable()

	/**
	 * A publication's title when the caller may read it, else null.
	 *
	 * @param object $objects OpenRegister's object service.
	 * @param string $id      The publication uuid.
	 *
	 * @return string|null The title, else its name, else the uuid.
	 */
	private function readableTitle(object $objects, string $id): ?string {
		$where = [
			'register' => $this->config->getValueString('opencatalogi', 'publication_register', ''),
			'schema'   => $this->config->getValueString('opencatalogi', 'publication_schema', ''),
			'uuid'     => $id,
		];
		$found = $this->readable(objects: $objects, where: $where);
		if ($found === null) {
			return null;
		}

		foreach ([($found['title'] ?? ''), ($found['@self']['name'] ?? '')] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return $id;
	}//end readableTitle()

	/**
	 * The one object at a register, schema and uuid the caller may read, as an array, or null.
	 *
	 * @param object                $objects OpenRegister's object service.
	 * @param array<string, string> $where   The register, schema and uuid; none may be empty.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readable(object $objects, array $where): ?array {
		if (in_array('', $where, true) === true) {
			return null;
		}

		try {
			$results = $objects->searchObjects(query: ['@self' => $where], _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[MostViewedPublications] read check failed', ['error' => $e->getMessage()]);
			return null;
		}

		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = reset($results);
		if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
			$first = $first->jsonSerialize();
		}

		if (is_array($first) === false) {
			return null;
		}

		return $first;
	}//end readable()

	/**
	 * OpenRegister's object service, or null when it is not available.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable) {
			return null;
		}
	}//end objectService()
}//end class
