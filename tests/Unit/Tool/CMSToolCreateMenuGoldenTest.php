<?php

/**
 * Golden test for CMSTool::createMenu().
 *
 * Every case creates a menu over a recording ObjectService double and compares
 * the answer and the saved object to the recording in
 * fixtures/cms-tool-create-menu-golden.json.
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

namespace Unit\Tool;

use OCA\OpenCatalogi\Tool\CMSTool;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CMSTool::createMenu().
 *
 * @covers \OCA\OpenCatalogi\Tool\CMSTool
 */
class CMSToolCreateMenuGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cms-tool-create-menu-golden.json';

	/**
	 * The recorded cases: parameters, the session user and the agent.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$items = [['order' => 0, 'name' => 'Home', 'link' => '/'], ['order' => 2, 'name' => 'Over', 'link' => '/over']];

		return [
			'session user owns the menu' => [['params' => ['title' => 'Main', 'items' => $items]]],
			'a forged owner is ignored' => [['params' => ['title' => 'Main', 'items' => $items, 'owner' => 'mallory', 'organisation' => 'other']]],
			'a forged userId is ignored' => [['params' => ['title' => 'Main', 'items' => $items, 'userId' => 'mallory']]],
			'agent user without a session' => [['session' => null, 'params' => ['title' => 'Main', 'items' => $items]]],
			'no agent, no session' => [['session' => null, 'agent' => false, 'params' => ['title' => 'Main', 'items' => $items]]],
			'agent never set' => [['agent' => 'unset', 'params' => ['title' => 'Main', 'items' => $items]]],
			'position, groups, hideBeforeLogin' => [['params' => ['title' => 'Main', 'items' => $items, 'position' => 3, 'groups' => ['a'], 'hideBeforeLogin' => 'yes']]],
			'groups not a list' => [['params' => ['title' => 'Main', 'items' => $items, 'groups' => 'a', 'hideBeforeLogin' => 0]]],
			'hideBeforeLogin null' => [['params' => ['title' => 'Main', 'items' => $items, 'hideBeforeLogin' => null]]],
			'no title' => [['params' => ['items' => $items]]],
			'empty title' => [['params' => ['title' => '', 'items' => $items]]],
			'no items' => [['params' => ['title' => 'Main']]],
			'items empty' => [['params' => ['title' => 'Main', 'items' => []]]],
			'items not a list' => [['params' => ['title' => 'Main', 'items' => 'x']]],
			'item without order' => [['params' => ['title' => 'Main', 'items' => [['name' => 'a', 'link' => '/']]]]],
			'item with order as text zero' => [['params' => ['title' => 'Main', 'items' => [['order' => '0', 'name' => 'a', 'link' => '/']]]]],
			'second item without name' => [['params' => ['title' => 'Main', 'items' => [$items[0], ['order' => 1, 'link' => '/']]]]],
			'item without link' => [['params' => ['title' => 'Main', 'items' => ['first' => ['order' => 1, 'name' => 'a']]]]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCreateMenuMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved): ObjectEntity {
				$saved[] = $args;
				$menu = new ObjectEntity();
				$menu->setUuid('menu-1');
				return $menu;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('session-user');
		$session->method('getUser')->willReturn((array_key_exists('session', $case) === true) ? null : $user);

		$tool = new CMSTool($objects, $this->createMock(LoggerInterface::class), $session);
		if (($case['agent'] ?? null) !== 'unset') {
			$agent = null;
			if (($case['agent'] ?? true) === true) {
				$agent = new Agent();
				$agent->setUser('agent-user');
				$agent->setOrganisation('org-1');
			}

			$tool->setAgent($agent);
		}

		$actual = ['answer' => $tool->createMenu($case['params']), 'saved' => $saved];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testCreateMenuMatchesTheRecording()
}//end class
