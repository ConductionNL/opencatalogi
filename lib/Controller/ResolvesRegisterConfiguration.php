<?php

/**
 * OpenCatalogi Register-configuration resolver trait.
 *
 * Shared helper that lets controllers resolve their `<context>_register` and
 * `<context>_schema` app-config keys through OpenRegister's
 * RegisterResolverService instead of reading them directly with
 * IAppConfig::getValueString(..., '') — the empty-string fallback that
 * silently hides a misconfigured register/schema (audit
 * .claude/audit-2026-05-03/04-hardcoded.md, Stream 4). The resolver throws
 * MissingConfigException when a context is unconfigured so the controller can
 * surface an operator-actionable 503 instead of returning an empty list that
 * looks like "no data".
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Resolve `<context>_register` / `<context>_schema` config via OpenRegister.
 *
 * Consuming controllers MUST expose a `$registers` property that answers
 * registerConfiguration() (OpenRegisterLookupInterface or RegisterAccess).
 */
trait ResolvesRegisterConfiguration {
	/**
	 * Resolve the register + schema identifiers for a configuration context.
	 *
	 * There is NO empty-string fallback: an unconfigured context raises
	 * MissingConfigException, which the caller surfaces as a 503.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey The `<context>_schema` config key.
	 *
	 * @return array<string, string> Map with 'register' and 'schema' identifiers (slug or UUID).
	 *
	 * @throws \RuntimeException When OpenRegister's resolver and the app config are unavailable.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function resolveRegisterConfiguration(string $registerKey, string $schemaKey): array {
		return $this->registers->registerConfiguration(registerKey: $registerKey, schemaKey: $schemaKey);

	}//end resolveRegisterConfiguration()

	/**
	 * Build the operator-actionable 503 response for an unresolved register context.
	 *
	 * Returned by every controller list/detail handler that catches the
	 * MissingConfigException (or the OpenRegister-unavailable RuntimeException)
	 * raised by resolveRegisterConfiguration(). The detail names the missing
	 * config key so an admin can fix it in OpenCatalogi settings — replacing the
	 * previous behaviour where a misconfigured register silently returned an
	 * empty result set.
	 *
	 * @param \Throwable $e The resolver failure.
	 *
	 * @return JSONResponse A 503 Service Unavailable with operator-actionable detail.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function registerConfigErrorResponse(\Throwable $e): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'register_not_configured',
				'detail' => $e->getMessage(),
			],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end registerConfigErrorResponse()
}//end trait
