<?php

/**
 * Golden test for CatalogScopePendingListener::handle().
 *
 * Every case hands register events to the listener and compares the app config
 * reads, the backfills and the log lines to the recording in
 * fixtures/catalog-scope-pending-golden.json.
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

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Listener\CatalogScopePendingListener;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CatalogScopePendingListener::handle().
 *
 * @covers \OCA\OpenCatalogi\Listener\CatalogScopePendingListener
 */
class CatalogScopePendingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-scope-pending-golden.json';

	/**
	 * The recorded cases.
	 *
	 * Events are ['created'|'updated', id, slug] or 'other'. Keys besides events:
	 * pending (the stored JSON text), backfill ('throw', or 'nested' to fire the
	 * same event again from inside the backfill).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$stack = ['created', 20, 'stackiq'];

		return [
			'another event' => [['events' => ['other']]],
			'created, pending by slug' => [['events' => [$stack]]],
			'created, pending by id' => [['pending' => '["20"]', 'events' => [$stack]]],
			'updated, pending by slug' => [['events' => [['updated', 20, 'stackiq']]]],
			'slug in capitals' => [['events' => [['created', 20, 'StackIQ']]]],
			'pending in capitals' => [['pending' => '["StackIQ"]', 'events' => [$stack]]],
			'pending id as a number' => [['pending' => '[20]', 'events' => [$stack]]],
			'unrelated register' => [['events' => [['created', 5, 'publication']]]],
			'only the old register pending' => [['pending' => '["old-slug","99"]', 'events' => [['updated', 20, 'stackiq']]]],
			'nothing pending' => [['pending' => '[]', 'events' => [$stack]]],
			'pending not json' => [['pending' => 'stackiq', 'events' => [$stack]]],
			'pending a string' => [['pending' => '"stackiq"', 'events' => [$stack]]],
			'pending an object' => [['pending' => '{"a":"stackiq"}', 'events' => [$stack]]],
			'several pending' => [['pending' => '["x","stackiq","20"]', 'events' => [$stack]]],
			'backfill fails' => [['backfill' => 'throw', 'events' => [$stack]]],
			'backfill fails, next event runs again' => [['backfill' => 'throw', 'events' => [$stack, $stack]]],
			'backfill fires the event again' => [['backfill' => 'nested', 'events' => [$stack]]],
			'two events' => [['events' => [$stack, ['updated', 20, 'stackiq'], 'other']]],
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
	public function testHandleMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$calls = [];
		$listener = null;
		$current = null;

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$calls, $case): string {
				$calls[] = ['getValueString', $app, $key, $default];
				return ($key === SettingsServiceInterface::CATALOG_SCOPE_PENDING_KEY ? ($case['pending'] ?? '["stackiq"]') : $default);
			}
		);

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('backfillCatalogScopes')->willReturnCallback(
			static function () use (&$calls, &$listener, &$current, $case): array {
				$calls[] = ['backfillCatalogScopes'];
				if (($case['backfill'] ?? null) === 'throw') {
					throw new \RuntimeException('backfill down');
				}

				if (($case['backfill'] ?? null) === 'nested') {
					$listener->handle($current);
				}

				return ['updated' => 1];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function ($message, array $context = []) use (&$calls, $level): void {
					if (isset($context['exception']) === true) {
						$context['exception'] = get_class($context['exception']) . ': ' . $context['exception']->getMessage();
					}

					$calls[] = [$level, (string)$message, $context];
				}
			);
		}

		$listener = new CatalogScopePendingListener($settings, $config, $logger);
		foreach ($case['events'] as $event) {
			$current = self::event(event: $event);
			$listener->handle($current);
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $calls;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($calls), true));

	}//end testHandleMatchesTheRecording()

	/**
	 * The event for a case entry.
	 *
	 * @param mixed $event ['created'|'updated', id, slug], or 'other'.
	 *
	 * @return Event The event.
	 */
	private static function event(mixed $event): Event {
		if ($event === 'other') {
			return new Event();
		}

		$register = new Register();
		$register->setId($event[1]);
		$register->setSlug($event[2]);
		if ($event[0] === 'created') {
			return new RegisterCreatedEvent(register: $register);
		}

		$old = new Register();
		$old->setId(99);
		$old->setSlug('old-slug');

		return new RegisterUpdatedEvent(newRegister: $register, oldRegister: $old);

	}//end event()
}//end class
