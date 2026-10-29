<?php

/**
 * Registers the service bindings of OpenCatalogi.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the service bindings of OpenCatalogi.
 *
 * Services that autowire need no entry here. This class holds the bindings the
 * container cannot infer: interfaces bound to their implementation, and
 * services that take a scalar constructor argument.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 */
class ServiceRegistrar {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id, passed to services that are scoped to it.
	 */
	public function __construct(
		private readonly string $appName,
	) {

	}//end __construct()

	/**
	 * Register the service bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds existing services, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		// The external case type catalogue is read through integriq's gateway;
		// this app composes the ask and reads the answer, and holds no
		// transport. The reader raises rather than answering an empty list when
		// there is no gateway to ask with.
		$context->registerServiceAlias(
			'OCA\\OpenCatalogi\\Service\\Catalogue\\CaseTypeSourceReader',
			GatewayCaseTypeSourceReader::class
		);

		// The published-collection configuration lives in this app's config, so
		// adding a collection takes effect on a running instance without a
		// release.
		$appName = $this->appName;
		$context->registerService(
			PublishedCollectionsService::class,
			static function ($c) use ($appName) {
				return new PublishedCollectionsService(
					config: $c->get(\OCP\IAppConfig::class),
					ruleService: $c->get(PublicationRuleService::class),
					appName: $appName
				);
			}
		);

	}//end register()
}//end class
