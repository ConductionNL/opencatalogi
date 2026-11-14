<?php

/**
 * Golden test for how SavedSearchService::save() reads and refuses its input.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

require_once __DIR__ . '/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Service\Portal\SavedSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every input SavedSearchService::save() is given, and what it stores or refuses.
 */
class SavedSearchInputGoldenTest extends TestCase {

	/**
	 * Inputs and the stored data or the refusal's message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|string}>
	 */
	public static function inputs(): array {
		$query = ['text' => '', 'filters' => ['informatiecategorie' => [], 'organisation' => [], 'periodFrom' => '', 'periodTo' => ''], 'catalog' => ''];
		$stored = static fn (array $over): array => array_replace(['title' => 'T', 'owner' => 'subject-1', 'query' => $query, 'frequency' => 'daily', 'active' => true], $over);
		return [
			'minimal' => [['title' => ' T ', 'query' => []], $stored([])],
			'no title' => [['query' => []], 'A saved search needs a title of at most 200 characters'],
			'title not text' => [['title' => 5, 'query' => []], 'A saved search needs a title of at most 200 characters'],
			'blank title' => [['title' => '   ', 'query' => []], 'A saved search needs a title of at most 200 characters'],
			'title too long' => [['title' => str_repeat('é', 201), 'query' => []], 'A saved search needs a title of at most 200 characters'],
			'title at the limit' => [['title' => str_repeat('é', 200), 'query' => []], $stored(['title' => str_repeat('é', 200)])],
			'weekly' => [['title' => 'T', 'frequency' => 'weekly', 'query' => []], $stored(['frequency' => 'weekly'])],
			'empty frequency' => [['title' => 'T', 'frequency' => '', 'query' => []], $stored([])],
			'unknown frequency' => [['title' => 'T', 'frequency' => 'hourly', 'query' => '{'], 'frequency must be immediate, daily or weekly'],
			'no query' => [['title' => 'T'], 'query must be an object'],
			'bad json' => [['title' => 'T', 'query' => '{'], 'query must be an object'],
			'json query' => [['title' => 'T', 'query' => '{"text":" a ","catalog":"c"}'], $stored(['query' => array_replace($query, ['text' => 'a', 'catalog' => 'c'])])],
			'filters not object' => [['title' => 'T', 'query' => ['filters' => 'x', 'text' => 5]], 'query.filters must be an object'],
			'text not text' => [['title' => 'T', 'query' => ['text' => 5, 'catalog' => 5]], 'query.text must be text of at most 500 characters'],
			'null text' => [['title' => 'T', 'query' => ['text' => null]], $stored([])],
			'catalog too long' => [['title' => 'T', 'query' => ['catalog' => str_repeat('c', 256), 'filters' => ['informatiecategorie' => 'x']]], 'query.catalog must be text of at most 255 characters'],
			'categories not list' => [['title' => 'T', 'query' => ['filters' => ['informatiecategorie' => ['a' => 'infocat014'], 'periodFrom' => 'x']]], 'informatiecategorie must be a list'],
			'too many categories' => [['title' => 'T', 'query' => ['filters' => ['informatiecategorie' => array_fill(0, 51, 'infocat014')]]], 'informatiecategorie must be a list'],
			'category not text' => [['title' => 'T', 'query' => ['filters' => ['informatiecategorie' => ['infocat014', 3, 'nope']]]], 'informatiecategorie must hold text values'],
			'empty category' => [['title' => 'T', 'query' => ['filters' => ['informatiecategorie' => ['']]]], 'informatiecategorie must hold text values'],
			'unknown category' => [['title' => 'T', 'query' => ['filters' => ['informatiecategorie' => ['infocat014', 'nope'], 'periodFrom' => 'x']]], 'Unknown information category: nope'],
			'bad periodFrom' => [['title' => 'T', 'query' => ['filters' => ['periodFrom' => '2026-1-1', 'periodTo' => 'x', 'organisation' => 'x']]], 'periodFrom must be a date like 2026-01-31'],
			'long periodTo' => [['title' => 'T', 'query' => ['filters' => ['periodTo' => '2026-01-011', 'organisation' => 'x']]], 'periodTo must be text of at most 10 characters'],
			'bad periodTo' => [['title' => 'T', 'query' => ['filters' => ['periodTo' => '31-01-2026', 'organisation' => 'x']]], 'periodTo must be a date like 2026-01-31'],
			'organisation not list' => [['title' => 'T', 'query' => ['filters' => ['organisation' => 'x']]], 'organisation must be a list'],
			'organisation entry too long' => [['title' => 'T', 'query' => ['filters' => ['organisation' => [str_repeat('o', 256)]]]], 'organisation must hold text values'],
			'full' => [
				['title' => 'T', 'frequency' => 'immediate', 'query' => ['text' => 'wind', 'catalog' => 'woo', 'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => ['org-1'], 'periodFrom' => ' 2026-01-01 ', 'periodTo' => '2026-12-31']]],
				$stored(['frequency' => 'immediate', 'query' => ['text' => 'wind', 'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => ['org-1'], 'periodFrom' => '2026-01-01', 'periodTo' => '2026-12-31'], 'catalog' => 'woo']]),
			],
		];

	}//end inputs()

	/**
	 * What one input stores, or why it is refused.
	 *
	 * @param array<string, mixed> $input The input.
	 * @param array<string, mixed>|string $expected The stored data, or the refusal's message.
	 *
	 * @return void
	 */
	#[DataProvider('inputs')]
	public function testSave(array $input, array|string $expected): void {
		$store = new FakePortalObjectStore();
		try {
			$saved = (new SavedSearchService(store: $store))->save(owner: 'subject-1', input: $input);
			unset($saved['id']);
			$actual = $saved;
		} catch (PortalInputException $e) {
			$actual = $e->getMessage();
		}

		$this->assertSame($expected, $actual);

	}//end testSave()

	/**
	 * An owner with the most saved searches cannot save another, and the input
	 * is checked first.
	 *
	 * @return void
	 */
	public function testTheLimitIsCheckedAfterTheInput(): void {
		$store = new FakePortalObjectStore();
		$service = new SavedSearchService(store: $store);
		for ($i = 0; $i < SavedSearchService::MAX_PER_OWNER; $i++) {
			$service->save(owner: 'subject-1', input: ['title' => 'T' . $i, 'query' => []]);
		}

		$messages = [];
		foreach ([['title' => 'x', 'query' => 'nope'], ['title' => 'x', 'query' => []]] as $input) {
			try {
				$service->save(owner: 'subject-1', input: $input);
			} catch (PortalInputException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(['query must be an object', 'You have the most saved searches you can have'], $messages);
		$this->assertArrayHasKey('id', $service->save(owner: 'subject-2', input: ['title' => 'x', 'query' => []]));

	}//end testTheLimitIsCheckedAfterTheInput()
}//end class
