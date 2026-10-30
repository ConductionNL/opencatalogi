<?php

/**
 * One run of the catalogue scope backfill.
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
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;

/**
 * The register and schema settings one backfill run of
 * {@see SettingsService::backfillCatalogScopes()} works with, the OpenRegister
 * object service it saves through, and the slugs it leaves pending.
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
 */
final class CatalogScopeBackfill {

	/**
	 * The publication register id an empty register scope gets.
	 *
	 * @var string
	 */
	public readonly string $publicationRegister;

	/**
	 * The publication schema id an empty schema scope gets.
	 *
	 * @var string
	 */
	public readonly string $publicationSchema;

	/**
	 * The register the catalogues live in.
	 *
	 * @var string
	 */
	public readonly string $catalogRegister;

	/**
	 * The schema of the catalogues.
	 *
	 * @var string
	 */
	public readonly string $catalogSchema;

	/**
	 * The OpenRegister object service, once OpenRegister is known to be installed.
	 *
	 * @var mixed
	 */
	public mixed $objectService = null;

	/**
	 * The register and schema slugs a later register event can still resolve.
	 *
	 * @var list<string>
	 */
	public array $pending = [];

	/**
	 * Read the register and schema settings.
	 *
	 * @param IAppConfig $config The app config.
	 * @param string $appName The app the settings belong to.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function __construct(IAppConfig $config, string $appName) {
		$this->publicationRegister = $config->getValueString($appName, 'publication_register', '');
		$this->publicationSchema = $config->getValueString($appName, 'publication_schema', '');
		$this->catalogRegister = $config->getValueString($appName, 'catalog_register', '');
		$this->catalogSchema = $config->getValueString($appName, 'catalog_schema', '');
	}//end __construct()

	/**
	 * Whether all four settings are set, so there is something to backfill against.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function isConfigured(): bool {
		return $this->publicationRegister !== '' && $this->publicationSchema !== ''
			&& $this->catalogRegister !== '' && $this->catalogSchema !== '';
	}//end isConfigured()

	/**
	 * The search for every catalogue, published or not.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function catalogQuery(): array {
		return [
			'@self' => [
				'register' => $this->catalogRegister,
				'schema' => $this->catalogSchema,
			],
		];
	}//end catalogQuery()
}//end class
