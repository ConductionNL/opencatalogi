<?php

/**
 * OpenCatalogi Portal Contribution Provider.
 *
 * OpenCatalogi's contribution to portaliq, the shared portal for residents
 * (hydra ADR-046, contract v2 and manifest v3). portaliq finds this class by
 * its conventional name `OCA\OpenCatalogi\Portal\PortalContributionProvider`
 * and duck-types it, so the class is plain: no portaliq import, no
 * `implements`, and only an optional service for `dossierItems()`. Without
 * portaliq it is inert.
 *
 * It gives a signed-in resident, in the audience `citizen` or `client` (DigiD
 * currently maps to `client`), two things of the Woo citizen journey (hydra
 * `woo-citizen-journey`): their own dossiers of publications (contract C1)
 * and their saved searches with alerts (C2, and the C3 rule key
 * `opencatalogi.savedSearch.matched`).
 *
 * Both collections are scoped by `owner`, the subject reference opencatalogi
 * stamps from portaliq's signed assertion. Every endpoint below verifies that
 * assertion and checks `owner` again itself.
 *
 * @category Portal
 * @package  OCA\OpenCatalogi\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Portal;

use OCA\OpenCatalogi\Contract\CitizenCollectionServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestIntakeInterface;
use OCA\OpenCatalogi\Contract\PortalContributionProviderInterface;
use OCA\OpenCatalogi\Service\Woo\DossiqWooForward;

/**
 * Declares what a resident may see and do in opencatalogi through the portal.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
 */
class PortalContributionProvider implements PortalContributionProviderInterface {

	/**
	 * The register both schemas live in.
	 */
	public const REGISTER = 'publication';

	/**
	 * The dossier schema (contract C1).
	 */
	public const COLLECTION_SCHEMA = 'collection';

	/**
	 * The saved search schema (contract C2).
	 */
	public const SAVED_SEARCH_SCHEMA = 'savedSearch';

	/**
	 * The menu heading over the resident's dossiers and saved searches (portaliq `group`).
	 */
	public const GROUP = 'Openbare informatie';

	/**
	 * What a resident reads for how often a saved search tells them, per stored value.
	 */
	public const FREQUENCY_LABELS = [
		'immediate' => 'Direct',
		'daily' => 'Dagelijks',
		'weekly' => 'Wekelijks',
	];

	/**
	 * The audiences served. DigiD currently maps to `client`.
	 */
	private const AUDIENCES = ['citizen', 'client'];

	/**
	 * Where the endpoint actions live, instance-local.
	 */
	private const API = '/index.php/apps/opencatalogi/api/portal';

	/**
	 * The dossier row actions: id, label, path below the portal API, extra whitelisted fields.
	 */
	private const DOSSIER_ROW_ACTIONS = [
		['viewDossier', 'Openen', '/collections/view', []],
		['removeFromDossier', 'Uit dossier halen', '/collections/items/remove', ['itemId']],
		['noteOnDossier', 'Notitie schrijven', '/collections/note', ['itemId', 'note']],
		['shareDossier', 'Deellink maken', '/collections/share', []],
		['unshareDossier', 'Deellink intrekken', '/collections/unshare', []],
		['deleteDossier', 'Dossier verwijderen', '/collections/delete', []],
	];

	/**
	 * The saved-search row actions: id, label, path below the portal API, extra whitelisted fields.
	 */
	private const SAVED_SEARCH_ROW_ACTIONS = [
		['pauseSavedSearch', 'Geen berichten meer', '/saved-searches/pause', []],
		['deleteSavedSearch', 'Zoekopdracht verwijderen', '/saved-searches/delete', []],
	];

	/**
	 * The fields of the create-dossier form.
	 */
	private const DOSSIER_FIELD_CONFIGS = [
		'title' => ['label' => 'Naam van het dossier', 'required' => true, 'placeholder' => 'Bijvoorbeeld: windpark'],
		'description' => ['label' => 'Notitie', 'size' => 'full'],
	];

	/**
	 * The fields of the change-saved-search form.
	 */
	private const SAVED_SEARCH_FIELD_CONFIGS = [
		'title' => ['label' => 'Naam', 'required' => true],
		'frequency' => ['label' => 'Hoe vaak wilt u bericht?'],
		'active' => ['label' => 'Stuur mij berichten'],
	];

