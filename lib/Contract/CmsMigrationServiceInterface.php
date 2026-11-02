<?php

/**
 * Contract of CmsMigrationService, for callers in other components.
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

/**
 * Contract of CmsMigrationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CmsMigrationServiceInterface {
	/**
	 * The register this app's CMS content lives in.
	 */
	public const SOURCE_REGISTER = 'publication';

	/**
	 * The register Portaliq's content lives in.
	 */
	public const TARGET_REGISTER = 'portaliq';

	/**
	 * The Portaliq route an OpenCatalogi slug becomes.
	 *
	 * Portaliq serves a page at an in-portal path with a leading slash;
	 * OpenCatalogi stored a bare slug. An empty slug becomes the portal root,
	 * which is what a page with no slug was already acting as.
	 *
	 * @param mixed $slug The stored slug.
	 *
	 * @return string The route.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function routeFor(mixed $slug): string;

	/**
	 * Turn a page's content blocks into a Portaliq widget grid.
	 *
	 * Blocks stack down a 12-column grid in the order they were authored, which
	 * is how they rendered before.
	 *
	 * @param array<int, mixed> $blocks The stored content blocks.
	 * @param string            $pageId A stable prefix for widget ids.
	 *
	 * @return array{body: array<string, mixed>, unmapped: array<int, string>, dropped: array<int, string>}
	 *         The body, plus any block type with no mapping and any prop the
	 *         target widget does not declare.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	public function bodyFor(array $blocks, string $pageId): array;

	/**
	 * The Portaliq page one OpenCatalogi page becomes.
	 *
	 * @param array<string, mixed> $page   The source page's fields.
	 * @param string               $portal The portal slug it belongs to.
	 *
	 * @return array{page: array<string, mixed>, unmapped: array<int, string>, dropped: array<int, string>}
	 *         The page to write, and what could not be carried.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function pageFor(array $page, string $portal): array;

	/**
	 * The Portaliq menu one OpenCatalogi menu becomes.
	 *
	 * `groups`, `hideBeforeLogin` and `icon` have no counterpart and are
	 * reported by the caller rather than carried.
	 *
	 * @param array<string, mixed> $menu   The source menu's fields.
	 * @param string               $portal The portal slug it belongs to.
	 *
	 * @return array{menu: array<string, mixed>, dropped: array<int, string>} The menu, and what is lost.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-menu-becomes-a-portal-menu-req-cms-103
	 */
	public function menuFor(array $menu, string $portal): array;

	/**
	 * Resolve an OpenRegister collaborator, or null when it is unavailable.
	 *
	 * @param string $id The service identifier.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function openRegister(string $id): ?object;

	/**
	 * Flatten an object row into a field map with its uuid resolved.
	 *
	 * @param mixed $row The row as the reader returned it.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function toFields(mixed $row): array;
}//end interface
