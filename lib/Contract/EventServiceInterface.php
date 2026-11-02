<?php

/**
 * Contract of EventService, for callers in other components.
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

use RuntimeException;

/**
 * Contract of EventService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface EventServiceInterface {
	/**
	 * Attempts to retrieve the OpenRegister object service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService;

	/**
	 * Get the FileMapper from the container.
	 *
	 * This method provides access to the FileMapper for direct database access
	 * to file information without triggering object updates.
	 *
	 * @return \OCA\OpenRegister\Db\FileMapper The FileMapper instance.
	 * @throws \RuntimeException If the FileMapper is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       FileMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getFileMapper(): ?\OCA\OpenRegister\Db\FileMapper;

	/**
	 * Handle object creation events with auto-publishing logic.
	 *
	 * This method processes newly created objects and applies auto-publishing
	 * rules based on the configuration settings.
	 *
	 * @param array $objects Array of created object data.
	 *
	 * @return array Results of the event processing including any auto-publishing actions.
	 * @throws \RuntimeException If event processing fails.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	public function handleObjectCreateEvents(array $objects): array;

	/**
	 * Handle object update events with auto-publishing logic.
	 *
	 * This method processes updated objects and applies auto-publishing
	 * rules based on the configuration settings and object changes.
	 *
	 * @param array $objects Array of updated object data.
	 *
	 * @return array Results of the event processing including any auto-publishing actions.
	 * @throws \RuntimeException If event processing fails.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	public function handleObjectUpdateEvents(array $objects): array;
}//end interface
