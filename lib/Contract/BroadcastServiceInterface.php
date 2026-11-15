<?php

/**
 * Contract of BroadcastService, for callers in other components.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Contract of BroadcastService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface BroadcastServiceInterface {
	/**
	 * Broadcast this OpenCatalogi directory to one or more instances.
	 *
	 * This method handles broadcasting the current directory information to external
	 * OpenCatalogi instances. It can broadcast to a specific URL or to all known directories.
	 *
	 * @param string|null $url Optional URL of a specific instance to broadcast to.
	 *                         If null, broadcasts to all known directories.
	 *
	 * @return array<string, bool> Associative array of URLs and their broadcast success status
	 *
	 * @throws DoesNotExistException When required data is not found
	 * @throws MultipleObjectsReturnedException When duplicate objects are found
	 * @throws ContainerExceptionInterface When container access fails
	 * @throws NotFoundExceptionInterface When service is not found in container
	 *
	 * The outcome is also reported to integriq's connection registry, at most
	 * once an hour while it stays the same (adopt-connection-registry). The
	 * report never changes the result.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function broadcast(?string $url = null): array;
}//end interface