	/**
	 * The dossier table columns.
	 */
	private const DOSSIER_COLUMNS = [
		['field' => 'title', 'label' => 'Dossier'],
		['field' => 'description', 'label' => 'Notitie'],
	];

	/**
	 * The saved-search table columns.
	 */
	private const SAVED_SEARCH_COLUMNS = [
		['field' => 'title', 'label' => 'Zoekopdracht'],
		['field' => 'frequency', 'label' => 'Hoe vaak', 'render' => 'badge', 'valueLabels' => self::FREQUENCY_LABELS],
		['field' => 'active', 'label' => 'Actief', 'render' => 'boolean'],
		['field' => 'lastNotifiedAt', 'label' => 'Laatste bericht', 'render' => 'datetime'],
	];

	/**
	 * Constructor. The manifest itself needs nothing; the dossier service is
	 * only for `dossierItems()`, which portaliq calls after its own scoped read,
	 * and the Woo intake only for `receiveWooRequest()`.
	 *
	 * @param CitizenCollectionServiceInterface|null $collections The dossiers.
	 * @param InformationRequestIntakeInterface|null $wooRequests Mints a Woo request and arms its term.
	 * @param ServiceStatusIndex|null $statusIndex The service status, for the public index.
	 * @param DossiqWooForward|null $dossiqForward Hands new Woo requests to dossiq while it is installed.
	 */
	public function __construct(
		private readonly ?CitizenCollectionServiceInterface $collections = null,
		private readonly ?InformationRequestIntakeInterface $wooRequests = null,
		private readonly ?ServiceStatusIndex $statusIndex = null,
		private readonly ?DossiqWooForward $dossiqForward = null,
	) {

	}//end __construct()

	/**
	 * The public index a portal's visitors read: the service status.
	 *
	 * Portaliq calls this without a session, for every portal; the status is
	 * the same for each.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The index items.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function getPublicIndex(string $portal): array {
		if ($this->statusIndex === null) {
			return [];
		}

		return $this->statusIndex->items();
	}//end getPublicIndex()

	/**
	 * The kinds of the public index, with the columns a site table can show.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The kinds.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function getPublicIndexKinds(string $portal): array {
		if ($this->statusIndex === null) {
			return [];
		}

		return [$this->statusIndex->kind()];
	}//end getPublicIndexKinds()

	/**
	 * Receive a Woo request a citizen sent through a portal form.
	 *
	 * The portal (portaliq) calls this from its intake delivery job for a form bound with
	 * `deliverTo: wooRequest`. opencatalogi mints the reference and arms the
	 * statutory term; portaliq only reports the outcome to the citizen.
	 *
	 * @param array<string, mixed> $answers The citizen's answers, keyed by the request's field names.
	 * @param string $receivedAt When the citizen sent it (ISO 8601).
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-a-new-request-is-forwarded-to-dossiq-and-minted-only-there-req-whd-002
	 */
	public function receiveWooRequest(array $answers, string $receivedAt = ''): array {
		// While dossiq is installed it mints the reference and arms the term;
		// OpenCatalogi stores nothing (REQ-WHD-002).
		if ($this->dossiqForward !== null && $this->dossiqForward->isAvailable() === true) {
			$answer = $this->dossiqForward->forward(answers: $answers, receivedAt: $receivedAt, origin: DossiqWooForward::ORIGIN_PORTAL_FORM);
			return array_intersect_key($answer, array_flip(DossiqWooForward::ANSWER_KEYS));
		}

		if ($this->wooRequests === null) {
			return [
				'outcome' => InformationRequestIntakeInterface::OUTCOME_UNAVAILABLE,
				'requestId' => '',
				'reference' => '',
				'dueAt' => '',
				'message' => 'The Woo request intake is not available.',
			];
		}

		return $this->wooRequests->receive(answers: $answers, receivedAt: $receivedAt);
	}//end receiveWooRequest()

