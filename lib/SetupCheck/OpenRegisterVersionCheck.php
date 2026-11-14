<?php

/**
 * OpenCatalogi setup check: OpenRegister carries the AppHost engine.
 *
 * Shown to administrators under Administration settings > Overview. When the
 * installed OpenRegister is older than 2.0, OpenCatalogi's pages answer with a
 * notice instead of the app, and this check says why and what to do.
 *
 * @category SetupCheck
 * @package  OCA\OpenCatalogi\SetupCheck
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
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\SetupCheck;

use OCA\OpenCatalogi\AppInfo\OpenRegisterCompatibility;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Reports an OpenRegister too old for OpenCatalogi to an administrator.
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */
class OpenRegisterVersionCheck implements ISetupCheck {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterCompatibility $compatibility The AppHost probe.
	 * @param IL10N                     $l             Translations.
	 */
	public function __construct(
		private readonly OpenRegisterCompatibility $compatibility,
		private readonly IL10N $l,
	) {

	}//end __construct()

	/**
	 * The check's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function getName(): string {
		return $this->l->t('OpenCatalogi: OpenRegister version');

	}//end getName()

	/**
	 * The section the check is listed under.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function getCategory(): string {
		return 'system';

	}//end getCategory()

	/**
	 * Run the check.
	 *
	 * @return SetupResult Success when the AppHost engine is installed, an error otherwise.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function run(): SetupResult {
		if ($this->compatibility->appHostAvailable() === true) {
			return SetupResult::success($this->l->t('OpenRegister carries the engine OpenCatalogi needs.'));
		}

		return SetupResult::error(
			$this->l->t(
				'OpenCatalogi requires OpenRegister %1$s or newer. The installed OpenRegister lacks %2$s, '
					. 'so OpenCatalogi shows a notice instead of its pages. Update OpenRegister.',
				[OpenRegisterCompatibility::REQUIRED_VERSION, $this->compatibility->markerClass()]
			)
		);

	}//end run()
}//end class
