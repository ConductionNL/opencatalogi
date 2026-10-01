<?php

/**
 * OpenCatalogi listener that completes a seeded catalogue scope when its register appears.
 *
 * A catalogue seeded by OpenCatalogi can name a register of another app by slug,
 * such as `applicatielandschap` naming stackiq's `stackiq` register. When that
 * app is installed after OpenCatalogi, OpenCatalogi's own import has already run
 * and left the slug unresolved. OpenRegister fires a register event when the
 * other app's import creates or updates its register; this listener then runs
 * the same backfill the import runs, so the scope resolves without a reinstall.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-runs the catalogue scope backfill when a pending register is created or updated.
 *
 * @template-implements IEventListener<Event>
 */
class CatalogScopePendingListener implements IEventListener {

	/**
	 * Guards against a backfill that, through some other listener, causes another register event.
	 *
	 * @var boolean
	 */
	private static bool $running = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Runs the backfill.
	 * @param IAppConfig $appConfig Holds the pending list.
	 * @param LoggerInterface $logger Logs a failed backfill.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the backfill when the event's register is one a catalogue scope waits for.
	 *
	 * Costs one app config read for every other register event, so it stays
	 * cheap during an import that updates many registers.
	 *
	 * @param Event $event The register event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function handle(Event $event): void {
		$register = $this->registerFrom(event: $event);
		if ($register === null || self::$running === true) {
			return;
		}

		$pending = json_decode(
			$this->appConfig->getValueString('opencatalogi', SettingsService::CATALOG_SCOPE_PENDING_KEY, '[]'),
			true
		);
		if (is_array($pending) === false || $pending === []) {
			return;
		}

		$keys = [strtolower((string)$register->getSlug()), (string)$register->getId()];
		if (array_intersect($keys, $pending) === []) {
			return;
		}

		self::$running = true;
		try {
			$this->settingsService->backfillCatalogScopes();
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: could not complete a catalogue scope after a register event: ' . $e->getMessage(),
				['exception' => $e]
			);
		} finally {
			self::$running = false;
		}
	}//end handle()

	/**
	 * Take the register out of a register event.
	 *
	 * @param Event $event The event.
	 *
	 * @return Register|null The register, or null for any other event.
	 */
	private function registerFrom(Event $event): ?Register {
		if ($event instanceof RegisterCreatedEvent) {
			return $event->getRegister();
		}

		if ($event instanceof RegisterUpdatedEvent) {
			return $event->getNewRegister();
		}

		return null;
	}//end registerFrom()
}//end class
