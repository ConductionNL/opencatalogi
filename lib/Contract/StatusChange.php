<?php

/**
 * A status page state an administrator or an integration sets.
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
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The component, its new state, what a reader should do about it, and who set it.
 *
 * Unvalidated: StatusPageServiceInterface::setState() refuses what is not a state.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */
final class StatusChange {

	/**
	 * The component a reader recognises.
	 *
	 * @var string
	 */
	public string $component = '';

	/**
	 * The state.
	 *
	 * @var string
	 */
	public string $state = '';

	/**
	 * What a reader should do about it.
	 *
	 * @var string
	 */
	public string $message = '';

	/**
	 * Who set it.
	 *
	 * @var string
	 */
	public string $setBy = '';
}//end class
