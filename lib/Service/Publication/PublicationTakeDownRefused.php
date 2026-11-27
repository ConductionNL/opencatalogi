<?php

/**
 * Why a withdrawal stopped, and in which state the publication was.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use RuntimeException;
use Throwable;

/**
 * Thrown by PublicationTakeDown with one of three reasons.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
final class PublicationTakeDownRefused extends RuntimeException {

	/**
	 * The publication is not public or scheduled.
	 *
	 * @var string
	 */
	public const WRONG_STATE = 'wrong-state';

	/**
	 * OpenRegister refused the withdrawn record; the previous exception says why.
	 *
	 * @var string
	 */
	public const NOT_SAVED = 'not-saved';

	/**
	 * It is down, but the record of why could not be stored.
	 *
	 * @var string
	 */
	public const UNSTORED = 'depublication-unstored';

	/**
	 * Constructor.
	 *
	 * @param string         $reason   WRONG_STATE, NOT_SAVED or UNSTORED.
	 * @param string         $state    The state the publication was in, or is in now for UNSTORED.
	 * @param Throwable|null $previous What OpenRegister threw, if anything.
	 *
	 * @spec exclude exception constructor.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly string $state,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, previous: $previous);
	}//end __construct()
}//end class
