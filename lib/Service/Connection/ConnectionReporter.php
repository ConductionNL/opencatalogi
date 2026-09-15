<?php

/**
 * OpenCatalogi connection reporter.
 *
 * Tells integriq's connection registry what only OpenCatalogi can see about
 * its outside connections: what the last directory sync, broadcast and
 * Woo-index readiness check met, and which connection a settings save
 * touched. Integriq owns the rows the Integrations page lists and works out
 * each status itself (hydra change connection-registry, design D4).
 * OpenCatalogi reports, and asks for a fresh resolve after a save.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Connection;

use OCA\OpenCatalogi\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = Application::APP_ID;

	/**
	 * Integriq's report event (ADR-041). Named by string so OpenCatalogi stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Named by string for the same reason.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The federation directory sync connection key.
	 *
	 * @var string
	 */
	public const KEY_DIRECTORY = 'directory';

	/**
	 * The directory broadcast connection key.
	 *
	 * @var string
	 */
	public const KEY_BROADCAST = 'broadcast';

	/**
	 * The Woo-index harvester connection key.
	 *
	 * @var string
	 */
	public const KEY_WOO_INDEX = 'woo-index';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [self::KEY_DIRECTORY, self::KEY_BROADCAST, self::KEY_WOO_INDEX];

	/**
	 * App-config keys per connection whose save asks integriq to resolve again.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const REFRESH_KEYS = [
		self::KEY_DIRECTORY => ['default_directory_url'],
		self::KEY_WOO_INDEX => [
			'woo_index_registration_status',
			'woo_index_registration_url',
			'woo_index_registration_at',
		],
	];

	/**
	 * The connections whose reports are throttled.
	 *
	 * A broadcast runs every four hours from cron, and again for every new
	 * directory a sync finds, so one sync can send many. A sync and a
	 * readiness check are bounded by their own interval or by an admin's
	 * button, so they report every time.
	 *
	 * @var array<int, string>
	 */
	public const THROTTLED_KEYS = [self::KEY_BROADCAST];

	/**
	 * Prefix of the app-config key that remembers the last report per connection.
	 *
	 * @var string
	 */
	public const MEMORY_KEY_PREFIX = 'connection_report_';

	/**
	 * Seconds after which the same status is reported again.
	 *
	 * @var int
	 */
	public const REPEAT_SECONDS = 3600;

	/**
	 * Seconds that must pass before a different status is reported.
	 *
	 * @var int
	 */
	public const CHANGE_SECONDS = 300;

	/**
	 * The pure outcome mapper.
	 *
	 * @var ConnectionObservations
	 */
	private readonly ConnectionObservations $observations;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events (ADR-041).
	 * @param IAppConfig       $appConfig       Keeps the report memory of throttled connections.
	 * @param ITimeFactory     $timeFactory     Tells the time for the report memory.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->observations = new ConnectionObservations();
	}//end __construct()

	/**
	 * Report what a directory sync met.
	 *
	 * @param array<string, mixed> $results The result of DirectoryService::doCronSync().
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportDirectorySync(array $results): bool {
		return $this->reportObserved(
			key: self::KEY_DIRECTORY,
			observe: fn (): array => $this->observations->directorySync(results: $results)
		);
	}//end reportDirectorySync()

	/**
	 * Report what a broadcast met. Throttled.
	 *
	 * @param array<string, bool> $results Target URL to whether it accepted the broadcast.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportBroadcast(array $results): bool {
		return $this->reportObserved(
			key: self::KEY_BROADCAST,
			observe: fn (): array => $this->observations->broadcast(results: $results)
		);
	}//end reportBroadcast()

	/**
	 * Report a broadcast refused because this instance advertises a local address. Throttled.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportBroadcastFromLocalAddress(): bool {
		return $this->reportObserved(
			key: self::KEY_BROADCAST,
			observe: fn (): array => $this->observations->broadcastFromLocalAddress()
		);
	}//end reportBroadcastFromLocalAddress()

	/**
	 * Report what a Woo-index readiness check met.
	 *
	 * @param array<string, mixed> $report The report WooReadinessService::runCheck() returns.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooReadiness(array $report): bool {
		return $this->reportObserved(
			key: self::KEY_WOO_INDEX,
			observe: fn (): array => $this->observations->wooReadiness(report: $report)
		);
	}//end reportWooReadiness()

	/**
	 * Report a readiness check refused because no catalog has Woo sitemaps switched on.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooNotConfigured(): bool {
		return $this->reportObserved(
			key: self::KEY_WOO_INDEX,
			observe: fn (): array => $this->observations->wooNotConfigured()
		);
	}//end reportWooNotConfigured()

	/**
	 * Report a readiness check that stopped with an exception.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function reportWooCheckStopped(): bool {
		return $this->reportObserved(
			key: self::KEY_WOO_INDEX,
			observe: fn (): array => $this->observations->wooCheckStopped()
		);
	}//end reportWooCheckStopped()

	/**
	 * Ask integriq to resolve every connection whose config keys a save wrote.
	 *
	 * Clears that connection's report memory too, so the next outcome reports
	 * at once instead of waiting out the hour. Integriq decides the status
	 * (design D6). Never throws.
	 *
	 * @param array<int, string> $savedKeys The app-config keys the save wrote.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function refreshFromSave(array $savedKeys): array {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$refreshed = [];
		foreach (self::REFRESH_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, $savedKeys) === []) {
				continue;
			}

			$this->forget(key: $key);
			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
				)
			);
			if ($sent === true) {
				$refreshed[] = $key;
			}
		}

		return $refreshed;
	}//end refreshFromSave()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Observe, throttle when the key asks for it, and send one status report. Never throws.
	 *
	 * Without integriq the class check fails first, so nothing is read,
	 * stored, sent or logged.
	 *
	 * @param string                               $key     One of {@see self::KEYS}.
	 * @param callable(): array{0: string, 1: string} $observe Works out the status and message.
	 *
	 * @return bool True when a report was sent.
	 */
	private function reportObserved(string $key, callable $observe): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			[$status, $message] = $observe();

			$throttled = in_array($key, self::THROTTLED_KEYS, true);
			$now       = $this->timeFactory->getTime();
			if ($throttled === true && $this->isDue(key: $key, status: $status, now: $now) === false) {
				return false;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($sent === true && $throttled === true) {
				$this->appConfig->setValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, $status . '|' . $now);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: could not report a connection to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end reportObserved()

	/**
	 * Whether the report memory allows a report with this status now.
	 *
	 * A different status waits five minutes after the last report, so peers
	 * that disagree cannot report on every broadcast. The same status reports
	 * again after an hour.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status the outcome says.
	 * @param int    $now    The current Unix time.
	 *
	 * @return bool
	 */
	private function isDue(string $key, string $status, int $now): bool {
		$memory = $this->appConfig->getValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, '');
		$parts  = explode('|', $memory, 2);
		if (count($parts) !== 2 || ctype_digit($parts[1]) === false) {
			return true;
		}

		$elapsed = ($now - (int) $parts[1]);
		if ($parts[0] === $status) {
			return $elapsed >= self::REPEAT_SECONDS;
		}

		return $elapsed >= self::CHANGE_SECONDS;
	}//end isDue()

	/**
	 * Clear the report memory of one connection.
	 *
	 * @param string $key The connection key.
	 *
	 * @return void
	 */
	private function forget(string $key): void {
		if (in_array($key, self::THROTTLED_KEYS, true) === false) {
			return;
		}

		try {
			$this->appConfig->deleteKey(self::APP_ID, self::MEMORY_KEY_PREFIX . $key);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: could not clear a connection report memory',
				['key' => $key, 'exception' => $e->getMessage()]
			);
		}
	}//end forget()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
