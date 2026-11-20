<?php

/**
 * OpenCatalogi capability scope listener: decides the eight Woo verbs from the catalog schema's grants.
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
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Decides the capability verbs (REQ-WAB-004).
 *
 * An administrator is allowed. Anyone else is allowed only when one of their
 * groups is listed under the verb in the `catalog` schema's authorization
 * block. Everything else, including a block or a group list that cannot be
 * read, is denied.
 *
 * @template-implements IEventListener<Event>
 */
class CapabilityScopeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Tells administrators apart.
	 * @param LoggerInterface $logger       The logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Vote on a capability verb asked of the catalog schema; leave every other check alone.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof CustomScopeEvaluatingEvent === false) {
			return;
		}

		$verb = $event->getAction();
		if (array_key_exists($verb, CapabilityRights::VERBS) === false) {
			return;
		}

		try {
			if ($event->getSchema()->getSlug() !== CapabilityRights::CATALOG_SCHEMA) {
				return;
			}

			if ($this->granted(event: $event, verb: $verb) === true) {
				$event->allow();
				return;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[CapabilityScopeListener] The grants for '.$verb.' could not be read: '.$e->getMessage());
		}

		$event->deny();

	}//end handle()

	/**
	 * Whether the caller is an administrator or in a group granted the verb.
	 *
	 * @param CustomScopeEvaluatingEvent $event The event.
	 * @param string                     $verb  The verb.
	 *
	 * @return bool True when granted.
	 */
	private function granted(CustomScopeEvaluatingEvent $event, string $verb): bool {
		$userId = $event->getUserId();
		if ($userId === null || $userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$authorization = $event->getSchema()->getAuthorization();
		if (is_array($authorization) === false || is_array($authorization[$verb] ?? null) === false) {
			return false;
		}

		$granted = array_filter($authorization[$verb], 'is_string');
		$groups  = array_filter($event->getUserGroups(), 'is_string');

		return array_intersect($groups, $granted) !== [];

	}//end granted()
}//end class
