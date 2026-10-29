<?php

/**
 * Contract of StatusPageService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use DateTimeInterface;
use DomainException;

/**
 * Contract of StatusPageService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface StatusPageServiceInterface {
	/**
	 * How long a state stays current, in hours, when nothing else is configured.
	 *
	 * @var integer
	 */
	public const DEFAULT_STALENESS_HOURS = 24;

	/**
	 * Set a component's state.
	 *
	 * The setter is a person or an integration, and it is recorded. There is no
	 * path in this class that derives a state from anything, which is what
	 * makes "the page probes nothing" true rather than merely intended.
	 *
	 * @param string $component The component a reader recognises.
	 * @param string $state The state.
	 * @param string $message What a reader should do about it.
	 * @param string $setBy Who set it.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The status to save.
	 *
	 * @throws DomainException When the state is not one this page knows.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function setState(
		string $component,
		string $state,
		string $message,
		string $setBy,
		?DateTimeInterface $now = null,
	): array;

	/**
	 * The page as an anonymous reader sees it.
	 *
	 * @param array<int, array<string, mixed>> $components The stored statuses.
	 * @param integer $stalenessHours How long a state stays current.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{components: array<int, array<string, mixed>>, stale: integer, probed: boolean}
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function render(
		array $components,
		int $stalenessHours = self::DEFAULT_STALENESS_HOURS,
		?DateTimeInterface $now = null,
	): array;
}//end interface
