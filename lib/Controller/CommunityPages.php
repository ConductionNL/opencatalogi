<?php

/**
 * The status page, the catalogue feed and markup rendering, for the community controller.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\AtomFeedServiceInterface;
use OCA\OpenCatalogi\Contract\MarkupRenderServiceInterface;
use OCA\OpenCatalogi\Contract\StatusChange;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;

/**
 * Renders the status page, sets a component's state, builds a catalogue's Atom
 * feed and renders markup; and resolves the configured register pairs and the
 * ObjectService those pages are read from and stored in.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */
class CommunityPages {
	use ForwardsRegisterAccess;

	/**
	 * Constructor.
	 *
	 * @param StatusPageServiceInterface $statusService The status page.
	 * @param AtomFeedServiceInterface $feedService The catalogue feed.
	 * @param MarkupRenderServiceInterface $markupService The markup renderer.
	 * @param RegisterAccess $registers The configured register pairs and the ObjectService.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly StatusPageServiceInterface $statusService,
		private readonly AtomFeedServiceInterface $feedService,
		private readonly MarkupRenderServiceInterface $markupService,
		private readonly RegisterAccess $registers,
	) {

	}//end __construct()

	/**
	 * The status page as an anonymous reader sees it.
	 *
	 * @param array $components The stored statuses.
	 * @param int $stalenessHours How long a state stays current.
	 *
	 * @return array The components and their states.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function renderStatus(array $components, int $stalenessHours): array {
		return $this->statusService->render(components: $components, stalenessHours: $stalenessHours);
	}//end renderStatus()

	/**
	 * Set a component's state.
	 *
	 * @param array{component: string, state: string, message: string, setBy: string} $change The change and who set it.
	 *
	 * @return array The status to store.
	 *
	 * @throws \DomainException When the state is not one the page knows.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function setState(array $change): array {
		$statusChange = new StatusChange();
		$statusChange->component = $change['component'];
		$statusChange->state = $change['state'];
		$statusChange->message = $change['message'];
		$statusChange->setBy = $change['setBy'];

		return $this->statusService->setState(change: $statusChange);

	}//end setState()

	/**
	 * A catalogue's activity as an Atom document.
	 *
	 * @param array{records: array, notices: array, rules: array, catalogTitle: string, selfUrl: string} $feed The feed sources.
	 *
	 * @return string The Atom document.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function atom(array $feed): string {
		$entries = $this->feedService->entries(records: $feed['records'], notices: $feed['notices'], rules: $feed['rules']);
		return $this->feedService->toAtom(
			catalogTitle: $feed['catalogTitle'],
			selfUrl: $feed['selfUrl'],
			entries: $entries
		);

	}//end atom()

	/**
	 * Render markup the way the app renders it.
	 *
	 * @param string $markup The markup.
	 *
	 * @return array The rendered result.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
	 */
	public function renderMarkup(string $markup): array {
		return $this->markupService->render(markup: $markup);
	}//end renderMarkup()
}//end class
