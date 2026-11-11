<?php

/**
 * Inspection windows and their stored records, for the inspection controller.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\InspectionServiceInterface;
use OCA\OpenCatalogi\Service\ObjectInPair;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\SaveActor;

/**
 * Opens an inspection window and resolves its link, and stores and reads the
 * inspection in the publication register's inspection schema.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */
class InspectionRecords {
	use ForwardsRegisterAccess;

	/**
	 * Constructor.
	 *
	 * @param InspectionServiceInterface $inspections The inspection windows.
	 * @param RegisterAccess $registers The configured register pairs and the ObjectService, refusing rather than defaulting.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly InspectionServiceInterface $inspections,
		private readonly RegisterAccess $registers,
	) {

	}//end __construct()

	/**
	 * Open an inspection window over a record's chosen documents.
	 *
	 * @param array{record: array, recordType: array, documents: array} $parameters The record, its type and the chosen documents.
	 * @param string $openedBy The user id of the administrator.
	 *
	 * @return array<string, mixed> The inspection to save.
	 *
	 * @throws \DomainException When the type declares no term, or no document was chosen.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function open(array $parameters, string $openedBy): array {
		return $this->inspections->open(
			record: $parameters['record'],
			recordType: $parameters['recordType'],
			documents: array_map('strval', $parameters['documents']),
			openedBy: $openedBy
		);

	}//end open()

	/**
	 * Resolve an inspection link at this moment.
	 *
	 * @param array<string, mixed> $inspection The stored inspection.
	 * @param string $token The token from the link.
	 *
	 * @return array{readable: boolean, documents: array<int, string>, reason: string|null, endDate: string|null}
	 *
	 * @throws \DomainException When the window cannot be read.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function resolveLink(array $inspection, string $token): array {
		return $this->inspections->resolveLink(inspection: $inspection, token: $token);
	}//end resolveLink()

	/**
	 * Store an inspection in the configured register and schema, without RBAC.
	 *
	 * The inspection schema names group admin, which leaves out the delegated
	 * administrators the route's admin-setting check lets in. That check is the
	 * gate, so the save skips RBAC. The system owns the record, so whoever opened
	 * it cannot rewrite or delete it.
	 *
	 * @param array<string, mixed> $inspection The inspection to save.
	 *
	 * @return mixed The saved object.
	 *
	 * @throws \Throwable When the register is unconfigured or unreadable, or the save fails.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function store(array $inspection): mixed {
		$config = $this->inspectionConfiguration();
		return $this->registers->getObjectService()->saveObject(
			object: $inspection,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $config['register'], schema: $config['schema']),
				actor: new SaveActor(unowned: true)
			),
			_rbac: false
		);

	}//end store()

	/**
	 * Read a stored inspection as a flat array, without RBAC.
	 *
	 * The inspection schema is readable by administrators only, and the link is
	 * followed anonymously, so the read skips RBAC. The caller must not answer
	 * anything from the result before the presented token matched it
	 * (see resolveLink()).
	 *
	 * @param string $id The inspection id.
	 *
	 * @return array<string, mixed> The inspection properties with its id.
	 *
	 * @throws \Throwable When the register is unconfigured or unreadable, or the inspection is unknown.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	public function find(string $id): array {
		$config = $this->inspectionConfiguration();
		return $this->toArray(
			object: ObjectInPair::require(
				found: $this->registers->getObjectService()->find(
					id: $id,
					register: $config['register'],
					schema: $config['schema'],
					_rbac: false
				),
				config: $config
			)
		);

	}//end find()

	/**
	 * Normalise an OpenRegister result to a plain array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec exclude pure framework plumbing: normalises an OpenRegister result.
	 */
	public function toArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;
	}//end toArray()

	/**
	 * The register and schema the inspections live in.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function inspectionConfiguration(): array {
		return $this->registerConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'inspection_schema'
		);

	}//end inspectionConfiguration()
}//end class
