<?php

/**
 * Embargo lifts, for the embargo lift controller.
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
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-lifting-an-embargo-takes-a-named-right-and-a-reason-and-is-logged-req-psg-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IUser;

/**
 * Finds the publication, says whether the caller may lift its embargo, publishes it at the
 * lifted moment and writes the one audit entry.
 *
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-lifting-an-embargo-takes-a-named-right-and-a-reason-and-is-logged-req-psg-002
 */
class EmbargoLifts {
	use ReadsObjectProperties;

	/**
	 * The audit action of a lift.
	 */
	public const ACTION = 'publication.embargo.lifted';

	/**
	 * OpenRegister's audit trail mapper.
	 */
	private const AUDIT_MAPPER = 'OCA\OpenRegister\Db\AuditTrailMapper';

	/**
	 * Constructor.
	 *
	 * @param PublicationRightsInterface    $rights       The update right on one publication.
	 * @param PublicationTransitions        $registers    The record publish now stores, the register pairs and ObjectService.
	 * @param PublishTransition             $publisher    Runs the publish transition, and names who may lift an embargo.
	 * @param OpenRegisterServicesInterface $openRegister Reaches OpenRegister's audit trail when it is installed.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationRightsInterface $rights,
		private readonly PublicationTransitions $registers,
		private readonly PublishTransition $publisher,
		private readonly OpenRegisterServicesInterface $openRegister,
	) {
	}//end __construct()

	/**
	 * The publication, read with the caller's rights.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication, or null when it is not there or the caller cannot read it.
	 *
	 * @throws \Throwable When the register is not configured or OpenRegister fails otherwise.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function find(string $id): ?object {
		$pair = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');

		try {
			return $this->registers->getObjectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $pair['register'], schema: $pair['schema']),
				_rbac: true
			);
		} catch (\Throwable $e) {
			// Not there, or not readable by this caller: both answer 404.
			if (str_ends_with(get_class($e), 'DoesNotExistException') === true || str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
				return null;
			}

			throw $e;
		}
	}//end find()

	/**
	 * Whether the caller may lift the embargo: in a lift group, and allowed to update the publication.
	 *
	 * @param object     $publication The publication.
	 * @param IUser|null $caller      The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function mayLift(object $publication, ?IUser $caller): bool {
		return $this->publisher->mayLiftEmbargo(caller: $caller) === true && $this->rights->mayUpdate(publication: $publication) === true;
	}//end mayLift()

	/**
	 * Publish the publication at $moment: a stored `published` (scheduled) one gets the date,
	 * any other runs the publish transition with it, as the session user.
	 *
	 * @param string $id          The publication id.
	 * @param object $publication The publication as stored.
	 * @param string $moment      The publication date to write (ATOM).
	 *
	 * @return object The publication as stored after the lift.
	 *
	 * @throws \Throwable A refused publish (PublishRefusedException when the lifecycle allows none from the
	 *                    stored state), or whatever OpenRegister refuses.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PublishTransition::storedState() is a pure state helper.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function lift(string $id, object $publication, string $moment): object {
		$properties = $this->asArray(object: $publication);
		$stored = PublishTransition::storedState(stored: (string)($properties['status'] ?? ''));
		if ($stored !== 'published') {
			return $this->publisher->publish(id: $id, stored: $stored, publicationDate: $moment);
		}

		$record = $this->registers->publishedRecord(publication: $properties);
		$record['publicationDate'] = $moment;
		unset($record['id']);
		$pair = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');

		return $this->registers->getObjectService()->saveObject(
			object: $record,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $pair['register'], schema: $pair['schema']), uuid: $id),
			_rbac: true
		);
	}//end lift()

	/**
	 * The lifted moment: now when none is given, the given one as ATOM, or null when it does not read as a date.
	 *
	 * @param mixed $value The `publicationDate` parameter.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function moment(mixed $value): ?string {
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			return (new DateTimeImmutable())->format(DATE_ATOM);
		}

		if (is_string($value) === false) {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format(DATE_ATOM);
		} catch (\Throwable $e) {
			return null;
		}
	}//end moment()

	/**
	 * The publication's properties.
	 *
	 * @param object $publication The publication.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function properties(object $publication): array {
		return $this->asArray(object: $publication);
	}//end properties()

	/**
	 * Write the one audit entry of a lift, naming the reason and both dates.
	 *
	 * @param object               $before  The publication before the lift.
	 * @param object               $after   The publication after the lift.
	 * @param array<string, mixed> $context The user, the reason, the previous and the new date.
	 *
	 * @return void
	 *
	 * @throws \Throwable When OpenRegister is not installed or the entry cannot be written.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	public function log(object $before, object $after, array $context): void {
		$this->openRegister->installedService(self::AUDIT_MAPPER, 'OpenRegister is not available.')->createAuditTrail(
			old: $before,
			new: $after,
			action: self::ACTION,
			cascadeContext: $context
		);
	}//end log()
}//end class
