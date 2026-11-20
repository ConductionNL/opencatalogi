<?php

/**
 * One publication's metadata-quality score, for its staff detail page.
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

use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\QualityServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scores the dataset a publication of a catalogue renders as, before or after it is published.
 *
 * The publication is read under the caller's OpenRegister RBAC in one of the
 * catalogue's registers and schemas; one the caller cannot read is not scored
 * (no IDOR). The score is advisory and never gates publishing (PQM-004).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
class PublicationQuality {

	/**
	 * Constructor.
	 *
	 * @param DcatServiceInterface    $dcat      Renders the publication as a dcat:Dataset.
	 * @param QualityServiceInterface $quality   Scores a dataset over the MQA dimensions.
	 * @param ContainerInterface      $container Resolves OpenRegister's object service.
	 * @param LoggerInterface         $logger    Records a failed read.
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
	 */
	public function __construct(
		private readonly DcatServiceInterface $dcat,
		private readonly QualityServiceInterface $quality,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The score of a publication in a catalogue, or null when the caller cannot read it there.
	 *
	 * @param array  $catalog     The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 * @param string $id          The publication uuid.
	 *
	 * @return array{total: int, dimensions: array<string, int>, missing: array<int, string>}|null
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
	 */
	public function score(array $catalog, string $catalogSlug, string $id): ?array {
		$publication = $this->readablePublication(catalog: $catalog, id: $id);
		if ($publication === null) {
			return null;
		}

		$node = $this->dcat->buildPublicationDataset(catalog: $catalog, catalogSlug: $catalogSlug, publication: $publication);
		return $this->quality->scoreDataset($node);
	}//end score()

	/**
	 * The publication in one of the catalogue's registers and schemas, read under the caller's RBAC.
	 *
	 * @param array  $catalog The catalog object.
	 * @param string $id      The publication uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readablePublication(array $catalog, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable) {
			return null;
		}

		foreach ($this->ids(raw: ($catalog['registers'] ?? [])) as $register) {
			foreach ($this->ids(raw: ($catalog['schemas'] ?? [])) as $schema) {
				$found = $this->first(objects: $objects, where: ['register' => $register, 'schema' => $schema, 'uuid' => $id]);
				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
	}//end readablePublication()

	/**
	 * The first object a search finds under the caller's RBAC, as an array, or null.
	 *
	 * @param object                $objects OpenRegister's object service.
	 * @param array<string, string> $where   The register, schema and uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(object $objects, array $where): ?array {
		try {
			$results = $objects->searchObjects(query: ['@self' => $where], _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicationQuality] publication read failed', ['error' => $e->getMessage()]);
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
	}//end first()

	/**
	 * A catalogue's register or schema list as non-empty id strings.
	 *
	 * @param mixed $raw The stored list: ids, or objects with an id.
	 *
	 * @return array<int, string>
	 */
	private function ids(mixed $raw): array {
		$ids = [];
		foreach ((array)$raw as $entry) {
			if (is_array($entry) === true) {
				$entry = ($entry['id'] ?? '');
			}

			if (is_scalar($entry) === true && (string)$entry !== '') {
				$ids[] = (string)$entry;
			}
		}

		return $ids;
	}//end ids()
}//end class
