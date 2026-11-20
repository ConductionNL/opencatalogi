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

use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
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
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: ObjectCreatedEventListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: ObjectUpdatedEventListener::class
		);

		// Woo citizen journey (citizen-collections, REQ-CCOL-007): when portaliq
		// marks a portal account removed, delete that resident's dossiers and
		// saved searches. portaliq has no removal event; the account update is it.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: PortalAccountRemovedListener::class
		);

		// PLOOI delivery (woo-national-delivery-repair, REQ-WND-003): queue a
		// delivery when a publication has just become public.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: PlooiDeliveryListener::class
		);

		// Queue a readiness check when a catalogue is switched on for Woo (REQ-WIH-004).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: WooReadinessTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: WooReadinessTriggerListener::class
		);

		// Register catalog cache event listeners.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: CatalogCacheEventListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: CatalogCacheEventListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: CatalogCacheEventListener::class
		);

		// Register catalog rewrite event listeners on the *pre-save* events so the
		// listener can mutate the in-flight payload via `setModifiedData(...)` instead
		// of issuing a second save. Subscribing to the post-save events caused an
		// infinite event loop on every catalog update/soft-delete.
		$context->registerEventListener(ObjectCreatingEvent::class, CatalogSchemaEventListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, CatalogSchemaEventListener::class);

		// Catalogue staff access (catalogue-staff-access): refuse a change by a
		// non-administrator before the write, recompute the covered schemas' rules after it.
		$catalogAccessEvents = [
			ObjectCreatingEvent::class,
			ObjectUpdatingEvent::class,
			ObjectCreatedEvent::class,
			ObjectUpdatedEvent::class,
			ObjectDeletedEvent::class,
		];
		foreach ($catalogAccessEvents as $catalogAccessEvent) {
			$context->registerEventListener($catalogAccessEvent, CatalogAccessListener::class);
		}

		// Capability rights (woo-access-boundary-hardening REQ-WAB-004): declare the
		// eight verbs to OpenRegister and decide them from the catalog schema's grants.
		$context->registerEventListener(PermissionsDeclaringEvent::class, CapabilityDeclarationListener::class);
		$context->registerEventListener(CustomScopeEvaluatingEvent::class, CapabilityScopeListener::class);

		$this->registerWriteGuards(context: $context);

		// Refuse a self-relation and mirror caseReference into sourceIdentifiers (REQ-PRS-001, REQ-PRS-003).
		$context->registerEventListener(ObjectCreatingEvent::class, PublicationRelationsListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, PublicationRelationsListener::class);

		// Document references (integration-publish-by-reference): refuse a URL
		// that is not a public https address before OpenRegister stores it.
		$context->registerEventListener(ObjectCreatingEvent::class, DocumentReferenceListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, DocumentReferenceListener::class);

		// A Woo publication does not go public without its mandatory DiWoo metadata,
		// and a document type is stored as its list member's URI (REQ-DWP-002, REQ-DWP-003).
		$context->registerEventListener(ObjectCreatingEvent::class, DiwooCompletenessListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, DiwooCompletenessListener::class);

		// A plain language summary is saved with its reading level, and its
		// provenance is the server's to write (publication-plain-language-and-translation, REQ-PPL-001).
		$context->registerEventListener(ObjectCreatingEvent::class, PlainSummaryCheck::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, PlainSummaryCheck::class);

		// A Woo publication is public by law: it cannot be limited to signed-in
		// readers (publications-for-signed-in-readers, REQ-PSR-004).
		$context->registerEventListener(ObjectCreatingEvent::class, PublicationAudienceCheck::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, PublicationAudienceCheck::class);

		// A Woo batch whose review task is rejected in OpenRegister goes back to its
		// author with the task's comment (woo-review-surface, REQ-WRV-002).
		$context->registerEventListener(TaskTerminalEvent::class, BatchReviewTaskListener::class);

		// A publication records whether it is complete and public; a source
		// system subscribes to the move to complete (publication-tells-its-source, REQ-PTS-002).
		$context->registerEventListener(ObjectCreatingEvent::class, CompletenessListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, CompletenessListener::class);

		// A publication carries its public page and its officer page (REQ-PTS-001).
		$context->registerEventListener(ObjectCreatingEvent::class, PublicationLinksListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, PublicationLinksListener::class);

		// A record a published collection publishes is filed under the collection's
		// Woo category before it is written, so it lands in that category's sitemap
		// (capability-rows-verify-scenarios, REQ-CVS-003).
		$context->registerEventListener(ObjectCreatingEvent::class, RuleCategoryListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, RuleCategoryListener::class);

		// A seeded catalogue can name another app's register by slug (the
		// applicatielandschap catalogue names stackiq's). When that app is
		// installed after OpenCatalogi, its register event completes the scope
		// (publish-from-stackiq, REQ-PFS-004).
		$context->registerEventListener(RegisterCreatedEvent::class, CatalogScopePendingListener::class);
		$context->registerEventListener(RegisterUpdatedEvent::class, CatalogScopePendingListener::class);

		// Register tool registration listener for OpenRegister agents.
		$context->registerEventListener(
			event: ToolRegistrationEvent::class,
			listener: ToolRegistrationListener::class
		);

		// Provide the manifest-config initial state on every SPA `index` render,
		// controller-independent. After the AppHost adoption (ADR-040) the `/`
		// index route resolves to OpenRegister's shared AppHost dashboard
		// controller, which serves templates/index.php without OpenCatalogi's
		// register/schema config, so a clean install then rendered no SPA. Hooking
		// the provision to BeforeTemplateRenderedEvent restores it for both the
		// AppHost `/` page and the UiController-served deep-link routes.
		$context->registerEventListener(
			event: BeforeTemplateRenderedEvent::class,
			listener: ProvideManifestConfigStateListener::class
		);

	}//end register()

	/**
	 * The listeners that check an object before OpenRegister writes it.
	 *
	 * A statutory end date written through any API lands on a working day
	 * (inspection-period-rolls-on-the-calendar, REQ-IPR-002). Once every stored
	 * Woo request moved to dossiq, the request schema is read-only here except
	 * for dossiq's stamp (woo-request-intake-hands-over-to-dossiq, REQ-WHD-004).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
	 */
	private function registerWriteGuards(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, TermRollListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, TermRollListener::class);
		$context->registerEventListener(ObjectCreatingEvent::class, HandedOverRecordsListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, HandedOverRecordsListener::class);
	}//end registerWriteGuards()
}//end class
