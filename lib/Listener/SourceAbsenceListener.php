<?php

/**
 * OpenCatalogi source absence listener.
 *
 * A publication harvested from an external source is maintained by a
 * synchronisation that declares what happens when the source stops carrying
 * the record. When that policy ends the record, the synchronisation writes an
 * end date on the publication and this listener takes the publication down.
 * When the policy keeps the record, the synchronisation only flags it absent;
 * the publication stays up and its page tells the editor.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\SourceAbsence;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Depublishes a publication whose source ended it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */
class SourceAbsenceListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config    Holds the publication register and schema.
	 * @param ContainerInterface $container Server container, for the consumed OR ObjectService.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Take a publication down when its source has just ended it.
	 *
	 * Failures are logged and swallowed: this runs inside OpenRegister's save
	 * and must never break the synchronisation that triggered it.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		if ($this->isPublication(entity: $new) === false) {
			return;
		}

		$absence = new SourceAbsence();
		$old = $event->getOldObject();
		$outcome = $absence->outcome(old: $this->properties(entity: $old), new: $this->properties(entity: $new));
		if ($outcome === SourceAbsence::FLAGGED) {
			$this->logger->info(
				'[SourceAbsenceListener] Publication ' . (string)$new->getUuid() . ' is no longer at its source; kept and flagged for an editor.'
			);
			return;
		}

		if ($outcome !== SourceAbsence::DEPUBLISHED) {
			return;
		}

		$depublished = $absence->depublished(publication: $this->properties(entity: $new));
		if ($depublished === null) {
			return;
		}

		$this->save(entity: $new, data: $depublished);
	}//end handle()

	/**
	 * Whether the object is in the configured publication register and schema.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True for a publication.
	 */
	private function isPublication(ObjectEntity $entity): bool {
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		return (string)$entity->getRegister() === $register && (string)$entity->getSchema() === $schema;
	}//end isPublication()

	/**
	 * The object's properties, or none for a missing object.
	 *
	 * @param ObjectEntity|null $entity The object.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function properties(?ObjectEntity $entity): array {
		if ($entity === null) {
			return [];
		}

		return ($entity->getObject() ?? []);
	}//end properties()

	/**
	 * Save the depublished publication outside RBAC: the system acts on the synchronisation's word.
	 *
	 * @param ObjectEntity         $entity The publication.
	 * @param array<string, mixed> $data   Its properties with the depublication date.
	 *
	 * @return void
	 */
	private function save(ObjectEntity $entity, array $data): void {
		try {
			$this->container->get('OCA\OpenRegister\Service\ObjectService')->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
						register: (string)$entity->getRegister(),
						schema: (string)$entity->getSchema()
					),
					uuid: (string)$entity->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
			$this->logger->info(
				'[SourceAbsenceListener] Publication ' . (string)$entity->getUuid() . ' depublished: its source ended it.'
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[SourceAbsenceListener] Could not depublish publication ' . (string)$entity->getUuid() . ': ' . $e->getMessage()
			);
		}
	}//end save()
}//end class
