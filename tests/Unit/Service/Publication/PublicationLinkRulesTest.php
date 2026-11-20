<?php

/**
 * Unit tests for PublicationLinkRules.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationLinkRules;
use PHPUnit\Framework\TestCase;

/**
 * The self-relation check and the case reference mirror.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationLinkRules
 */
class PublicationLinkRulesTest extends TestCase {

	/**
	 * Each relation property is checked, with ids given as strings, numbers or objects.
	 *
	 * @return void
	 */
	public function testTheFirstPropertyNamingItselfIsReturned(): void {
		$this->assertSame('amends', (new PublicationLinkRules())->selfRelation(['replaces' => ['b'], 'amends' => [['uuid' => 'a']]], 'a'));
		$this->assertSame('implements', (new PublicationLinkRules())->selfRelation(['implements' => [['id' => 'a']]], 'a'));
		$this->assertSame('relatedTo', (new PublicationLinkRules())->selfRelation(['relatedTo' => 'a'], 'a'));
		$this->assertSame('replaces', (new PublicationLinkRules())->selfRelation(['replaces' => [5]], '5'));
	}//end testTheFirstPropertyNamingItselfIsReturned()

	/**
	 * Nothing is refused for a new publication, other ids or unreadable items.
	 *
	 * @return void
	 */
	public function testNoSelfRelationIsFoundWhereThereIsNone(): void {
		$this->assertNull((new PublicationLinkRules())->selfRelation(['replaces' => ['a']], ''));
		$this->assertNull((new PublicationLinkRules())->selfRelation(['replaces' => ['b', null, 1.5, ['title' => 'x']], 'other' => ['a']], 'a'));
		$this->assertNull((new PublicationLinkRules())->selfRelation([], 'a'));
	}//end testNoSelfRelationIsFoundWhereThereIsNone()

	/**
	 * A case reference is added as a private case entry, after the entries already there.
	 *
	 * @return void
	 */
	public function testACaseReferenceIsMirroredAsAPrivateEntry(): void {
		$raad = ['system' => 'raad', 'identifier' => 'RB-17', 'public' => true];
		$this->assertSame(
			[$raad, ['system' => 'case', 'identifier' => 'Z-1', 'public' => false]],
			(new PublicationLinkRules())->withMirroredCaseReference(['caseReference' => 'Z-1', 'sourceIdentifiers' => [$raad, 'junk']])
		);
		$this->assertSame(
			[['system' => 'case', 'identifier' => 'Z-1', 'public' => false]],
			(new PublicationLinkRules())->withMirroredCaseReference(['caseReference' => 'Z-1', 'sourceIdentifiers' => 'junk'])
		);
		$this->assertSame(
			[['system' => 'other', 'identifier' => 'Z-1'], ['system' => 'case', 'identifier' => 'Z-1', 'public' => false]],
			(new PublicationLinkRules())->withMirroredCaseReference(['caseReference' => 'Z-1', 'sourceIdentifiers' => [['system' => 'other', 'identifier' => 'Z-1']]])
		);
	}//end testACaseReferenceIsMirroredAsAPrivateEntry()

	/**
	 * Nothing changes without a case reference or when it is already mirrored.
	 *
	 * @return void
	 */
	public function testNothingChangesWithoutANewCaseReference(): void {
		$this->assertNull((new PublicationLinkRules())->withMirroredCaseReference([]));
		$this->assertNull((new PublicationLinkRules())->withMirroredCaseReference(['caseReference' => '  ']));
		$this->assertNull((new PublicationLinkRules())->withMirroredCaseReference(['caseReference' => 12]));
		$this->assertNull((new PublicationLinkRules())->withMirroredCaseReference([
			'caseReference' => 'Z-1',
			'sourceIdentifiers' => [['system' => 'case', 'identifier' => 'Z-1', 'public' => true]],
		]));
	}//end testNothingChangesWithoutANewCaseReference()

	/**
	 * Only entries whose `public` is exactly true stay; a missing flag counts as internal.
	 *
	 * @return void
	 */
	public function testOnlyPublicSourceIdentifiersAreKeptForAPublicAnswer(): void {
		$publication = [
			'title' => 'Raadsbesluit',
			'sourceIdentifiers' => [
				['system' => 'decos', 'identifier' => 'Z-2026-0412', 'public' => false],
				['system' => 'raad', 'identifier' => 'RB-2026-17', 'public' => true],
				['system' => 'case', 'identifier' => 'c-1'],
				['system' => 'x', 'identifier' => 'y', 'public' => 'true'],
				'not an entry',
			],
		];

		$this->assertSame(
			['title' => 'Raadsbesluit', 'sourceIdentifiers' => [['system' => 'raad', 'identifier' => 'RB-2026-17', 'public' => true]]],
			(new PublicationLinkRules())->withPublicSourceIdentifiers($publication)
		);
		$this->assertSame(['title' => 'Geen'], (new PublicationLinkRules())->withPublicSourceIdentifiers(['title' => 'Geen']));
		$this->assertSame(['sourceIdentifiers' => []], (new PublicationLinkRules())->withPublicSourceIdentifiers(['sourceIdentifiers' => 'Z-1']));
	}//end testOnlyPublicSourceIdentifiersAreKeptForAPublicAnswer()
}//end class
