<?php

/**
 * OpenCatalogi capability declaration listener: declares the eight Woo verbs to OpenRegister.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Declares the capability verbs at the schema level (REQ-WAB-004).
 *
 * @template-implements IEventListener<Event>
 */
class CapabilityDeclarationListener implements IEventListener {

	/**
	 * Declare every verb of CapabilityRights::VERBS for app opencatalogi.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof PermissionsDeclaringEvent === false) {
			return;
		}

		foreach (CapabilityRights::VERBS as $verb => $description) {
			$event->declareVerb(verb: $verb, app: 'opencatalogi', description: $description, levels: ['schema']);
		}

	}//end handle()
}//end class
