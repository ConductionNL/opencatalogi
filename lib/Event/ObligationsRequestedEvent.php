<?php

/**
 * OpenCatalogi Obligations Requested Event.
 *
 * Asks one registered source app what it must still publish. The app that
 * owns the source answers by setting the obligations; nobody answering is
 * itself an answer, and the overview shows that source as unread.
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
 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Event;

use OCP\EventDispatcher\Event;

/**
 * One source's obligations, set by the listener of the app that owns it.
 *
 * An obligation is an array with `title`, `dueDate` (ISO 8601, or empty when
 * unknown), `publishedAt` (empty while unpublished), `reference` and,
 * optionally, `category` and `url`.
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */
class ObligationsRequestedEvent extends Event {

	/**
	 * What the source answered, or null while nobody answered.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $obligations = null;

	/**
	 * Constructor.
	 *
	 * @param string $appId The app id of the source being asked.
	 */
	public function __construct(
		private readonly string $appId,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The app id of the source being asked.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function getAppId(): string {
		return $this->appId;

	}//end getAppId()

	/**
	 * Answer for the source.
	 *
	 * @param array<int, array<string, mixed>> $obligations The source's obligations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function setObligations(array $obligations): void {
		$this->obligations = array_values($obligations);

	}//end setObligations()

	/**
	 * What the source answered.
	 *
	 * @return array<int, array<string, mixed>>|null The obligations, or null when nobody answered.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function getObligations(): ?array {
		return $this->obligations;

	}//end getObligations()
}//end class
