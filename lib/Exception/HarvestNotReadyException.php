<?php

/**
 * A publiccode harvest refused an action because a precondition is not met.
 *
 * Carries a stable slug next to the message, so a controller can answer with a
 * static localised message and a machine-readable `error` instead of the
 * exception text (ADR-050, ADR-105). It extends RuntimeException so a caller
 * that already catches that keeps working.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
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
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use RuntimeException;

/**
 * Refusal of a harvest action, with the reason as a slug.
 *
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */
class HarvestNotReadyException extends RuntimeException {

	/**
	 * Integriq is not installed or not enabled.
	 *
	 * @var string
	 */
	public const NEEDS_INTEGRIQ = 'needs-integriq';

	/**
	 * OpenRegister is not installed.
	 *
	 * @var string
	 */
	public const NEEDS_OPENREGISTER = 'needs-openregister';

	/**
	 * The shipped flow is not in the flow store.
	 *
	 * @var string
	 */
	public const FLOW_NOT_IMPORTED = 'flow-not-imported';

	/**
	 * Not every synchronization the flow names exists.
	 *
	 * @var string
	 */
	public const NOT_SET_UP = 'not-set-up';

	/**
	 * The flow is switched off.
	 *
	 * @var string
	 */
	public const SWITCHED_OFF = 'switched-off';

	/**
	 * Constructor.
	 *
	 * @param string $message The reason in words, for the log.
	 * @param string $error The reason as one of the slugs above.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function __construct(string $message, private readonly string $error) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The reason as a slug.
	 *
	 * @return string One of the class constants.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function getError(): string {
		return $this->error;

	}//end getError()
}//end class
