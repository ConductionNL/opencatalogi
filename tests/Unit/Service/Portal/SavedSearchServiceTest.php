<?php

/**
 * Tests for saving, pausing and deleting a resident's saved searches.
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

require_once __DIR__.'/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\Portal\SavedSearchService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SavedSearchService.
 */
class SavedSearchServiceTest extends TestCase {

	private FakePortalObjectStore $store;

	private SavedSearchService $service;

	protected function setUp(): void {
		$this->store = new FakePortalObjectStore();
		$this->service = new SavedSearchService(store: $this->store);
	}

	/**
	 * Assert the stored saved search passes the shipped schema.
	 *
	 * @param string $id The id.
	 *
	 * @return void
	 */
	private function assertStoredIsValid(string $id): void {
		$root = dirname(__DIR__, 4);
		$fragment = json_decode((string)file_get_contents($root.'/lib/Settings/register.d/saved-searches-and-alerts.json'), true)['components']['schemas']['savedSearch'];
		$data = $this->store->objects['savedSearch'][$id];
		unset($data['id']);
		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			(string)json_encode(['type' => 'object', 'properties' => $fragment['properties'], 'required' => $fragment['required']])
		);
		$this->assertTrue($result->isValid(), 'The stored saved search does not pass the schema');
	}

	public function testSavingWithoutAFrequencyMakesADailyActiveSearchOwnedByTheResident(): void {
		$saved = $this->service->save(owner: 'subject-1', input: [
			'title' => 'Windpark',
			'owner' => 'someone-else',
			'query' => ['text' => 'windpark', 'filters' => ['informatiecategorie' => ['infocat014']]],
		]);

		$stored = $this->store->objects['savedSearch'][$saved['id']];
		$this->assertSame('subject-1', $stored['owner']);
		$this->assertSame('daily', $stored['frequency']);
		$this->assertTrue($stored['active']);
		$this->assertSame(['text' => 'windpark', 'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => [], 'periodFrom' => '', 'periodTo' => ''], 'catalog' => ''], $stored['query']);
		$this->assertArrayNotHasKey('lastRunAt', $stored);
		$this->assertStoredIsValid($saved['id']);
	}

	public function testTheQueryMayArriveAsJson(): void {
		$saved = $this->service->save(owner: 'subject-1', input: ['title' => 'x', 'query' => '{"text":"bouw","filters":{"organisation":["org-1"],"periodFrom":"2026-01-01"}}', 'frequency' => 'weekly']);
		$stored = $this->store->objects['savedSearch'][$saved['id']];
		$this->assertSame(['org-1'], $stored['query']['filters']['organisation']);
		$this->assertSame('2026-01-01', $stored['query']['filters']['periodFrom']);
		$this->assertSame('weekly', $stored['frequency']);
	}

	public function testRefusedInput(): void {
		foreach ([
			['title' => '', 'query' => ['text' => 'x']],
			['title' => 'x', 'query' => 'not json'],
			['title' => 'x', 'query' => ['text' => 'x'], 'frequency' => 'hourly'],
			['title' => 'x', 'query' => ['text' => 'x', 'filters' => ['periodFrom' => 'yesterday']]],
			['title' => 'x', 'query' => ['text' => 'x', 'filters' => ['informatiecategorie' => 'infocat014']]],
			['title' => 'x', 'query' => ['text' => 'x', 'filters' => ['informatiecategorie' => ['infocat999']]]],
		] as $input) {
			try {
				$this->service->save(owner: 'subject-1', input: $input);
				$this->fail('Expected refusal: '.json_encode($input));
			} catch (PortalInputException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], ($this->store->objects['savedSearch'] ?? []));
	}

	public function testPauseAndDeleteOnlyYourOwn(): void {
		$saved = $this->service->save(owner: 'subject-1', input: ['title' => 'x', 'query' => ['text' => 'x']]);

		foreach ([fn () => $this->service->pause(owner: 'subject-2', savedSearchId: $saved['id']), fn () => $this->service->delete(owner: 'subject-2', savedSearchId: $saved['id'])] as $call) {
			try {
				$call();
				$this->fail('Expected not found');
			} catch (PortalNotFoundException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->service->pause(owner: 'subject-1', savedSearchId: $saved['id']);
		$this->assertFalse($this->store->objects['savedSearch'][$saved['id']]['active']);
		$this->assertStoredIsValid($saved['id']);

		$this->service->delete(owner: 'subject-1', savedSearchId: $saved['id']);
		$this->assertSame([], $this->store->objects['savedSearch']);
	}

	public function testAResidentKeepsAtMostTwentyFive(): void {
		for ($i = 0; $i < SavedSearchService::MAX_PER_OWNER; $i++) {
			$this->store->objects['savedSearch']['s'.$i] = ['id' => 's'.$i, 'owner' => 'subject-1'];
		}

		$this->expectException(PortalInputException::class);
		$this->service->save(owner: 'subject-1', input: ['title' => 'x', 'query' => ['text' => 'x']]);
	}
}
