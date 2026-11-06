<?php

/**
 * OpenCatalogi cross-origin rule of the public list controllers.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

/**
 * Resolve the `Access-Control-Allow-Origin` value for the list controllers,
 * through ResolvesAllowedOrigin.
 *
 * Consuming controllers MUST expose a `private readonly IAppConfig $config`
 * property, and inherit `$appName` and `$request` from `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait AnswersCrossOriginListRequests {
	use ResolvesAllowedOrigin;

	/**
	 * The `cors_allowed_origins` setting.
	 *
	 * @return string The setting.
	 */
	private function allowedOriginsSetting(): string {
		return $this->config->getValueString($this->appName, 'cors_allowed_origins', '*');
	}//end allowedOriginsSetting()
}//end trait
