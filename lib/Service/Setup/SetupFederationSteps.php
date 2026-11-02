<?php

/**
 * The setup steps that connect to the default directory and synchronise every directory.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Setup;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Syncs the default directory and announces this instance to it, or synchronises every registered directory.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupFederationSteps {
	/**
	 * Constructor.
	 *
	 * @param DirectoryServiceInterface $directoryService Directory service (federation sync).
	 * @param BroadcastServiceInterface $broadcastService Broadcast service (announce self to a directory).
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DirectoryServiceInterface $directoryService,
		private readonly BroadcastServiceInterface $broadcastService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Connect to the federated network — pull peer listings AND announce this instance.
	 *
	 * Federation is pull-based: a fresh instance only sees peers whose URLs are
	 * already stored on the remote directory. Without a matching push, a brand-
	 * new install always reports "0 new, 0 updated" and admins have no signal
	 * whether their instance is discoverable to others. This step therefore does
	 * two things in sequence and reports both outcomes in the response:
	 *
	 * 1. Pull: sync listings FROM the directory into this instance. Existing
	 *    behaviour.
	 * 2. Push: broadcast this instance's directory URL to the remote directory
	 *    so it can sync back and add us to its catalog. `syncDirectory` already
	 *    triggers an internal broadcast when the remote does not yet know us,
	 *    but the outcome is silently swallowed — this explicit call captures it
	 *    and lets the admin see whether self-registration succeeded.
	 *
	 * Both sub-steps are non-fatal: the wizard reports success as long as the
	 * pull HTTP call itself did not throw. `doCronSync` will keep the state
	 * fresh over time regardless of this step's outcome.
	 *
	 * @return JSONResponse The result envelope (success, message, details).
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function connectFederation(): JSONResponse {
		$directoryUrl = $this->directoryService->getDefaultDirectoryUrl();

		try {
			$result = $this->directoryService->syncDirectory($directoryUrl);
		} catch (\Exception $e) {
			$this->logger->warning('Setup connect-federation sync failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('Could not reach the directory right now. You can skip this — it will sync automatically later.'),
				]
			);
		}

		$created = (int)($result['listings_created'] ?? 0);
		$updated = (int)($result['listings_updated'] ?? 0);

		$advertised = false;
		try {
			$broadcastResults = $this->broadcastService->broadcast($directoryUrl);
			$advertised = (isset($broadcastResults[$directoryUrl]) === true && $broadcastResults[$directoryUrl] === true);
		} catch (\Exception $e) {
			$this->logger->warning('Setup connect-federation advertise failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
		}

		return new JSONResponse(
			[
				'success' => true,
				'message' => $this->buildFederationMessage(created: $created, updated: $updated, advertised: $advertised),
				'details' => [
					'listings_created' => $created,
					'listings_updated' => $updated,
					'advertised' => $advertised,
					'directory_url' => $directoryUrl,
				],
			]
		);

	}//end connectFederation()

	/**
	 * Sync every registered federation directory (wizard step 6).
	 *
	 * The preceding connect-federation step (step 5) only pulls from the
	 * single `default_directory_url` peer to bootstrap federation. Once the
	 * user (or the peer's own broadcast) has registered additional listings,
	 * this step brings them all up to date in one go — the wizard equivalent
	 * of the admin-settings "Sync directories now" button, which routes to
	 * the same `DirectoryService::doCronSync()` used by the periodic cron
	 * (`BackgroundJob\DirectorySync`).
	 *
	 * Non-fatal by design: a peer that is briefly offline should not block
	 * setup completion. The message surfaces the succeeded / failed split so
	 * an admin can retry from the settings page if needed.
	 *
	 * @return JSONResponse The result envelope (success, message, details).
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function syncAllDirectories(): JSONResponse {
		try {
			$result = $this->directoryService->doCronSync();
		} catch (\Exception $e) {
			$this->logger->warning('Setup sync-all-directories failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t(
						'Could not synchronize the directories right now. You can skip this — the periodic cron will retry automatically.'
					),
				]
			);
		}

		$synced = (int)($result['synced_directories'] ?? 0);
		$failed = (int)($result['failed_directories'] ?? 0);
		$total = (int)($result['total_directories'] ?? ($synced + $failed));

		return new JSONResponse(
			[
				'success' => true,
				'message' => $this->l10n->t(
					text: 'Synchronized %1$d of %2$d directories (%3$d failed).',
					parameters: [$synced, $total, $failed]
				),
				'details' => [
					'synced_directories' => $synced,
					'failed_directories' => $failed,
					'total_directories' => $total,
				],
			]
		);

	}//end syncAllDirectories()

	/**
	 * Compose the admin-facing message describing the pull + push outcome.
	 *
	 * Kept separate so message-shape unit tests can exercise every combination
	 * (zero-listings + advertise-ok / zero-listings + advertise-fail / non-zero +
	 * both) without wiring up the full DirectoryService + BroadcastService flow.
	 *
	 * @param integer $created New listings pulled from the directory.
	 * @param integer $updated Existing listings updated during the pull.
	 * @param boolean $advertised True when the broadcast to the directory succeeded.
	 *
	 * @return string The composed message.
	 */
	private function buildFederationMessage(int $created, int $updated, bool $advertised): string {
		$parts = [];

		if ($created > 0 || $updated > 0) {
			$parts[] = $this->l10n->t(
				text: 'Fetched %1$d new and %2$d updated listing(s) from the directory.',
				parameters: [$created, $updated]
			);
		} else {
			$parts[] = $this->l10n->t(
				'The directory has no other peer instances registered yet — federation activates automatically once other instances join.'
			);
		}

		if ($advertised === true) {
			$parts[] = $this->l10n->t(
				'This instance was announced to the directory and is now discoverable to other peers.'
			);
		} else {
			$parts[] = $this->l10n->t(
				'This instance could not be announced to the directory (it may not be reachable from the directory host).'
				. ' Federation still works one-way; retry later or announce it manually.'
			);
		}

		return implode(' ', $parts);
	}//end buildFederationMessage()
}//end class
