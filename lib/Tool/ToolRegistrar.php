<?php

/**
 * Registers the interface bindings of OpenCatalogi.
 *
 * @category Tool
 * @package  OCA\OpenCatalogi\Tool
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

namespace OCA\OpenCatalogi\Tool;

use OCA\OpenCatalogi\Contract\CMSToolInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the interface bindings of OpenCatalogi.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 */
class ToolRegistrar {

	/**
	 * Register the interface bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds existing classes, no behaviour of their own.
	 */
	public function register(IRegistrationContext $context): void {
		// Callers in other components depend on these interfaces.
		$context->registerServiceAlias(CMSToolInterface::class, CMSTool::class);
	}//end register()
}//end class
