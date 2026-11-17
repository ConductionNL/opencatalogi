<?php

/**
 * OpenCatalogi MetadataSuggestionListener
 *
 * Queues the rules floor for a publication when it is created.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\BackgroundJob\SuggestPublicationMetadata;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Queue the metadata rules floor for a new object in the publication register (REQ-WMS-001).
 *
 * The save does not wait: the listener only adds a job. Catalogues and the
 * suggestions themselves live in the same register and are left alone, so a
 * stored suggestion never queues another round.
 *
 * @template-implements IEventListener<Event>
 */
class MetadataSuggestionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Holds the publication register and the schemas to skip.
	 * @param IJobList $jobList Where the job is queued.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Queue the job for an object created in the publication register.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$this->queue(object: $event->getObject());
		} catch (\Throwable $e) {
			// The save goes on whatever happens here.
			$this->logger->error(
				message: '[MetadataSuggestionListener] could not queue suggestions: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * Queue the job for the object when it is a publication-register object other than a suggestion or catalogue.
	 *
	 * @param object $object The created object entity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function queue(object $object): void {
		$register = (string)$object->getRegister();
		$schema = (string)$object->getSchema();
		$uuid = (string)$object->getUuid();
		if ($uuid === '' || $register === '' || $register !== $this->setting(key: 'publication_register')) {
			return;
		}

		$skip = [$this->setting(key: 'metadata_suggestion_schema'), $this->setting(key: 'catalog_schema')];
		if (in_array($schema, $skip, true) === true) {
			return;
		}

		$this->jobList->add(
			SuggestPublicationMetadata::class,
			['register' => $register, 'schema' => $schema, 'id' => $uuid]
		);
	}//end queue()

	/**
	 * One trimmed app setting.
	 *
	 * @param string $key The setting.
	 *
	 * @return string Its value, empty when unset.
	 */
	private function setting(string $key): string {
		return trim($this->config->getValueString('opencatalogi', $key, ''));
	}//end setting()
}//end class
