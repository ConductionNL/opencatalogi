<?php

/**
 * OpenCatalogi connection observations.
 *
 * Turns an outcome OpenCatalogi already has, such as a directory sync result,
 * a broadcast result or a Woo-index readiness report, into the status and
 * message integriq's connection registry shows (hydra change
 * connection-registry, design D4 and D6). Pure: it holds no state, reads
 * nothing and sends nothing, so every mapping is testable without a double.
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

/**
 * Maps sync, broadcast and readiness outcomes to connection statuses and messages.
 *
 * Every method answers `[status, message]`. A message names a directory or a
 * peer by its host only: a path, a query or user info can carry a secret, and
 * every admin reads the row.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */
class ConnectionObservations {

	/**
	 * The check id WooReadinessService gives the registration check.
	 *
	 * @var string
	 */
	public const REGISTRATION_CHECK = 'registration';

	/**
	 * What a passing readiness check adds about the registration, per registration skip or fail reason.
	 */
	private const REGISTRATION_NOTES = [
		'url-mismatch' => 'The registered Woo-index URL does not match this address.',
		'registration-pending' => 'The Woo-index registration is requested and not confirmed yet.',
	];

	/**
	 * What a passing readiness check adds when this instance is not registered.
	 */
	private const NOT_REGISTERED = 'This instance is not registered with the Woo-index yet.';

