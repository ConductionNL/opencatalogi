<?php

/**
 * OpenCatalogi repair step: mirror each stored publication's case reference into its source identifiers.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Service\Publication\PublicationLinkRules;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;

/**
 * Once for every stored publication, the same mirror the pre-save listener applies to new saves.
 *
 * Idempotent: a publication whose case reference is already an entry is not
 * written, so a second run changes nothing. Never removes an entry.
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
 */
class MirrorCaseReferenceIntoSourceIdentifiers implements IRepairStep {

	/**
	 * How many publications one search page reads.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration naming the publication register and schema.
	 * @param ContainerInterface $container Server container for resolving OpenRegister.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	public function getName(): string {
		return 'Add the case reference of every publication to its source identifiers';
	}//end getName()

	/**
	 * Mirror the case reference of every stored publication that lacks the entry.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	public function run(IOutput $output): void {
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($register === '' || $schema === '') {
			$output->info('No publication register and schema configured; no source identifiers written');
			return;
		}

		$objects = $this->objectService();
		if ($objects === null) {
			$output->warning('OpenRegister is not available; no source identifiers written');
			return;
		}

		$written = 0;
		$page = 1;
		do {
			$result = $objects->searchObjectsPaginated(
				query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE_SIZE, '_page' => $page],
				_rbac: false,
				_multitenancy: false
			);
			foreach (($result['results'] ?? []) as $object) {
				$written += $this->mirror(objects: $objects, object: $object, scope: ['register' => $register, 'schema' => $schema]);
			}

			$page++;
		} while ($page <= (int)($result['pages'] ?? 1));

		$output->info('Case reference added to the source identifiers of ' . $written . ' publication(s)');
	}//end run()

	/**
	 * Write one publication's mirrored identifiers when it lacks the entry.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param mixed $object The stored publication.
	 * @param array{register: string, schema: string} $scope The publication register and schema.
	 *
	 * @return int 1 when written, else 0.
	 */
	private function mirror(object $objects, mixed $object, array $scope): int {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return 0;
		}

		$identifiers = (new PublicationLinkRules())->withMirroredCaseReference(data: $object);
		if ($identifiers === null) {
			return 0;
		}

		$object['sourceIdentifiers'] = $identifiers;
		$objects->saveObject(
			object: $object,
			register: $scope['register'],
			schema: $scope['schema'],
			uuid: (string)($object['id'] ?? $object['@self']['id'] ?? ''),
			_rbac: false,
			_multitenancy: false
		);

		return 1;
	}//end mirror()

	/**
	 * OpenRegister's ObjectService, or null when OpenRegister is missing.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end objectService()
}//end class
