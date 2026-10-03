<?php

/**
 * OpenCatalogi Woo Readiness Trigger Listener.
 *
 * Queues one readiness check when a catalogue starts publishing a Woo sitemap:
 * created with `hasWooSitemap` true, or updated from not-true to true. The
 * check itself runs in the background, so the save never waits on it.
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\BackgroundJob\WooReadinessCheckNow;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Queues the readiness check when a catalogue is switched on for Woo.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
 */
class WooReadinessTriggerListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config  The catalogue register and schema.
	 * @param IJobList   $jobList The background job list.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IJobList $jobList,
	) {

	}//end __construct()

	/**
	 * Queue the check when a catalogue's `hasWooSitemap` becomes true.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-readiness-verdict-stays-current-without-anyone-running-it-req-wih-004
	 */
	public function handle(Event $event): void {
		$new = null;
		$old = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$new = $event->getNewObject();
			$old = $event->getOldObject();
		}

		if ($event instanceof ObjectCreatedEvent) {
			$new = $event->getObject();
		}

		if ($new === null) {
			return;
		}

		if ($this->isCatalogue(object: $new) === false || $this->publishesWoo(object: $new) === false) {
			return;
		}

		if ($old !== null && $this->publishesWoo(object: $old) === true) {
			return;
		}

		$this->jobList->add(WooReadinessCheckNow::class);

	}//end handle()

	/**
	 * Whether the object is a catalogue of the configured register and schema.
	 *
	 * @param object $object The object.
	 *
	 * @return boolean True for a catalogue.
	 */
	private function isCatalogue(object $object): bool {
		$schema = $this->config->getValueString('opencatalogi', 'catalog_schema', '');
		$register = $this->config->getValueString('opencatalogi', 'catalog_register', '');
		if ($schema === '' || $register === '') {
			return false;
		}

		return (string)$object->getSchema() === $schema && (string)$object->getRegister() === $register;

	}//end isCatalogue()

	/**
	 * Whether the catalogue publishes a Woo sitemap.
	 *
	 * @param object $object The catalogue.
	 *
	 * @return boolean True when `hasWooSitemap` is true.
	 */
	private function publishesWoo(object $object): bool {
		return (($object->getObject()['hasWooSitemap'] ?? false) === true);

	}//end publishesWoo()
}//end class
