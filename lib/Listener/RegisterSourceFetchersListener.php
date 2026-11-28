<?php

/**
 * Adds OpenCatalogi's DCAT catalogue fetcher to OpenRegister's source types.
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
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Harvest\DcatJsonLdFetcher;
use OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the `opencatalogi.dcat-jsonld` fetcher when OpenRegister asks.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */
class RegisterSourceFetchersListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DcatJsonLdFetcher $fetcher The DCAT catalogue fetcher.
	 */
	public function __construct(
		private readonly DcatJsonLdFetcher $fetcher,
	) {
	}//end __construct()

	/**
	 * Contribute the fetcher, owned by this app.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.2
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterSourceFetchersEvent) === false) {
			return;
		}

		$event->registerFetcher(fetcher: $this->fetcher, appId: 'opencatalogi');
	}//end handle()
}//end class