	/**
	 * The items of one dossier, for portaliq's `itemList` (hydra C7).
	 *
	 * Portaliq calls this only after the resident's own scoped read of the
	 * dossier succeeded, so it does not check the owner again.
	 *
	 * @param string $collectionId The dossier id.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function dossierItems(string $collectionId): array {
		if ($this->collections === null) {
			return [];
		}

		return $this->collections->itemList(collectionId: $collectionId);
	}//end dossierItems()

	/**
	 * The audiences this provider contributes to (contract v2).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getAudiences(): array {
		return self::AUDIENCES;
	}//end getAudiences()

	/**
	 * The primary audience, for a contract v1 registry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getAudience(): string {
		return 'citizen';
	}//end getAudience()

	/**
	 * The manifest for one resolved subject, or null for an audience not served.
	 *
	 * @param array<string, mixed> $subject The subject portaliq resolved.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getContribution(array $subject): ?array {
		if (in_array(($subject['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return null;
		}

		return [
			'label' => self::GROUP,
			'collections' => [
				$this->dossierCollection(),
				$this->savedSearchCollection(),
			],
			'actions' => array_merge($this->dossierActions(), $this->savedSearchActions()),
			'pages' => $this->pages(),
			// A declared rule key, not a change rule: opencatalogi writes the
			// match message itself (SavedSearchNoticeWriter), so portaliq sends
			// its e-mail. A change rule would add a generic notice per save.
			'notifications' => [self::RULE_SAVED_SEARCH_MATCHED],
		];

	}//end getContribution()

	/**
	 * The resident's dossiers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	private function dossierCollection(): array {
		return [
			'id' => 'myDossiers',
			'register' => self::REGISTER,
			'schema' => self::COLLECTION_SCHEMA,
			'scopeField' => 'owner',
			'label' => 'Mijn dossiers',
			'listable' => true,
			'fields' => ['title', 'description', 'items', 'share', 'sourceOf'],
			'columns' => self::DOSSIER_COLUMNS,
			// The items are shown by the item list below, with their links,
			// notes and a "no longer public" mark, so the card does not print
			// them raw.
			'detail' => ['layout' => 'card', 'fields' => ['title', 'description']],
			// A table button sends only the row id. Opening a dossier is the
			// detail block's job, and `noteOnDossier` sent without `itemId`
			// and `note` would erase the dossier's own note, so neither is a
			// button. Both stay declared for the API.
			'rowActions' => ['removeFromDossier', 'shareDossier', 'unshareDossier', 'deleteDossier'],
			'itemList' => ['label' => 'Documenten', 'provider' => 'dossierItems', 'removeAction' => 'removeFromDossier'],
		];

	}//end dossierCollection()

	/**
	 * The resident's saved searches.
	 *
	 * @return array<string, mixed>
	 */
	private function savedSearchCollection(): array {
		return [
			'id' => 'mySavedSearches',
			'register' => self::REGISTER,
			'schema' => self::SAVED_SEARCH_SCHEMA,
			'scopeField' => 'owner',
			'label' => 'Mijn zoekopdrachten',
			'listable' => true,
			'fields' => ['title', 'query', 'frequency', 'active', 'lastNotifiedAt', 'lastMatches', 'matchCount'],
			'columns' => self::SAVED_SEARCH_COLUMNS,
			'detail' => ['layout' => 'card', 'fields' => ['title', 'frequency', 'active', 'lastNotifiedAt', 'matchCount', 'lastMatches']],
			'rowActions' => ['pauseSavedSearch', 'deleteSavedSearch'],
		];

	}//end savedSearchCollection()

	/**
	 * The dossier actions: create, add a publication, and the row actions on a dossier.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/citizen-collections/spec.md
	 */
	private function dossierActions(): array {
		return [
			$this->createDossierAction(),
			[
				'id' => 'addToDossier',
				'label' => 'Bewaar in mijn dossier',
				'endpoint' => self::API . '/collections/items',
				'method' => 'POST',
				'fields' => ['collection', 'title', 'publication', 'attachment', 'note'],
				'successMessage' => 'Bewaard in uw dossier',
			],
			...$this->rowActions(specs: self::DOSSIER_ROW_ACTIONS, rowField: 'collection'),
		];

	}//end dossierActions()

	/**
	 * The form that creates a dossier owned by the resident.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/citizen-collections/spec.md
	 */
	private function createDossierAction(): array {
		return [
			'id' => 'createDossier',
			'type' => 'create',
			'label' => 'Nieuw dossier',
			'register' => self::REGISTER,
			'schema' => self::COLLECTION_SCHEMA,
			'scopeField' => 'owner',
			'defaults' => ['items' => [], 'sourceOf' => []],
			'fields' => ['title', 'description'],
			'fieldConfigs' => self::DOSSIER_FIELD_CONFIGS,
			'submitLabel' => 'Dossier maken',
			'successMessage' => 'Uw dossier is gemaakt',
		];

	}//end createDossierAction()

