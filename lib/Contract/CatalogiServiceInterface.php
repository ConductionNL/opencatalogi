<?php

/**
 * Contract of CatalogiService, for callers in other components.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Contract of CatalogiService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CatalogiServiceInterface {
	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       service from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService;

	/**
	 * Attempts to retrieve the SchemaMapper from the Container
	 *
	 * @return \OCA\OpenRegister\Db\SchemaMapper|null
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       SchemaMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getSchemaMapper(): ?\OCA\OpenRegister\Db\SchemaMapper;

	/**
	 * Attempts to retrieve the RegisterMapper from the Container
	 *
	 * @return \OCA\OpenRegister\Db\RegisterMapper|null
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       RegisterMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getRegisterMapper(): ?\OCA\OpenRegister\Db\RegisterMapper;

	/**
	 * Compute rewritten register and schema arrays for a catalog object.
	 *
	 * Resolves slug-or-id values in `registers` and `schemas` to integer ids. Returns
	 * only the keys that actually changed, so callers can pass the result straight
	 * into a pre-save hook's `setModifiedData(...)` without overwriting unrelated fields.
	 *
	 * @param array<string, mixed> $object The decoded catalog object payload.
	 *
	 * @return array<string, array<int|string>> Map containing only the changed keys
	 *                                          (`registers` and/or `schemas`). Empty
	 *                                          when nothing needs rewriting.
	 *
	 * @throws \RuntimeException When a slug cannot be resolved to a register/schema.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function computeRewrittenRegistersAndSchemas(array $object): array;

	/**
	 * Rewrite slugs and uuids in register and schema fields of a Catalog to actual ids.
	 *
	 * @param ObjectEntity $objectEntity The catalog object to rewrite.
	 *
	 * @return bool Whether the object has been updated.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @deprecated Calling this from a post-save event handler causes an infinite loop
	 *             because the inner `saveObject(...)` re-emits `ObjectUpdatedEvent`. Subscribe
	 *             to the pre-save events (`ObjectCreatingEvent` / `ObjectUpdatingEvent`) and use
	 *             {@see self::computeRewrittenRegistersAndSchemas()} together with
	 *             `$event->setModifiedData(...)` instead.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function rewriteSchemasAndRegisters(ObjectEntity $objectEntity): bool;

	/**
	 * Get register and schema combinations from catalogs.
	 *
	 * This method retrieves all catalogs (or a specific one if ID is provided),
	 * extracts their registers and schemas, and stores them as general variables.
	 *
	 * @param string|integer|null $catalogId Optional ID of a specific catalog to filter by
	 *
	 * @return array<string, array<string>> Array containing available registers and schemas
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function getCatalogFilters(null|string|int $catalogId = null): array;

	/**
	 * Get the list of available registers.
	 *
	 * @return array<string> List of available registers
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function getAvailableRegisters(): array;

	/**
	 * Get the list of available schemas.
	 *
	 * @return array<string> List of available schemas
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function getAvailableSchemas(): array;

	/**
	 * Get a catalog by its slug from cache or database.
	 *
	 * This method first attempts to retrieve the catalog from cache. If not found in cache,
	 * it queries the database and stores the result in cache for future requests.
	 *
	 * @param string $slug The slug of the catalog to retrieve
	 *
	 * @return array|null The catalog data as an array, or null if not found
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function getCatalogBySlug(string $slug): ?array;

	/**
	 * Invalidate the cache for a specific catalog.
	 *
	 * This method removes the catalog from cache, forcing the next request
	 * to fetch fresh data from the database.
	 *
	 * @param string $slug The slug of the catalog to invalidate
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function invalidateCatalogCache(string $slug): void;

	/**
	 * Invalidate cache for a catalog by its ID.
	 *
	 * This method retrieves the catalog by ID to get its slug, then invalidates the cache.
	 *
	 * @param integer|string $catalogId The ID of the catalog
	 *
	 * @return void
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function invalidateCatalogCacheById(int|string $catalogId): void;

	/**
	 * Warm up the cache for a specific catalog.
	 *
	 * This method pre-loads the catalog into cache to improve performance
	 * for subsequent requests.
	 *
	 * @param string $slug The slug of the catalog to warm up
	 *
	 * @return void
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function warmupCatalogCache(string $slug): void;

	/**
	 * Warm up cache for a catalog by its ID.
	 *
	 * @param integer|string $catalogId The ID of the catalog
	 *
	 * @return void
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function warmupCatalogCacheById(int|string $catalogId): void;

	/**
	 * Retrieves a list of all objects for a specific register and schema.
	 *
	 * This method returns a paginated list of objects that match the specified register and schema.
	 * It supports filtering, sorting, and pagination through query parameters.
	 *
	 * @param string|integer|null $catalogId Optional catalog ID to filter by
	 *
	 * @return JSONResponse A JSON response containing the list of objects
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
	 */
	public function index(null|string|int $catalogId = null): JSONResponse;
}//end interface
