<?php

/**
 * Reads the latest run of one flow.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenRegister\Db\FlowRunFilter;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunViewer;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The latest run of a flow the app ships, read as a system read: no
 * requester, so no per-user scoping. The run mapper is resolved from the
 * container on use (ADR-083).
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
final class LatestFlowRun {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's flow run mapper.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The latest run of a flow, or null when it never ran or the runs cannot be read.
	 *
	 * @param string $flowUuid The flow's uuid.
	 *
	 * @return object|null The FlowRun entity.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function find(string $flowUuid): ?object {
		try {
			$runs = $this->container->get(FlowRunMapper::class)->findAllRuns(
				listing: new FlowRunListing(filter: new FlowRunFilter(flowId: $flowUuid), limit: 1),
				viewer: new FlowRunViewer(requesterUid: null, ownedFlowIds: [])
			);
		} catch (Throwable) {
			return null;
		}

		return ($runs[0] ?? null);
	}//end find()
}//end class
