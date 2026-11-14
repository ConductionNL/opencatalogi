<?php

/**
 * Rewrites the public and officer pages of every publication.
 *
 * A publication's `publicUrl` and `officerUrl` are written when it is created.
 * When an administrator changes `publication_url_template` or
 * `publication_url_catalog`, every stored page may be wrong, so a queued job
 * runs this pass and saves each publication whose pages changed
 * (publication-tells-its-source, REQ-PTS-001).
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One pass over every publication, saving those whose pages changed.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */
class PublicationLinksRewriteService {

	/**
	 * Publications read per page.
	 *
	 * @var integer
	 */
	public const PAGE = 100;

	/**
	 * The most pages one pass reads.
	 *
	 * @var integer
	 */
	public const MAX_PAGES = 1000;

	/**
	 * Constructor.
	 *
	 * @param PublicationLinks   $links     Builds both pages.
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param IAppConfig         $config    Names the publication register and schema.
	 * @param LoggerInterface    $logger    Records a publication that could not be rewritten.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function __construct(
		private readonly PublicationLinks $links,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rewrite the pages of every publication.
	 *
	 * @return array{checked: int, changed: int, failed: int} The counts.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function rewrite(): array {
		$counts   = ['checked' => 0, 'changed' => 0, 'failed' => 0];
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema   = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($register === '' || $schema === '' || $this->container->has('OCA\OpenRegister\Service\ObjectService') === false) {
			return $counts;
		}

		$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$rows = array_values(
				(array)($objects->searchObjectsPaginated(
					query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE, '_page' => $page],
					_rbac: false,
					_multitenancy: false
				)['results'] ?? [])
			);
			foreach ($rows as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$counts['checked']++;
				$outcome = $this->rewriteOne(objects: $objects, row: $row, scope: ['register' => $register, 'schema' => $schema]);
				if ($outcome !== 'unchanged') {
					$counts[$outcome]++;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		return $counts;
	}//end rewrite()

	/**
	 * Save one publication when its pages changed.
	 *
	 * @param object                                  $objects OpenRegister's object service.
	 * @param array<string, mixed>                    $row     The listed publication.
	 * @param array{register: string, schema: string} $scope   The publication register and schema.
	 *
	 * @return string `changed`, `unchanged` or `failed`.
	 */
	private function rewriteOne(object $objects, array $row, array $scope): string {
		$uuid = (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
		try {
			$links = $this->links->for(uuid: $uuid, schema: $scope['schema']);
			if ($uuid === '' || ($row['publicUrl'] ?? '') === $links['publicUrl'] && ($row['officerUrl'] ?? '') === $links['officerUrl']) {
				return 'unchanged';
			}

			$data = $row;
			unset($data['@self']);
			$objects->saveObject(
				object: array_merge($data, $links),
				register: $scope['register'],
				schema: $scope['schema'],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
			return 'changed';
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicationLinksRewriteService] The pages of publication ' . $uuid . ' could not be rewritten: ' . $e->getMessage());
			return 'failed';
		}//end try
	}//end rewriteOne()
}//end class
