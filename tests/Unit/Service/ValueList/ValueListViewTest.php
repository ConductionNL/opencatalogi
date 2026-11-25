<?php

/**
 * ValueListView test: the organisation's choices sit beside the normative entry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\ValueList;

use OCA\OpenCatalogi\Service\ValueListsService;
use OCA\OpenCatalogi\Service\ValueList\ValueListChoiceStore;
use OCA\OpenCatalogi\Service\ValueList\ValueListMerge;
use OCA\OpenCatalogi\Service\ValueList\ValueListView;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The list is a double answering what a refresh would leave behind; the merge is real.
 *
 * @covers \OCA\OpenCatalogi\Service\ValueList\ValueListView
 * @covers \OCA\OpenCatalogi\Service\ValueList\ValueListMerge
 */
class ValueListViewTest extends TestCase {

	/**
	 * The TOOI URI of the contact details category.
	 */
	private const CONTACT = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_89ee6784';

	/**
	 * The TOOI URI of the covenants category.
	 */
	private const COVENANTS = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_8fc2335c';

	/**
	 * The TOOI URI of the subsidy obligations category.
	 */
	private const SUBSIDIES = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_cf268088';

	/**
	 * The warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warned = [];

	/**
	 * The list as the source answers it, in source order.
	 *
	 * @param string $contactLabel The source label of the contact details category.
	 *
	 * @return ValueListsService
	 */
	private function lists(string $contactLabel = 'Bereikbaarheidsgegevens'): ValueListsService {
		$lists = $this->createMock(originalClassName: ValueListsService::class);
		$lists->method('entries')->with('informatiecategorieen')->willReturn([
			'results' => [
				['scheme' => 'informatiecategorieen', 'code' => 'infocat005', 'label' => $contactLabel, 'uri' => self::CONTACT, 'status' => 'active'],
				['scheme' => 'informatiecategorieen', 'code' => 'infocat011', 'label' => 'Convenanten', 'uri' => self::COVENANTS, 'status' => 'active'],
				[
					'scheme' => 'informatiecategorieen',
					'code' => 'infocat013',
					'label' => 'Subsidieverplichtingen anders dan met beschikking',
					'uri' => self::SUBSIDIES,
					'status' => 'active',
				],
			],
			'total' => 3,
			'schemes' => ['informatiecategorieen'],
			'unavailable' => [],
		]);

		return $lists;
	}//end lists()

	/**
	 * A choice store answering the given choices, or failing.
	 *
	 * @param array<string, array<string, mixed>>|null $choices The choices by entry URI, or null to fail.
	 *
	 * @return ValueListChoiceStore
	 */
	private function store(?array $choices): ValueListChoiceStore {
		$store = $this->createMock(originalClassName: ValueListChoiceStore::class);
		if ($choices === null) {
			$store->method('forScheme')->willThrowException(new RuntimeException('Register configuration is not set.'));
			return $store;
		}

		$store->method('forScheme')->with('informatiecategorieen')->willReturn($choices);
		return $store;
	}//end store()

