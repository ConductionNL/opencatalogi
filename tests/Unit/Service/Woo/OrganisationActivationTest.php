<?php

/**
 * OrganisationActivation test: an administrator activates the organisations the installation publishes for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Woo;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\ValueList\OpenRegisterOrganisations;
use OCA\OpenCatalogi\Service\ValueList\OrganisationActivation;
use OCA\OpenCatalogi\Service\ValueList\ValueListChoiceStore;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListRefresh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Activation over stand-ins of the vocabulary, the choices and OpenRegister's organisations.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
#[CoversClass(OrganisationActivation::class)]
class OrganisationActivationTest extends TestCase {

	private const DORDRECHT = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0505';

	private const ZWIJNDRECHT = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0642';

	private const ALBLASSERDAM = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0482';

	private const ENDED = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0001';

	private const RIVIERENLAND = 'https://identifier.overheid.nl/tooi/id/waterschap/ws0621';

	/**
	 * The stored choices, keyed by entry.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The saves the store received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The organisations OpenRegister answers by TOOI identifier.
	 *
	 * @var array<string, array{uuid: string, name: string}>
	 */
	private array $existing = [];

	/**
	 * The creates OpenRegister received, as name and TOOI identifier.
	 *
	 * @var list<array{name: string, tooi: string}>
	 */
	private array $created = [];

	/**
	 * An activation over two registers: three municipalities (one ended) and a water board.
	 *
	 * @return OrganisationActivation
	 */
	private function activation(): OrganisationActivation {
		$gemeenten = 'https://identifier.overheid.nl/tooi/set/rwc_gemeenten_compleet';
		$waterschappen = 'https://identifier.overheid.nl/tooi/set/rwc_waterschappen_compleet';
		$vocabulary = $this->createMock(originalClassName: VocabularyRegisterInterface::class);
		$vocabulary->method('concepts')->willReturnMap(
			[
				[
					$gemeenten,
					[
						self::DORDRECHT => ['label' => 'gemeente Dordrecht', 'notation' => 'gm0505', 'deprecated' => false],
						self::ZWIJNDRECHT => ['label' => 'gemeente Zwijndrecht', 'notation' => 'gm0642', 'deprecated' => false],
						self::ALBLASSERDAM => ['label' => 'gemeente Alblasserdam', 'notation' => 'gm0482', 'deprecated' => false],
						self::ENDED => ['label' => 'gemeente Opgeheven', 'notation' => 'gm0001', 'deprecated' => true],
					],
				],
				[
					$waterschappen,
					[self::RIVIERENLAND => ['label' => 'waterschap Rivierenland', 'notation' => 'ws0621', 'deprecated' => false]],
				],
			]
		);

		$refresh = $this->createMock(originalClassName: ValueListRefresh::class);
		$refresh->method('organisationSchemes')->willReturn([$gemeenten, $waterschappen]);

		$choices = $this->createMock(originalClassName: ValueListChoiceStore::class);
		$choices->method('forScheme')->willReturnCallback(
			function (string $scheme): array {
				$this->assertSame(expected: OrganisationActivation::SCHEME, actual: $scheme);
				$answer = [];
				foreach ($this->stored as $entry => $choice) {
					$answer[$entry] = ['order' => null, 'localLabel' => '', 'explanation' => '', 'hidden' => false, 'active' => (($choice['active'] ?? false) === true)];
				}

				return $answer;
			}
		);
		$choices->method('save')->willReturnCallback(
			function (array $choice): void {
				$this->saved[] = $choice;
				$this->stored[(string)$choice['entry']] = array_merge(($this->stored[(string)$choice['entry']] ?? []), $choice);
			}
		);

		$organisations = $this->createMock(originalClassName: OpenRegisterOrganisations::class);
		$organisations->method('findByTooi')->willReturnCallback(fn (string $tooi): ?array => ($this->existing[$tooi] ?? null));
		$organisations->method('create')->willReturnCallback(
			function (string $name, string $tooi): array {
				$this->created[] = ['name' => $name, 'tooi' => $tooi];
				return ['uuid' => 'new-uuid', 'name' => $name];
			}
		);

		return new OrganisationActivation(vocabulary: $vocabulary, refresh: $refresh, choices: $choices, organisations: $organisations);
	}//end activation()

	/**
	 * Activating links the organisation OpenRegister already holds under that TOOI identifier.
	 *
	 * @return void
	 */
	public function testActivatingLinksAnExistingOrganisation(): void {
		$this->existing[self::DORDRECHT] = ['uuid' => 'dordrecht-uuid', 'name' => 'Gemeente Dordrecht'];

		$answer = $this->activation()->activate(uri: self::DORDRECHT);

		$this->assertSame(
			expected: ['uri' => self::DORDRECHT, 'label' => 'gemeente Dordrecht', 'organisation' => 'dordrecht-uuid', 'created' => false],
			actual: $answer
		);
		$this->assertSame(expected: [], actual: $this->created, message: 'nothing is created when the organisation exists');
		$this->assertSame(expected: [['scheme' => 'organisaties', 'entry' => self::DORDRECHT, 'active' => true]], actual: $this->saved);
	}//end testActivatingLinksAnExistingOrganisation()

	/**
	 * Activating an organisation OpenRegister does not hold creates one with the TOOI identifier and the source name.
	 *
	 * @return void
	 */
	public function testActivatingCreatesOneWithTheTooiIdentifier(): void {
		$answer = $this->activation()->activate(uri: self::RIVIERENLAND);

		$this->assertSame(expected: [['name' => 'waterschap Rivierenland', 'tooi' => self::RIVIERENLAND]], actual: $this->created);
		$this->assertSame(expected: 'new-uuid', actual: $answer['organisation']);
		$this->assertTrue($answer['created']);
		$this->assertSame(expected: [self::RIVIERENLAND], actual: $this->activation()->activeEntries());
	}//end testActivatingCreatesOneWithTheTooiIdentifier()

	/**
	 * An identifier outside the registers, or an organisation that ended, is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownOrEndedOrganisationIsRefused(): void {
		$activation = $this->activation();
		foreach (['https://identifier.overheid.nl/tooi/id/gemeente/gm9999', self::ENDED] as $uri) {
			try {
				$activation->activate(uri: $uri);
				$this->fail('activating ' . $uri . ' is refused');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame(expected: '', actual: $e->getMessage());
			}
		}

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertSame(expected: [], actual: $this->created);
	}//end testAnUnknownOrEndedOrganisationIsRefused()

	/**
	 * Only the two activated organisations are the activated set, the scenario "Only activated organisations are offered".
	 *
	 * @return void
	 */
	public function testOnlyActivatedOrganisationsAreTheActiveSet(): void {
		$this->existing[self::DORDRECHT] = ['uuid' => 'd', 'name' => 'Dordrecht'];
		$this->existing[self::ZWIJNDRECHT] = ['uuid' => 'z', 'name' => 'Zwijndrecht'];
		$activation = $this->activation();
		$activation->activate(uri: self::DORDRECHT);
		$activation->activate(uri: self::ZWIJNDRECHT);
		$activation->activate(uri: self::ALBLASSERDAM);
		$activation->deactivate(uri: self::ALBLASSERDAM);

		$this->assertSame(expected: [self::DORDRECHT, self::ZWIJNDRECHT], actual: $activation->activeEntries());
		$this->assertSame(
			expected: [self::DORDRECHT, self::ZWIJNDRECHT],
			actual: array_column($activation->search(query: '')['results'], 'uri'),
			message: 'an empty search answers the activated organisations'
		);
	}//end testOnlyActivatedOrganisationsAreTheActiveSet()

	/**
	 * A search matches name or code, puts activated organisations first and leaves out an ended one.
	 *
	 * @return void
	 */
	public function testASearchMatchesNameOrCodeActivatedFirst(): void {
		$this->stored[self::ZWIJNDRECHT] = ['active' => true];
		$activation = $this->activation();

		$found = $activation->search(query: 'DRECHT');
		$this->assertSame(expected: [self::ZWIJNDRECHT, self::DORDRECHT], actual: array_column($found['results'], 'uri'));
		$this->assertSame(expected: [true, false], actual: array_column($found['results'], 'active'));
		$this->assertSame(expected: 2, actual: $found['total']);

		$this->assertSame(expected: [self::RIVIERENLAND], actual: array_column($activation->search(query: 'ws0621')['results'], 'uri'));
		$this->assertSame(expected: [], actual: $activation->search(query: 'Opgeheven')['results'], message: 'an ended organisation is not offered');

		$this->stored[self::ENDED] = ['active' => true];
		$this->assertSame(
			expected: [self::ENDED],
			actual: array_column($this->activation()->search(query: 'Opgeheven')['results'], 'uri'),
			message: 'an ended organisation that is still activated stays visible, so it can be deactivated'
		);
	}//end testASearchMatchesNameOrCodeActivatedFirst()
}//end class
