<?php

/**
 * Tests for opencatalogi's portal contribution.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
 */

declare(strict_types=1);

namespace Unit\Portal;

use OCA\OpenCatalogi\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 */
class PortalContributionProviderTest extends TestCase {

	/**
	 * The manifest for one audience.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>|null
	 */
	private function manifest(string $audience): ?array {
		return (new PortalContributionProvider())->getContribution(['subjectRef' => 'subject-1', 'audience' => $audience, 'trust' => 'substantial']);
	}

	/**
	 * Entries of a list keyed by their id.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			$out[$entry['id']] = $entry;
		}

		return $out;
	}

	public function testItServesCitizenAndClient(): void {
		$provider = new PortalContributionProvider();
		$this->assertSame(['citizen', 'client'], $provider->getAudiences());
		$this->assertSame('citizen', $provider->getAudience());
		$this->assertNotNull($this->manifest('citizen'));
		$this->assertNotNull($this->manifest('client'));
	}

	public function testASupplierGetsNothing(): void {
		$this->assertNull($this->manifest('supplier'));
		$this->assertNull($this->manifest(''));
	}

	public function testBothAudiencesGetTheSameManifest(): void {
		$this->assertSame($this->manifest('citizen'), $this->manifest('client'));
	}

	public function testTheDossiersAreScopedByOwner(): void {
		$collections = $this->byId($this->manifest('client')['collections']);
		$dossiers = $collections['myDossiers'];
		$this->assertSame('publication', $dossiers['register']);
		$this->assertSame('collection', $dossiers['schema']);
		$this->assertSame('owner', $dossiers['scopeField']);
		$this->assertArrayNotHasKey('scopeClaim', $dossiers);
		$this->assertNotContains('owner', $dossiers['fields']);
		$this->assertSame(['removeFromDossier', 'shareDossier', 'unshareDossier', 'deleteDossier'], $dossiers['rowActions']);
		$this->assertSame(['label' => 'Documenten', 'provider' => 'dossierItems', 'removeAction' => 'removeFromDossier'], $dossiers['itemList']);
		$this->assertTrue(method_exists(PortalContributionProvider::class, 'dossierItems'));

		$searches = $collections['mySavedSearches'];
		$this->assertSame('savedSearch', $searches['schema']);
		$this->assertSame('owner', $searches['scopeField']);
		$this->assertContains('lastNotifiedAt', $searches['fields']);
		$this->assertContains('title', $searches['fields']);
	}

	public function testEveryActionTheContractNamesIsDeclared(): void {
		$actions = $this->byId($this->manifest('citizen')['actions']);
		foreach (['createDossier', 'addToDossier', 'viewDossier', 'removeFromDossier', 'noteOnDossier', 'shareDossier', 'unshareDossier', 'deleteDossier', 'saveSearch', 'updateSavedSearch', 'pauseSavedSearch', 'deleteSavedSearch'] as $id) {
			$this->assertArrayHasKey($id, $actions, $id);
		}

		$this->assertSame('create', $actions['createDossier']['type']);
		$this->assertSame('owner', $actions['createDossier']['scopeField']);
		$this->assertSame('update', $actions['updateSavedSearch']['type']);
		$this->assertSame(['title', 'frequency', 'active'], $actions['updateSavedSearch']['fields']);
	}

	public function testEndpointActionsAreInstanceLocalAndRowActionsNameTheirRow(): void {
		$actions = $this->byId($this->manifest('citizen')['actions']);
		$this->assertSame('/index.php/apps/opencatalogi/api/portal/collections/items', $actions['addToDossier']['endpoint']);
		$this->assertSame(['collection', 'title', 'publication', 'attachment', 'note'], $actions['addToDossier']['fields']);
		$this->assertSame('/index.php/apps/opencatalogi/api/portal/saved-searches', $actions['saveSearch']['endpoint']);
		$this->assertSame(['itemId'], $actions['removeFromDossier']['fields']);
		$this->assertSame(['itemId', 'note'], $actions['noteOnDossier']['fields']);
		$this->assertSame(['title', 'query', 'frequency'], $actions['saveSearch']['fields']);

		foreach (['viewDossier', 'removeFromDossier', 'noteOnDossier', 'shareDossier', 'unshareDossier', 'deleteDossier'] as $id) {
			$this->assertSame('collection', $actions[$id]['rowField'], $id);
		}

		foreach (['pauseSavedSearch', 'deleteSavedSearch'] as $id) {
			$this->assertSame('savedSearch', $actions[$id]['rowField'], $id);
		}

		foreach ($actions as $id => $action) {
			if (isset($action['endpoint']) === false) {
				continue;
			}

			$this->assertStringStartsWith('/index.php/apps/opencatalogi/api/portal/', $action['endpoint'], $id);
			$this->assertSame('POST', $action['method'], $id);
			$this->assertNotContains('owner', ($action['fields'] ?? []), $id);
		}
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	public function testTheSavedSearchNoticeIsADeclaredRuleKeyNotAChangeRule(): void {
		// opencatalogi writes the match message itself; a change rule on the
		// saved search would add portaliq's generic "has been updated" notice.
		$this->assertSame(['opencatalogi.savedSearch.matched'], $this->manifest('citizen')['notifications']);
	}

	public function testThePagesComposeOnlyOwnBlocks(): void {
		$manifest = $this->manifest('citizen');
		$pages = $this->byId($manifest['pages']);
		$this->assertSame('Mijn dossiers', $pages['dossiers']['label']);
		$this->assertSame('Mijn zoekopdrachten', $pages['zoekopdrachten']['label']);
		$collections = array_column($manifest['collections'], 'id');
		$actions = array_column($manifest['actions'], 'id');
		foreach ($manifest['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if ($block['type'] === 'collection') {
					$this->assertContains($block['collection'], $collections);
				}

				if ($block['type'] === 'action') {
					$this->assertContains($block['action'], $actions);
				}
			}
		}
	}

	/**
	 * The dossier page shows the selected dossier: its title and note, its
	 * items through the item list, and the actions other apps attach to it.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	public function testTheDossierPageShowsTheSelectedDossier(): void {
		$manifest = $this->manifest('citizen');
		$blocks = $this->byId($manifest['pages'])['dossiers']['blocks'];
		$types = array_map(static fn (array $block): string => $block['type'].':'.($block['collection'] ?? ($block['action'] ?? '')), $blocks);
		$this->assertSame(['richText:', 'action:createDossier', 'collection:myDossiers', 'detail:myDossiers'], $types);

		$dossiers = $this->byId($manifest['collections'])['myDossiers'];
		// The items are the item list's, so the card does not print them raw.
		$this->assertSame(['layout' => 'card', 'fields' => ['title', 'description']], $dossiers['detail']);
		foreach (['title', 'description'] as $field) {
			$this->assertContains($field, $dossiers['fields']);
		}
	}

	/**
	 * A table button sends only the row id. Opening is the detail block's
	 * job, and a note sent without its fields would erase the dossier's note,
	 * so neither is a table button. Both stay declared for the API.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-dossier-page-shows-one-dossier-in-full-req-ccol-008
	 */
	public function testNoTableButtonOpensOrEmptiesADossier(): void {
		$manifest = $this->manifest('citizen');
		$dossiers = $this->byId($manifest['collections'])['myDossiers'];
		$this->assertNotContains('viewDossier', $dossiers['rowActions']);
		$this->assertNotContains('noteOnDossier', $dossiers['rowActions']);
		$actions = $this->byId($manifest['actions']);
		$this->assertArrayHasKey('viewDossier', $actions);
		$this->assertArrayHasKey('noteOnDossier', $actions);
	}

	public function testNoLabelUsesAnEmDashOrTitleCase(): void {
		$json = (string)json_encode($this->manifest('citizen'), JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('—', $json);
		preg_match_all('/"(?:label|submitLabel|successMessage)":"([^"]+)"/', $json, $labels);
		foreach ($labels[1] as $label) {
			$words = explode(' ', $label);
			array_shift($words);
			foreach ($words as $word) {
				$this->assertFalse(ctype_upper(mb_substr($word, 0, 1)) && $word !== 'Woo', 'Title Case in: '.$label);
			}
		}
	}
}
