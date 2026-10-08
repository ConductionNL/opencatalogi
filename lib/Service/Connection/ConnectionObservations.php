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
	 * What a directory sync says about the directories.
	 *
	 * @param array<string, mixed> $results The result of DirectoryService::doCronSync().
	 *
	 * @return array{0: string, 1: string} The status and message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function directorySync(array $results): array {
		$total  = (int) ($results['total_directories'] ?? 0);
		$failed = (int) ($results['failed_directories'] ?? 0);
		$synced = (int) ($results['synced_directories'] ?? 0);

		if ($total === 0) {
			return ['unconfigured', 'No directory is known yet, so the last sync had nothing to read.'];
		}

		if ($failed === 0 && $total === 1) {
			return ['configured', 'The directory answered the last sync.'];
		}

		if ($failed === 0) {
			return ['configured', 'All ' . $total . ' directories answered the last sync.'];
		}

		$errors = array_values((array) ($results['errors'] ?? []));
		$first  = $this->hostOf(url: (string) ($errors[0]['directory'] ?? ''), fallback: 'A directory');
		if ($synced === 0) {
			return ['error', 'No directory answered the last sync. ' . $first . ' did not.'];
		}

		return ['limited', $synced . ' of ' . $total . ' directories answered the last sync. ' . $first . ' did not.'];
	}//end directorySync()

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

		$failed   = array_keys(array_filter($results, static fn (mixed $accepted): bool => $accepted !== true));
		$accepted = ($total - count($failed));

		if ($failed === []) {
			if ($total === 1) {
				return ['configured', $this->hostOf(url: (string) array_key_first($results), fallback: 'The peer directory') . ' accepted the last broadcast.'];
			}

			return ['configured', 'All ' . $total . ' peer directories accepted the last broadcast.'];
		}

		$first = $this->hostOf(url: (string) $failed[0], fallback: 'A peer directory');
		if ($accepted === 0 && $total === 1) {
			return ['error', $first . ' did not accept the last broadcast.'];
		}

		if ($accepted === 0) {
			return ['error', 'No peer directory accepted the last broadcast. ' . $first . ' did not.'];
		}

		return ['limited', $accepted . ' of ' . $total . ' peer directories accepted the last broadcast. ' . $first . ' did not.'];
	}//end broadcast()

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
		$passed       = 0;
		$failed       = 0;
		$registration = null;
		foreach ((array) ($report['checks'] ?? []) as $check) {
			if (is_array($check) === false) {
				continue;
			}

			if (($check['id'] ?? '') === self::REGISTRATION_CHECK) {
				$registration = $check;
				continue;
			}

			$passed += (int) (($check['status'] ?? '') === 'pass');
			$failed += (int) (($check['status'] ?? '') === 'fail');
		}

		if ($failed > 0 && $passed === 0) {
			return ['error', 'No readiness check passed. The Woo-index section lists what failed.'];
		}

		if ($failed > 0) {
			return ['limited', $failed . ' of ' . ($passed + $failed) . ' readiness checks failed. The Woo-index section lists which.'];
		}

		return $this->wooRegistration(registration: $registration);
	}//end wooReadiness()

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
		$status = (string) ($registration['status'] ?? '');
		$reason = (string) ($registration['reason'] ?? '');

		if ($status === 'pass') {
			return ['configured', 'Every readiness check passed, and the Woo-index registration matches this address.'];
		}

		if ($reason === 'url-mismatch') {
			return ['limited', 'Every readiness check passed. The registered Woo-index URL does not match this address.'];
		}

		if ($reason === 'registration-pending') {
			return ['limited', 'Every readiness check passed. The Woo-index registration is requested and not confirmed yet.'];
		}

		return ['limited', 'Every readiness check passed. This instance is not registered with the Woo-index yet.'];
	}//end wooRegistration()

	/**
	 * The host of a URL, or a fallback name when the URL has none.
	 *
	 * @param string $url      The URL.
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
