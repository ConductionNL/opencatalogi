<?php

/**
 * OpenCatalogi auto-publish listener helpers.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

/**
 * Turn an event's object into EventService input, and log what EventService did with it.
 *
 * Consuming listeners MUST expose a `LoggerInterface $logger` property.
 *
 * @spec openspec/specs/auto-publishing/spec.md
 */
trait ReportsAutoPublishOutcome {
	/**
	 * The object's serialised data with its id, uuid, register and schema in `@self`.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $objectEntity The object entity.
	 *
	 * @return array The object data.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function eventObjectData(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): array {
		$objectData = $objectEntity->jsonSerialize();
		if (isset($objectData['@self']) === false) {
			$objectData['@self'] = [];
		}

		$objectData['@self']['id'] = $objectEntity->getUuid();
		$objectData['@self']['uuid'] = $objectEntity->getUuid();
		$objectData['@self']['register'] = $objectEntity->getRegister();
		$objectData['@self']['schema'] = $objectEntity->getSchema();

		return $objectData;
	}//end eventObjectData()

	/**
	 * Log a processed event and each error EventService reported.
	 *
	 * @param array $result The EventService result.
	 * @param array<string, mixed> $about The event name ('creation' or 'update') and the object id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function logAutoPublishOutcome(array $result, array $about): void {
		if ($result['processed'] > 0) {
			$this->logger->info(
				message: 'OpenCatalogi: Processed object ' . $about['event'] . ' event',
				context: [
					'objectId' => $about['objectId'],
					'published' => $result['published'],
					'attachmentsPublished' => $result['attachmentsPublished'],
				]
			);
		}

		foreach (($result['errors'] ?? []) as $error) {
			$this->logger->error(
				message: 'OpenCatalogi: Error processing object ' . $about['event'] . ' event',
				context: ['error' => $error, 'objectId' => $about['objectId']]
			);
		}
	}//end logAutoPublishOutcome()
}//end trait