	/**
	 * The saved-search actions: save, change, and the row actions on a saved search.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/saved-searches/spec.md
	 */
	private function savedSearchActions(): array {
		return [
			[
				'id' => 'saveSearch',
				'label' => 'Bewaar deze zoekopdracht',
				'endpoint' => self::API . '/saved-searches',
				'method' => 'POST',
				'fields' => ['title', 'query', 'frequency'],
				'successMessage' => 'Uw zoekopdracht is bewaard',
			],
			$this->updateSavedSearchAction(),
			...$this->rowActions(specs: self::SAVED_SEARCH_ROW_ACTIONS, rowField: 'savedSearch'),
		];

	}//end savedSearchActions()

	/**
	 * The form that changes the resident's own saved search.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/saved-searches/spec.md
	 */
	private function updateSavedSearchAction(): array {
		return [
			'id' => 'updateSavedSearch',
			'type' => 'update',
			'label' => 'Zoekopdracht wijzigen',
			'register' => self::REGISTER,
			'schema' => self::SAVED_SEARCH_SCHEMA,
			'scopeField' => 'owner',
			'fields' => ['title', 'frequency', 'active'],
			'fieldConfigs' => self::SAVED_SEARCH_FIELD_CONFIGS,
			'optionsProviders' => ['frequency' => ['type' => 'static', 'options' => $this->frequencyOptions()]],
			'submitLabel' => 'Opslaan',
			'successMessage' => 'Uw zoekopdracht is gewijzigd',
		];

	}//end updateSavedSearchAction()

	/**
	 * The notice frequencies as select options, in the order of FREQUENCY_LABELS.
	 *
	 * @return array<int, array{value: string, label: string}>
	 *
	 * @spec openspec/specs/saved-searches/spec.md
	 */
	private function frequencyOptions(): array {
		return array_map(
			static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
			array_keys(self::FREQUENCY_LABELS),
			self::FREQUENCY_LABELS
		);

	}//end frequencyOptions()

	/**
	 * Endpoint row actions. portaliq proves the row under the collection's
	 * scope and sends its id under `rowField`.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3: list<string>}> $specs Id, label, path, extra fields.
	 * @param string $rowField The body field that carries the row id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/citizen-collections/spec.md
	 */
	private function rowActions(array $specs, string $rowField): array {
		return array_map(
			static fn (array $spec): array => [
				'id' => $spec[0],
				'label' => $spec[1],
				'endpoint' => self::API . $spec[2],
				'method' => 'POST',
				'rowField' => $rowField,
				'fields' => $spec[3],
			],
			$specs
		);

	}//end rowActions()

	/**
	 * The pages: Mijn dossiers and Mijn zoekopdrachten.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	private function pages(): array {
		return [$this->dossiersPage(), $this->savedSearchesPage()];
	}//end pages()

	/**
	 * The Mijn dossiers page: the create form, the dossier list and the selected dossier.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	private function dossiersPage(): array {
		return [
			'id' => 'dossiers',
			'label' => 'Mijn dossiers',
			'group' => self::GROUP,
			'icon' => 'FolderStar',
			'blocks' => [
				['type' => 'richText', 'markdown' => 'Bewaar openbare documenten in uw eigen dossier. Alleen u ziet het, tot u een deellink maakt.'],
				['type' => 'action', 'action' => 'createDossier'],
				['type' => 'collection', 'collection' => 'myDossiers'],
				// The selected dossier in full: title, note, the items and
				// the actions pipelinq and dossiq attach to a dossier.
				['type' => 'detail', 'collection' => 'myDossiers'],
			],
		];

	}//end dossiersPage()

	/**
	 * The Mijn zoekopdrachten page: the saved-search list.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/saved-searches/spec.md
	 */
	private function savedSearchesPage(): array {
		return [
			'id' => 'zoekopdrachten',
			'label' => 'Mijn zoekopdrachten',
			'group' => self::GROUP,
			'icon' => 'BellRing',
			'blocks' => [
				['type' => 'richText', 'markdown' => 'U krijgt bericht als er nieuwe documenten zijn die bij uw zoekopdracht passen.'],
				['type' => 'collection', 'collection' => 'mySavedSearches'],
			],
		];

	}//end savedSearchesPage()
}//end class
