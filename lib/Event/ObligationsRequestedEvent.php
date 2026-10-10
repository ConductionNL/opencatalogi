<?php

/**
 * OpenCatalogi Obligations Requested Event.
 *
 * The cross-app contract the obligation overview reads through. OpenCatalogi
 * dispatches one event per registered source, carrying that source's app id.
 * The listener in the source app answers with the publication obligations it
 * holds. A source without a listener leaves the event unanswered, and the
 * overview names that source as unread rather than counting it as zero.
 *
 * The contract, for a listener in another app: `getAppId(): string` names the
 * source being asked; `setObligations(array $obligations): void` answers with
 * a list of rows carrying `title`, `recordReference`, and either `publishedAt`
 * or `dueDate` (ISO 8601); a row that is not an array is dropped.
 *
 * @category Event
 * @package  OCA\OpenCatalogi\Event
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
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks one source app for its publication obligations.
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */
class ObligationsRequestedEvent extends Event {

	/**
	 * The obligations the source answered with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $obligations = [];

	/**
	 * Whether a listener answered.
	 *
	 * @var boolean
	 */
	private bool $answered = false;

	/**
	 * Constructor.
	 *
	 * @param string $appId The source app being asked.
	 */
	public function __construct(
		private readonly string $appId,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The source app being asked.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function getAppId(): string {
		return $this->appId;

	}//end getAppId()

	/**
	 * Answer with the source's obligations. An empty list is an answer: the
	 * source holds nothing that must be published.
	 *
	 * @param array<int, mixed> $obligations The obligations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function setObligations(array $obligations): void {
		$this->obligations = array_values(array_filter($obligations, 'is_array'));
		$this->answered = true;

	}//end setObligations()

	/**
	 * The obligations the source answered with.
	 *
	 * @return array<int, array<string, mixed>> The obligations.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function getObligations(): array {
		return $this->obligations;

	}//end getObligations()

	/**
	 * Whether a listener answered.
	 *
	 * @return boolean True once a listener set obligations.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function isAnswered(): bool {
		return $this->answered;

	}//end isAnswered()
}//end class
