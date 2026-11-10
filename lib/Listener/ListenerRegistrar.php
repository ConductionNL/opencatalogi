<?php

/**
 * Registers the event listeners of OpenCatalogi.
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

/**
 * Registers the event listeners of OpenCatalogi.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A wiring class names every listener and event it subscribes.
 */
class ListenerRegistrar {

	/**
	 * Every event and its listener, in the order they are registered, which is
	 * the order the listeners of one event run in.
	 *
	 * @var array<int, array{0: class-string, 1: class-string}>
	 */
	private const LISTENERS = [
		[ObjectCreatedEvent::class, ObjectCreatedEventListener::class],
		[ObjectUpdatedEvent::class, ObjectUpdatedEventListener::class],
		// Woo citizen journey (citizen-collections, REQ-CCOL-007): when portaliq
		// marks a portal account removed, delete that resident's dossiers and
		// saved searches. portaliq has no removal event; the account update is it.
		[ObjectUpdatedEvent::class, PortalAccountRemovedListener::class],
		// PLOOI delivery (woo-national-delivery-repair, REQ-WND-003): queue a
		// delivery when a publication has just become public.
		[ObjectUpdatedEvent::class, PlooiDeliveryListener::class],
		// Queue a readiness check when a catalogue is switched on for Woo (REQ-WIH-004).
		[ObjectCreatedEvent::class, WooReadinessTriggerListener::class],
		[ObjectUpdatedEvent::class, WooReadinessTriggerListener::class],
		// Catalog cache event listeners.
		[ObjectCreatedEvent::class, CatalogCacheEventListener::class],
		[ObjectUpdatedEvent::class, CatalogCacheEventListener::class],
		[ObjectDeletedEvent::class, CatalogCacheEventListener::class],
		// Catalog rewrite event listeners on the *pre-save* events so the
		// listener can mutate the in-flight payload via `setModifiedData(...)` instead
		// of issuing a second save. Subscribing to the post-save events caused an
		// infinite event loop on every catalog update/soft-delete.
		[ObjectCreatingEvent::class, CatalogSchemaEventListener::class],
		[ObjectUpdatingEvent::class, CatalogSchemaEventListener::class],
		// Catalogue staff access (catalogue-staff-access): refuse a change by a
		// non-administrator before the write, recompute the covered schemas' rules after it.
		[ObjectCreatingEvent::class, CatalogAccessListener::class],
		[ObjectUpdatingEvent::class, CatalogAccessListener::class],
		[ObjectCreatedEvent::class, CatalogAccessListener::class],
		[ObjectUpdatedEvent::class, CatalogAccessListener::class],
		[ObjectDeletedEvent::class, CatalogAccessListener::class],
		// A seeded catalogue can name another app's register by slug (the
		// applicatielandschap catalogue names stackiq's). When that app is
		// installed after OpenCatalogi, its register event completes the scope
		// (publish-from-stackiq, REQ-PFS-004).
		[RegisterCreatedEvent::class, CatalogScopePendingListener::class],
		[RegisterUpdatedEvent::class, CatalogScopePendingListener::class],
		// Tool registration listener for OpenRegister agents.
		[ToolRegistrationEvent::class, ToolRegistrationListener::class],
		// Provide the manifest-config initial state on every SPA `index` render,
		// controller-independent. After the AppHost adoption (ADR-040) the `/`
		// index route resolves to OpenRegister's shared AppHost dashboard
		// controller, which serves templates/index.php without OpenCatalogi's
		// register/schema config, so a clean install then rendered no SPA. Hooking
		// the provision to BeforeTemplateRenderedEvent restores it for both the
		// AppHost `/` page and the UiController-served deep-link routes.
		[BeforeTemplateRenderedEvent::class, ProvideManifestConfigStateListener::class],
	];

	/**
	 * Register the event listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec exclude Component wiring; subscribes existing listeners, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::LISTENERS as [$event, $listener]) {
			$context->registerEventListener(event: $event, listener: $listener);
		}

	}//end register()
}//end class
