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
		$this->assertSame(['viewDossier', 'removeFromDossier', 'noteOnDossier', 'shareDossier', 'unshareDossier', 'deleteDossier'], $dossiers['rowActions']);

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
	public function testTheSavedSearchNoticeIsAChangeRule(): void {
		$this->assertSame(
			[
				[
					'ruleKey' => 'opencatalogi.savedSearch.matched',
					'collection' => 'mySavedSearches',
					'on' => ['field' => 'lastNotifiedAt', 'operator' => 'changed'],
					'titleField' => 'title',
				],
			],
			$this->manifest('citizen')['notifications']
		);
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
