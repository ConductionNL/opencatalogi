<?php

/**
 * Golden test for the CMSTool page, list, menu item, dispatch and magic-call paths.
 *
 * Every case runs one call over recording ObjectService doubles and compares the
 * answer, the saved objects and the list queries to the recording in
 * fixtures/cms-tool-actions-golden.json.
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
 * Golden test for the CMSTool actions.
 *
 * @covers \OCA\OpenCatalogi\Tool\CMSTool
 */
class CMSToolActionsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cms-tool-actions-golden.json';

	/**
	 * The recorded cases: the method, its arguments, and what the doubles answer.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$pages = [
			['uuid' => 'p-1', 'object' => ['title' => 'Home'], 'slug' => 'home', 'summary' => 'Start'],
			['uuid' => 'p-2', 'object' => []],
		];
		$menus = [
			['uuid' => 'm-1', 'object' => ['title' => 'Main', 'position' => 2, 'items' => [['a'], ['b']]]],
			['uuid' => 'm-2', 'object' => []],
		];

		return [
			'page with title only' => [['call' => 'createPage', 'args' => [['title' => 'Hello World!']]]],
			'page with slug, summary, description' => [['call' => 'createPage', 'args' => [['title' => 'T', 'slug' => 'own', 'summary' => 's', 'description' => 'd']]]],
			'page with an odd title' => [['call' => 'createPage', 'args' => [['title' => '--Über  Uns 2026--']]]],
			'page without title' => [['call' => 'createPage', 'args' => [['slug' => 'x']]]],
			'page with a forged owner' => [['call' => 'createPage', 'args' => [['title' => 'F', 'owner' => 'mallory', 'organisation' => 'other']]]],
			'item with a forged owner' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm', 'name' => 'n', 'link' => '/', 'owner' => 'mallory', 'organisation' => 'other']]]],
			'page with empty title' => [['call' => 'createPage', 'args' => [['title' => '']]]],
			'page without agent' => [['agent' => false, 'call' => 'createPage', 'args' => [['title' => 'A']]]],
			'pages default limit' => [['call' => 'listPages', 'args' => [[]], 'find' => $pages]],
			'pages with a limit' => [['call' => 'listPages', 'args' => [['limit' => 5]], 'find' => []]],
			'menus' => [['call' => 'listMenus', 'args' => [], 'find' => $menus]],
			'menus without agent' => [['agent' => false, 'call' => 'listMenus', 'args' => [], 'find' => []]],
			'item with link' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'name' => 'Over', 'link' => '/over']]]],
			'item with page and order' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'name' => 'Over', 'pageId' => 'p-1', 'order' => 4]]]],
			'item with link and page' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'name' => 'Both', 'link' => '/x', 'pageId' => 'p-1']]]],
			'item without menu' => [['call' => 'addMenuItem', 'args' => [['name' => 'Over', 'link' => '/over']]]],
			'item without name' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'link' => '/over']]]],
			'item without link or page' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'name' => 'Over']]]],
			'item with empty link and page' => [['call' => 'addMenuItem', 'args' => [['menuId' => 'm-1', 'name' => 'Over', 'link' => '', 'pageId' => '']]]],
			'dispatch create page' => [['call' => 'executeFunction', 'args' => ['cms_create_page', ['title' => 'P']]]],
			'dispatch list pages' => [['call' => 'executeFunction', 'args' => ['cms_list_pages', ['limit' => 1]], 'find' => $pages]],
			'dispatch create menu' => [['call' => 'executeFunction', 'args' => ['cms_create_menu', ['title' => 'M', 'items' => [['order' => 1, 'name' => 'a', 'link' => '/']]]]]],
			'dispatch list menus' => [['call' => 'executeFunction', 'args' => ['cms_list_menus', []], 'find' => $menus]],
			'dispatch add item' => [['call' => 'executeFunction', 'args' => ['cms_add_menu_item', ['menuId' => 'm', 'name' => 'n', 'link' => '/']]]],
			'dispatch with a user id' => [['call' => 'executeFunction', 'args' => ['cms_list_menus', [], 'someone'], 'find' => []]],
			'dispatch unknown' => [['call' => 'executeFunction', 'args' => ['cms_delete_all', []]]],
			'dispatch failing save' => [['save' => 'throw', 'call' => 'executeFunction', 'args' => ['cms_create_page', ['title' => 'P']]]],
			'magic create page' => [['call' => 'cms_create_page', 'args' => [['title' => 'Magic']]]],
			'magic list menus' => [['call' => 'cms_list_menus', 'args' => [], 'find' => $menus]],
			'magic list pages with a string list' => [['call' => 'list_pages', 'args' => ['x'], 'find' => []]],
			'magic generate slug' => [['call' => 'generate_slug', 'args' => ['A B']]],
			'magic unknown' => [['call' => 'cms_drop_menu', 'args' => []]],
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
	public function testActionMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$calls = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$calls, $case): ObjectEntity {
				$calls[] = ['saveObject' => self::passedArguments(method: 'saveObject', args: $args)];
				if (($case['save'] ?? 'ok') === 'throw') {
					throw new \RuntimeException('store down');
				}

				$saved = new ObjectEntity();
				$saved->setUuid('saved-' . count($calls));
				$saved->setName((string)($args[0]['name'] ?? $args[0]['title'] ?? ''));
				return $saved;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (...$args) use (&$calls, $case): array {
				$calls[] = ['findAll' => self::passedArguments(method: 'findAll', args: $args)];
				return array_map([self::class, 'entity'], ($case['find'] ?? []));
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('session-user');
		$session->method('getUser')->willReturn($user);

		$tool = new CMSTool($objects, $this->createMock(LoggerInterface::class), $session);
		$agent = null;
		if (($case['agent'] ?? true) === true) {
			$agent = new Agent();
			$agent->setUser('agent-user');
			$agent->setOrganisation('org-1');
		}

		$tool->setAgent($agent);

		try {
			$answer = $tool->{$case['call']}(...$case['args']);
		} catch (\Throwable $e) {
			$answer = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['answer' => $answer, 'calls' => $calls];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testActionMatchesTheRecording()

	/**
	 * A magic call with named arguments saves the page once.
	 *
	 * @return void
	 */
	public function testMagicCallWithNamedArgumentsSavesOnce(): void {
		$objects = $this->createMock(ObjectService::class);
		$page = new ObjectEntity();
		$page->setUuid('p-1');
		$objects->expects($this->once())->method('saveObject')->willReturn($page);

		$session = $this->createMock(IUserSession::class);
		$tool = new CMSTool($objects, $this->createMock(LoggerInterface::class), $session);

		$answer = json_decode((string)$tool->cms_create_page(parameters: ['title' => 'Named']), true);

		$this->assertSame('p-1', $answer['data']['pageId']);

	}//end testMagicCallWithNamedArgumentsSavesOnce()

	/**
	 * An object entity from a case's spec.
	 *
	 * @param array<string, mixed> $spec The uuid, object, slug and summary.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $spec): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($spec['uuid']);
		$entity->setObject($spec['object']);
		if (isset($spec['slug']) === true) {
			$entity->setSlug($spec['slug']);
		}

		if (isset($spec['summary']) === true) {
			$entity->setSummary($spec['summary']);
		}

		return $entity;

	}//end entity()

	/**
	 * The arguments of an ObjectService call by parameter name, without those left at their default.
	 *
	 * @param string            $method The ObjectService method.
	 * @param array<int, mixed> $args   The arguments the mock received.
	 *
	 * @return array<string, mixed> The passed arguments by name.
	 */
	private static function passedArguments(string $method, array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $args[$position] === $parameter->getDefaultValue()) {
				continue;
			}

			$named[$parameter->getName()] = $args[$position];
		}

		return $named;

	}//end passedArguments()
}//end class