	/**
	 * What a directory sync says about the directories.
	 *
	 * @param array<string, mixed> $results The result of DirectoryService::doCronSync().
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function directorySync(array $results): array {
		$total = (int)($results['total_directories'] ?? 0);
		$failed = (int)($results['failed_directories'] ?? 0);
		$synced = (int)($results['synced_directories'] ?? 0);

		if ($total === 0) {
			return ['unconfigured', 'No directory is known yet, so the last sync had nothing to read.'];
		}

		if ($failed === 0) {
			return ['configured', $this->allAnswered(total: $total)];
		}

		$first = $this->firstFailedDirectory(results: $results);
		if ($synced === 0) {
			return ['error', 'No directory answered the last sync. ' . $first . ' did not.'];
		}

		return ['limited', $synced . ' of ' . $total . ' directories answered the last sync. ' . $first . ' did not.'];
	}//end directorySync()

	/**
	 * The message of a sync every directory answered.
	 *
	 * @param int $total The number of directories.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	private function allAnswered(int $total): string {
		if ($total === 1) {
			return 'The directory answered the last sync.';
		}

		return 'All ' . $total . ' directories answered the last sync.';
	}//end allAnswered()

	/**
	 * The host of the first directory a sync reported an error for.
	 *
	 * @param array<string, mixed> $results The sync results.
	 *
	 * @return string The host, or `A directory` when it has none.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	private function firstFailedDirectory(array $results): string {
		$errors = array_values((array)($results['errors'] ?? []));

		return $this->hostOf(url: (string)($errors[0]['directory'] ?? ''), fallback: 'A directory');
	}//end firstFailedDirectory()

	/**
	 * What a broadcast says about the peer directories.
	 *
	 * @param array<string, bool> $results Target URL to whether it accepted the broadcast.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function broadcast(array $results): array {
		$total = count($results);
		if ($total === 0) {
			return ['unconfigured', 'No peer directory is known yet, so the last broadcast told nobody.'];
		}

		$failed = array_keys(array_filter($results, static fn (mixed $accepted): bool => $accepted !== true));
		$accepted = ($total - count($failed));

		if ($failed === []) {
			return $this->allAccepted(results: $results);
		}

		$first = $this->hostOf(url: (string)$failed[0], fallback: 'A peer directory');
		if ($accepted > 0) {
			return ['limited', $accepted . ' of ' . $total . ' peer directories accepted the last broadcast. ' . $first . ' did not.'];
		}

		return ['error', $this->noneAccepted(first: $first, total: $total)];
	}//end broadcast()

	/**
	 * The message of a broadcast no peer accepted.
	 *
	 * @param string $first The first refusing peer's host.
	 * @param int $total The number of peers.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	private function noneAccepted(string $first, int $total): string {
		if ($total === 1) {
			return $first . ' did not accept the last broadcast.';
		}

		return 'No peer directory accepted the last broadcast. ' . $first . ' did not.';
	}//end noneAccepted()

	/**
	 * The observation of a broadcast every peer accepted: the peer by host when there was one.
	 *
	 * @param array<string, mixed> $results The broadcast results, by peer URL.
	 *
	 * @return array{0: string, 1: string} The state and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	private function allAccepted(array $results): array {
		if (count($results) === 1) {
			return ['configured', $this->hostOf(url: (string)array_key_first($results), fallback: 'The peer directory') . ' accepted the last broadcast.'];
		}

		return ['configured', 'All ' . count($results) . ' peer directories accepted the last broadcast.'];
	}//end allAccepted()

	/**
	 * What a refused broadcast says: this instance advertises an address no peer can reach.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function broadcastFromLocalAddress(): array {
		return [
			'unconfigured',
			'This instance advertises a local address, so no peer directory is told about it. '
			. 'Set overwrite.cli.url to an address peers can reach.',
		];
	}//end broadcastFromLocalAddress()

	/**
	 * What a Woo-index readiness report says about the harvester.
	 *
	 * @param array<string, mixed> $report The report WooReadinessService::runCheck() returns.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function wooReadiness(array $report): array {
		[$passed, $failed, $registration] = $this->readinessTally(report: $report);
		if ($failed > 0 && $passed === 0) {
			return ['error', 'No readiness check passed. The Woo-index section lists what failed.'];
		}

		if ($failed > 0) {
			return ['limited', $failed . ' of ' . ($passed + $failed) . ' readiness checks failed. The Woo-index section lists which.'];
		}

		return $this->wooRegistration(registration: $registration);
	}//end wooReadiness()

	/**
	 * The passed and failed readiness checks, and the registration check apart.
	 *
	 * @param array<string, mixed> $report The persisted readiness report.
	 *
	 * @return array{0: int, 1: int, 2: array|null} Passed, failed, and the registration check or null.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	private function readinessTally(array $report): array {
		$tally = [0, 0, null];
		foreach ((array)($report['checks'] ?? []) as $check) {
			if (is_array($check) === false) {
				continue;
			}

			if (($check['id'] ?? '') === self::REGISTRATION_CHECK) {
				$tally[2] = $check;
				continue;
			}

			$tally[0] += (int)(($check['status'] ?? '') === 'pass');
			$tally[1] += (int)(($check['status'] ?? '') === 'fail');
		}

		return $tally;
	}//end readinessTally()

	/**
	 * What a readiness check without a Woo-enabled catalog says.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function wooNotConfigured(): array {
		return [
			'unconfigured',
			'No catalog has Woo sitemaps switched on, so the harvester has nothing to read. Switch them on for a catalog first.',
		];
	}//end wooNotConfigured()

	/**
	 * What a readiness check that stopped with an exception says.
	 *
	 * The exception text is left out: it can carry a URL with a secret in it.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function wooCheckStopped(): array {
		return ['error', 'The last readiness check stopped before it finished.'];
	}//end wooCheckStopped()

	/**
	 * What the registration check says, once every other check passed.
	 *
	 * @param array<string, mixed>|null $registration The registration check entry, or null when absent.
	 *
	 * @return array{0: string, 1: string} The status and message.
	 */
	private function wooRegistration(?array $registration): array {
		$status = (string)($registration['status'] ?? '');
		$reason = (string)($registration['reason'] ?? '');

		if ($status === 'pass') {
			return ['configured', 'Every readiness check passed, and the Woo-index registration matches this address.'];
		}

		return ['limited', 'Every readiness check passed. ' . (self::REGISTRATION_NOTES[$reason] ?? self::NOT_REGISTERED)];
	}//end wooRegistration()

	/**
	 * The host of a URL, or a fallback name when the URL has none.
	 *
	 * @param string $url The URL.
	 * @param string $fallback The name to use without a host.
	 *
	 * @return string The host or the fallback.
	 */
	private function hostOf(string $url, string $fallback): string {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return $fallback;
		}

		return $host;
	}//end hostOf()
}//end class
