<?php

/**
 * Contract of PublicationService, for callers in other components.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Contract of PublicationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationServiceInterface {
	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService;

	/**
	 * Get register and schema combinations from catalogs.
	 *
	 * This method retrieves all catalogs (or a specific one if ID is provided),
	 * extracts their registers and schemas, and stores them as general variables.
	 *
	 * When the catalog register/schema pair is unconfigured this returns empty
	 * arrays without querying — see resolveCatalogScope() for why that refusal
	 * is stated here rather than left to the mapper.
	 *
	 * @param string|integer|null $catalogId Optional ID of a specific catalog to filter by
	 *
	 * @return array<string, array<string>> Array containing available registers and schemas
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function getCatalogFilters(null|string|int $catalogId = null): array;

	/**
	 * Get the list of available registers.
	 *
	 * @return array<string> List of available registers
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function getAvailableRegisters(): array;

	/**
	 * Get the list of available schemas.
	 *
	 * @return array<string> List of available schemas
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function getAvailableSchemas(): array;

	/**
	 * Retrieves a list of all objects for a specific register and schema
	 *
	 * This method returns a paginated list of objects that match the specified register and schema.
	 * It supports filtering, sorting, and pagination through query parameters using the new search structure.
	 *
	 * @param null|string|integer $catalogId Optional catalog ID to filter objects by
	 * @param array|null $customParams Optional custom parameters to use instead of request params
	 *
	 * @return JSONResponse A JSON response containing the list of objects
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function index(null|string|int $catalogId = null, ?array $customParams = null): JSONResponse;

	/**
	 * Shows a specific object from a register and schema
	 *
	 * Retrieves and returns a single object from the specified register and schema,
	 * with support for field filtering and related object extension.
	 *
	 * @param string $id The object ID
	 *
	 * @return JSONResponse A JSON response containing the object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function show(string $id): JSONResponse;

	/**
	 * Shows attachments of a publication
	 *
	 * Retrieves and returns attachments of a publication using code from OpenRegister.
	 *
	 * @param string $id The object ID
	 *
	 * @return JSONResponse A JSON response containing attachments
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function attachments(string $id): JSONResponse;

	/**
	 * Download all files of an object as a ZIP archive
	 *
	 * This method creates a ZIP file containing all files associated with a specific object
	 * and returns it as a downloadable file. The ZIP file includes all files stored in the
	 * object's folder with their original names.
	 *
	 * @param string $id The identifier of the object to download files for
	 *
	 * @return DataDownloadResponse|JSONResponse ZIP file download response or error response
	 *
	 * @throws ContainerExceptionInterface If there's an issue with dependency injection
	 * @throws NotFoundExceptionInterface If the FileService dependency is not found
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function download(
		string $id,
	): DataDownloadResponse|JSONResponse;

	/**
	 * Retrieves all objects that this publication references
	 *
	 * This method returns all objects that this publication
	 * uses/references. A -> B means that A (This publication)
	 * references B (Another object).
	 *
	 * @param string $id The ID of the publication to retrieve
	 *                   relations for
	 *
	 * @return JSONResponse A JSON response containing the related objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function uses(string $id): JSONResponse;

	/**
	 * Retrieves all objects that use this publication
	 *
	 * This method returns all objects that reference (use) this
	 * publication. B -> A means that B (Another object) references
	 * A (This publication).
	 *
	 * @param string $id The ID of the publication to retrieve
	 *                   uses for
	 *
	 * @return JSONResponse A JSON response containing the referenced objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function used(string $id): JSONResponse;

	/**
	 * Get aggregated publications from local and federated sources
	 *
	 * This method handles both local and aggregated search results when the _aggregate
	 * parameter is not set to false. It supports faceting when _facetable parameter is provided.
	 *
	 * PERFORMANCE OPTIMIZATIONS:
	 * - Fast path for single catalog scenarios (no federation overhead)
	 * - Optional catalog information via _include_catalogs parameter
	 * - Cached expensive operations to reduce database queries
	 * - Optimized timeouts for better responsiveness
	 *
	 * AGGREGATION FEATURES:
	 * - Proper pagination: Collects sufficient data from all sources, merges and deduplicates,
	 *   then applies pagination to ensure consistent totals and page counts
	 * - Ordering: Supports _order parameters like _order[@self.created]=DESC to sort the
	 *   combined dataset from all sources according to specified criteria
	 * - Deduplication: Removes duplicate entries based on object ID across all sources
	 * - Faceting: Merges facet data from multiple sources when _facetable=true
	 *
	 * @param array $queryParams Query parameters for filtering, pagination, ordering, etc.
	 * @param array $requestParams Original request parameters for building pagination links
	 * @param string $baseUrl Base URL for building pagination links
	 *
	 * @return array Response data containing publications, pagination info, and optionally facets
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getAggregatedPublications(array $queryParams = [], array $requestParams = [], string $baseUrl = ''): array;

	/**
	 * Get a single publication with optional federation support
	 *
	 * This method searches for a publication in the local catalog first,
	 * and optionally in federated catalogs if _aggregate parameter is not
	 * set to false and the publication is not found locally.
	 *
	 * @param string $id The ID of the publication to retrieve
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the publication or error information
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedPublication(string $id, array $queryParams = []): array;

	/**
	 * Get publications that use this publication with federation support
	 *
	 * This method returns all objects that reference (use) this
	 * publication. B -> A means that B (Another object) references
	 * A (This publication).
	 * When aggregation is enabled, it also searches federated
	 * catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve uses for
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the referenced objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedUsed(string $id, array $queryParams = []): array;

	/**
	 * Get publications that this publication uses with federation support
	 *
	 * This method returns all objects that this publication
	 * uses/references. A -> B means that A (This publication)
	 * references B (Another object).
	 * When aggregation is enabled, it also searches federated
	 * catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve relations for
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the related objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedUses(string $id, array $queryParams = []): array;
}//end interface
