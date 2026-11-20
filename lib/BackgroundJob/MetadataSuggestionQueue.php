<?php

/**
 * Queues the metadata suggestions of a created publication.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Contract\MetadataSuggestionQueueInterface;
use OCP\BackgroundJob\IJobList;

/**
 * Adds a SuggestPublicationMetadata job, so a save never waits on the suggestions.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */
class MetadataSuggestionQueue implements MetadataSuggestionQueueInterface {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {

	}//end __construct()

	/**
	 * Queue the suggestions for one created publication.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 * @param string $id       The publication's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function queue(string $register, string $schema, string $id): void {
		$this->jobList->add(
			SuggestPublicationMetadata::class,
			['register' => $register, 'schema' => $schema, 'id' => $id]
		);

	}//end queue()
}//end class
