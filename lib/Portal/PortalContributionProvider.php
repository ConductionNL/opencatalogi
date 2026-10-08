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

use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Woo\WooRequestIntake;

/**
 * Declares what a resident may see and do in opencatalogi through the portal.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
 */
class PortalContributionProvider {

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
	 * The rule key of a saved-search notice (contract C3).
	 */
	public const RULE_SAVED_SEARCH_MATCHED = 'opencatalogi.savedSearch.matched';

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
	 * Constructor. The manifest itself needs nothing; the dossier service is
	 * only for `dossierItems()`, which portaliq calls after its own scoped read,
	 * and the Woo intake only for `receiveWooRequest()`.
	 *
	 * @param CitizenCollectionService|null $collections The dossiers.
	 * @param WooRequestIntake|null $wooRequests Mints a Woo request and arms its term.
	 */
	public function __construct(
		private readonly ?CitizenCollectionService $collections=null,
		private readonly ?WooRequestIntake $wooRequests=null,
	) {

	}//end __construct()

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
	 */
	public function receiveWooRequest(array $answers, string $receivedAt=''): array {
		if ($this->wooRequests === null) {
			return [
				'outcome' => WooRequestIntake::OUTCOME_UNAVAILABLE,
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
			'columns' => [
				['field' => 'title', 'label' => 'Dossier'],
				['field' => 'description', 'label' => 'Notitie'],
			],
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
			'columns' => [
				['field' => 'title', 'label' => 'Zoekopdracht'],
				['field' => 'frequency', 'label' => 'Hoe vaak', 'render' => 'badge', 'valueLabels' => self::FREQUENCY_LABELS],
				['field' => 'active', 'label' => 'Actief', 'render' => 'boolean'],
				['field' => 'lastNotifiedAt', 'label' => 'Laatste bericht', 'render' => 'datetime'],
			],
			'detail' => ['layout' => 'card', 'fields' => ['title', 'frequency', 'active', 'lastNotifiedAt', 'matchCount', 'lastMatches']],
			'rowActions' => ['pauseSavedSearch', 'deleteSavedSearch'],
		];

	}//end savedSearchCollection()

	/**
	 * The dossier actions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dossierActions(): array {
		return [
			[
				'id' => 'createDossier',
				'type' => 'create',
				'label' => 'Nieuw dossier',
				'register' => self::REGISTER,
				'schema' => self::COLLECTION_SCHEMA,
				'scopeField' => 'owner',
				'defaults' => ['items' => [], 'sourceOf' => []],
				'fields' => ['title', 'description'],
				'fieldConfigs' => [
					'title' => ['label' => 'Naam van het dossier', 'required' => true, 'placeholder' => 'Bijvoorbeeld: windpark'],
					'description' => ['label' => 'Notitie', 'size' => 'full'],
				],
				'submitLabel' => 'Dossier maken',
				'successMessage' => 'Uw dossier is gemaakt',
			],
			[
				'id' => 'addToDossier',
				'label' => 'Bewaar in mijn dossier',
				'endpoint' => self::API.'/collections/items',
				'method' => 'POST',
				'fields' => ['collection', 'title', 'publication', 'attachment', 'note'],
				'successMessage' => 'Bewaard in uw dossier',
			],
			$this->rowAction(id: 'viewDossier', label: 'Openen', path: '/collections/view', rowField: 'collection', fields: []),
			$this->rowAction(
				id: 'removeFromDossier',
				label: 'Uit dossier halen',
				path: '/collections/items/remove',
				rowField: 'collection',
				fields: ['itemId']
			),
			$this->rowAction(id: 'noteOnDossier', label: 'Notitie schrijven', path: '/collections/note', rowField: 'collection', fields: ['itemId', 'note']),
			$this->rowAction(id: 'shareDossier', label: 'Deellink maken', path: '/collections/share', rowField: 'collection', fields: []),
			$this->rowAction(id: 'unshareDossier', label: 'Deellink intrekken', path: '/collections/unshare', rowField: 'collection', fields: []),
			$this->rowAction(id: 'deleteDossier', label: 'Dossier verwijderen', path: '/collections/delete', rowField: 'collection', fields: []),
		];

	}//end dossierActions()

	/**
	 * The saved-search actions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedSearchActions(): array {
		return [
			[
				'id' => 'saveSearch',
				'label' => 'Bewaar deze zoekopdracht',
				'endpoint' => self::API.'/saved-searches',
				'method' => 'POST',
				'fields' => ['title', 'query', 'frequency'],
				'successMessage' => 'Uw zoekopdracht is bewaard',
			],
			[
				'id' => 'updateSavedSearch',
				'type' => 'update',
				'label' => 'Zoekopdracht wijzigen',
				'register' => self::REGISTER,
				'schema' => self::SAVED_SEARCH_SCHEMA,
				'scopeField' => 'owner',
				'fields' => ['title', 'frequency', 'active'],
				'fieldConfigs' => [
					'title' => ['label' => 'Naam', 'required' => true],
					'frequency' => ['label' => 'Hoe vaak wilt u bericht?'],
					'active' => ['label' => 'Stuur mij berichten'],
				],
				'optionsProviders' => [
					'frequency' => [
						'type' => 'static',
						'options' => [
							['value' => 'immediate', 'label' => self::FREQUENCY_LABELS['immediate']],
							['value' => 'daily', 'label' => self::FREQUENCY_LABELS['daily']],
							['value' => 'weekly', 'label' => self::FREQUENCY_LABELS['weekly']],
						],
					],
				],
				'submitLabel' => 'Opslaan',
				'successMessage' => 'Uw zoekopdracht is gewijzigd',
			],
			$this->rowAction(id: 'pauseSavedSearch', label: 'Geen berichten meer', path: '/saved-searches/pause', rowField: 'savedSearch', fields: []),
			$this->rowAction(id: 'deleteSavedSearch', label: 'Zoekopdracht verwijderen', path: '/saved-searches/delete', rowField: 'savedSearch', fields: []),
		];

	}//end savedSearchActions()

	/**
	 * One endpoint row action. portaliq proves the row under the collection's
	 * scope and sends its id under `rowField`.
	 *
	 * @param string             $id       The action id.
	 * @param string             $label    The label.
	 * @param string             $path     The path below the portal API.
	 * @param string             $rowField The body field that carries the row id.
	 * @param array<int, string> $fields   Extra whitelisted fields.
	 *
	 * @return array<string, mixed>
	 */
	private function rowAction(string $id, string $label, string $path, string $rowField, array $fields): array {
		return [
			'id' => $id,
			'label' => $label,
			'endpoint' => self::API.$path,
			'method' => 'POST',
			'rowField' => $rowField,
			'fields' => $fields,
		];

	}//end rowAction()

	/**
	 * The pages: Mijn dossiers and Mijn zoekopdrachten.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	private function pages(): array {
		return [
			[
				'id' => 'dossiers',
				'label' => 'Mijn dossiers',
				'group' => self::GROUP,
				'icon' => 'FolderStar',
				'blocks' => [
					[
						'type' => 'richText',
						'markdown' => "Bewaar openbare documenten in uw eigen dossier. Alleen u ziet het, tot u een deellink maakt.",
					],
					['type' => 'action', 'action' => 'createDossier'],
					['type' => 'collection', 'collection' => 'myDossiers'],
					// The selected dossier in full: title, note, the items and
					// the actions pipelinq and dossiq attach to a dossier.
					['type' => 'detail', 'collection' => 'myDossiers'],
				],
			],
			[
				'id' => 'zoekopdrachten',
				'label' => 'Mijn zoekopdrachten',
				'group' => self::GROUP,
				'icon' => 'BellRing',
				'blocks' => [
					[
						'type' => 'richText',
						'markdown' => "U krijgt bericht als er nieuwe documenten zijn die bij uw zoekopdracht passen.",
					],
					['type' => 'collection', 'collection' => 'mySavedSearches'],
				],
			],
		];

	}//end pages()
}//end class
