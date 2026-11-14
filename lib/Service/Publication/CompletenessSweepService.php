<?php

/**
 * Re-evaluates publications that became public, or stopped being public, by date.
 *
 * A scheduled publication becomes public when its publication date passes,
 * without anyone writing it, so no save evaluates it. This pass finds the
 * publications whose publication or depublication date passed since the
 * previous pass, evaluates them, and saves those whose completeness changed.
 * The save is an ordinary OpenRegister update, so a source system subscribed
 * to the move to complete is told (publication-tells-its-source, REQ-PTS-002).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One sweep over the publications whose dates passed in a window.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */
class CompletenessSweepService {

	/**
	 * Publications read per page.
	 *
	 * @var integer
	 */
	public const PAGE = 100;

	/**
	 * The most pages one sweep reads.
	 *
	 * @var integer
	 */
	public const MAX_PAGES = 100;

	/**
	 * The dates whose passing changes whether a publication is public.
	 *
	 * @var array<int, string>
	 */
	private const DATES = ['publicationDate', 'depublicationDate'];

	/**
	 * Constructor.
	 *
	 * @param CompletenessService $completeness Evaluates a publication.
	 * @param ContainerInterface  $container    Resolves OpenRegister's object service.
	 * @param IAppConfig          $config       Names the publication register and schema.
	 * @param LoggerInterface     $logger       Records a publication that could not be swept.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function __construct(
		private readonly CompletenessService $completeness,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Re-evaluate every publication whose publication or depublication date lies in (since, now].
	 *
	 * @param DateTimeInterface $since The previous pass.
	 * @param DateTimeInterface $now   This pass.
	 *
	 * @return array{checked: int, changed: int, failed: int} The counts.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function sweep(DateTimeInterface $since, DateTimeInterface $now): array {
		$counts   = ['checked' => 0, 'changed' => 0, 'failed' => 0];
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema   = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($register === '' || $schema === '' || $this->container->has('OCA\OpenRegister\Service\ObjectService') === false) {
			return $counts;
		}

		$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$rows = $this->page(objects: $objects, register: $register, schema: $schema, page: $page);
			foreach ($rows as $row) {
				if (is_array($row) === false || $this->datePassed(publication: $row, since: $since, now: $now) === false) {
					continue;
				}

				$counts['checked']++;
				$outcome = $this->refresh(objects: $objects, id: $this->idOf(row: $row), register: $register, schema: $schema, now: $now);
				if ($outcome !== 'unchanged') {
					$counts[$outcome]++;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		return $counts;
	}//end sweep()

	/**
	 * One page of publications.
	 *
	 * @param object $objects  OpenRegister's object service.
	 * @param string $register The publication register.
	 * @param string $schema   The publication schema.
	 * @param int    $page     The page, from 1.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function page(object $objects, string $register, string $schema, int $page): array {
		$result = $objects->searchObjectsPaginated(
			query: [
				'@self'  => ['register' => $register, 'schema' => $schema],
				'_limit' => self::PAGE,
				'_page'  => $page,
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values((array)($result['results'] ?? []));
	}//end page()

	/**
	 * Evaluate one publication and save it when its completeness changed.
	 *
	 * @param object            $objects  OpenRegister's object service.
	 * @param string            $id       The publication's uuid.
	 * @param string            $register The publication register.
	 * @param string            $schema   The publication schema.
	 * @param DateTimeInterface $now      This pass.
	 *
	 * @return string `changed`, `unchanged` or `failed`.
	 */
	private function refresh(object $objects, string $id, string $register, string $schema, DateTimeInterface $now): string {
		try {
			$entity = $objects->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
			if ($entity instanceof ObjectEntity === false) {
				return 'failed';
			}

			$data  = (array)$entity->getObject();
			$value = $this->completeness->evaluate(publication: $data, object: $entity, now: $now);
			if ($value === ($data['completeness'] ?? CompletenessService::INCOMPLETE)) {
				return 'unchanged';
			}

			$objects->saveObject(
				object: array_merge($data, $this->completeness->propertiesFor(value: $value, previous: $data, now: $now)),
				register: $register,
				schema: $schema,
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
			return 'changed';
		} catch (\Throwable $e) {
			$this->logger->warning('[CompletenessSweepService] Publication ' . $id . ' could not be swept: ' . $e->getMessage());
			return 'failed';
		}//end try
	}//end refresh()

	/**
	 * Whether the publication's publication or depublication date lies in (since, now].
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param DateTimeInterface    $since       The previous pass.
	 * @param DateTimeInterface    $now         This pass.
	 *
	 * @return bool
	 */
	private function datePassed(array $publication, DateTimeInterface $since, DateTimeInterface $now): bool {
		foreach (self::DATES as $field) {
			$value = $publication[$field] ?? null;
			if (is_string($value) === false || trim($value) === '') {
				continue;
			}

			try {
				$at = new DateTimeImmutable($value);
			} catch (\Exception) {
				continue;
			}

			if ($at > $since && $at <= $now) {
				return true;
			}
		}

		return false;
	}//end datePassed()

	/**
	 * The uuid of a listed publication.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid.
	 */
	private function idOf(array $row): string {
		return (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
	}//end idOf()
}//end class