	/**
	 * The view over a list and a store.
	 *
	 * @param ValueListsService    $lists The list.
	 * @param ValueListChoiceStore $store The choices.
	 *
	 * @return ValueListView
	 */
	private function view(ValueListsService $lists, ValueListChoiceStore $store): ValueListView {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warned[] = $message;
		});

		return new ValueListView(lists: $lists, choices: $store, merge: new ValueListMerge(), logger: $logger);
	}//end view()

	/**
	 * One choice in the store's shape.
	 *
	 * @param array<string, mixed> $fields The fields that differ from no choice at all.
	 *
	 * @return array<string, mixed>
	 */
	private static function choice(array $fields): array {
		return array_merge(['order' => null, 'localLabel' => '', 'explanation' => '', 'hidden' => false, 'active' => false], $fields);
	}//end choice()

	/**
	 * The entry for one URI.
	 *
	 * @param array<string, mixed> $view The view.
	 * @param string               $uri  The URI.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(array $view, string $uri): array {
		foreach ($view['entries'] as $entry) {
			if ($entry['uri'] === $uri) {
				return $entry;
			}
		}

		self::fail(message: 'No entry for ' . $uri);
	}//end entry()

	/**
	 * A refresh renames the category at the source; the organisation's label stays.
	 *
	 * @return void
	 */
	public function testARefreshChangesTheSourceLabelAndKeepsTheLocalOne(): void {
		$choices = [self::CONTACT => self::choice(fields: ['localLabel' => 'Contact en bereikbaarheid'])];

		$before = self::entry(
			view: $this->view(lists: $this->lists(), store: $this->store(choices: $choices))->entries(scheme: 'informatiecategorieen'),
			uri: self::CONTACT
		);
		$this->assertSame(expected: 'Bereikbaarheidsgegevens', actual: $before['sourceLabel']);
		$this->assertSame(expected: 'Contact en bereikbaarheid', actual: $before['label']);

		$renamed = $this->view(lists: $this->lists(contactLabel: 'Contactgegevens'), store: $this->store(choices: $choices));
		$after = self::entry(view: $renamed->entries(scheme: 'informatiecategorieen'), uri: self::CONTACT);
		$this->assertSame(expected: 'Contactgegevens', actual: $after['sourceLabel']);
		$this->assertSame(expected: 'Contact en bereikbaarheid', actual: $after['label']);
		$this->assertSame(expected: 'infocat005', actual: $after['code']);
	}//end testARefreshChangesTheSourceLabelAndKeepsTheLocalOne()

	/**
	 * Without a local label the source's label is shown.
	 *
	 * @return void
	 */
	public function testWithoutALocalLabelTheSourceLabelIsShown(): void {
		$view = $this->view(lists: $this->lists(), store: $this->store(choices: []))->entries(scheme: 'informatiecategorieen');

		$entry = self::entry(view: $view, uri: self::COVENANTS);
		$this->assertSame(expected: 'Convenanten', actual: $entry['label']);
		$this->assertSame(expected: '', actual: $entry['explanation']);
		$this->assertNull(actual: $entry['order']);
		$this->assertFalse(condition: $entry['hidden']);
		$this->assertFalse(condition: $view['choicesUnavailable']);
	}//end testWithoutALocalLabelTheSourceLabelIsShown()

	/**
	 * Unreadable choices: every entry in source order, nothing hidden, and the view says so.
	 *
	 * @return void
	 */
	public function testUnreadableChoicesFallBackToSourceOrderAndSaySo(): void {
		$view = $this->view(lists: $this->lists(), store: $this->store(choices: null))->entries(scheme: 'informatiecategorieen');

		$this->assertTrue(condition: $view['choicesUnavailable']);
		$this->assertSame(expected: ['infocat005', 'infocat011', 'infocat013'], actual: array_column($view['entries'], 'code'));
		$this->assertSame(expected: [false, false, false], actual: array_column($view['entries'], 'hidden'));
		$this->assertCount(expectedCount: 1, haystack: $this->warned);
	}//end testUnreadableChoicesFallBackToSourceOrderAndSaySo()

	/**
	 * A save goes to the choice store unchanged.
	 *
	 * @return void
	 */
	public function testASaveGoesToTheChoiceStore(): void {
		$store = $this->createMock(originalClassName: ValueListChoiceStore::class);
		$store->expects($this->once())->method('save')->with(['scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS, 'hidden' => true]);

		$choice = ['scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS, 'hidden' => true];
		$this->view(lists: $this->lists(), store: $store)->save(choice: $choice);
	}//end testASaveGoesToTheChoiceStore()

	/**
	 * Entries with an order come first, lowest first; the rest follow in source order.
	 *
	 * @return void
	 */
	public function testTheOrganisationsOrderComesFirstThenTheSourceOrder(): void {
		$choices = [
			self::SUBSIDIES => self::choice(fields: ['order' => 0]),
			self::COVENANTS => self::choice(fields: ['explanation' => 'Afspraken met andere partijen.', 'hidden' => true]),
		];

		$view = $this->view(lists: $this->lists(), store: $this->store(choices: $choices))->entries(scheme: 'informatiecategorieen');

		$this->assertSame(expected: ['infocat013', 'infocat005', 'infocat011'], actual: array_column($view['entries'], 'code'));
		$covenants = self::entry(view: $view, uri: self::COVENANTS);
		$this->assertSame(expected: 'Afspraken met andere partijen.', actual: $covenants['explanation']);
		$this->assertTrue(condition: $covenants['hidden']);
	}//end testTheOrganisationsOrderComesFirstThenTheSourceOrder()
}//end class
