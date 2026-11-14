<?php

/**
 * OpenCatalogi OpenRegister compatibility probe.
 *
 * Whether the installed OpenRegister carries the AppHost engine (ADR-040) that
 * OpenCatalogi's dashboard, preferences, health and metrics controllers are
 * bound to. Nextcloud's info.xml cannot declare a minimum version of another
 * app, so this is checked at runtime.
 *
 * The probe is NEVER called from Application::register(): at register time
 * the OCA\OpenRegister\ prefix is not on the autoloader yet (`opencatalogi`
 * sorts before `openregister`), so a class check there answers false on a
 * healthy instance. It is called lazily, when a bound controller is built for
 * a request, and from boot() and the setup check, which run after every app
 * registered.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
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

namespace OCA\OpenCatalogi\AppInfo;

/**
 * Answers whether OpenRegister's AppHost engine is installed.
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
 */
class OpenRegisterCompatibility {

	/**
	 * The class whose presence means OpenRegister carries the AppHost engine.
	 *
	 * @var string
	 */
	public const MARKER_CLASS = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController';

	/**
	 * The OpenRegister version that ships the AppHost engine.
	 *
	 * @var string
	 */
	public const REQUIRED_VERSION = '2.0';

	/**
	 * Constructor.
	 *
	 * @param string $markerClass The class to look for; tests pass another one.
	 */
	public function __construct(
		private readonly string $markerClass=self::MARKER_CLASS,
	) {

	}//end __construct()

	/**
	 * Whether OpenRegister's AppHost engine is installed.
	 *
	 * @return bool True when the marker class exists.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function appHostAvailable(): bool {
		return class_exists($this->markerClass);

	}//end appHostAvailable()

	/**
	 * The class this probe looks for, for messages.
	 *
	 * @return string The fully qualified class name.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-openregister-apphost-compatibility-guard-onb-009
	 */
	public function markerClass(): string {
		return $this->markerClass;

	}//end markerClass()
}//end class
