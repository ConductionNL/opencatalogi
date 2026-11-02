<?php

/**
 * The profile-version check and the optional quality scorer of a DCAT feed.
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
 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Checks a DCAT feed against the published DCAT-AP-NL profile version, and
 * scores its datasets for DQV exposure when a quality scorer is available.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
 */
class DcatAssurance {

	/**
	 * Constructor.
	 *
	 * @param StandardsVersionService $standardsVersions Declared DCAT-AP-NL profile version, and the published one.
	 * @param QualityService|null     $qualityService    Optional MQA/FAIR scorer for DQV exposure (PQM-002).
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly StandardsVersionService $standardsVersions,
		private readonly ?QualityService $qualityService = null,
	) {

	}//end __construct()

	/**
	 * The profile-version violation, when the publisher has moved past the version we target.
	 *
	 * A feed that declares a superseded profile is a feed whose mandatory-property
	 * checklist is the wrong checklist, so it is reported as a violation rather than
	 * left to a reader to notice. An unreadable release index yields no violation but
	 * is still reported by the controller as `unknown`, never as current.
	 *
	 * @return array<int, array<string, mixed>> One entry when behind, empty otherwise.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function profileVersionViolations(): array {
		$profile = $this->standardsVersions->dcatApNlVersion();
		if ($profile['status'] !== StandardsVersionService::STATUS_BEHIND) {
			return [];
		}

		return [
			[
				'axis' => 'profile-version',
				'declared' => $profile['declared'],
				'published' => $profile['published'],
			],
		];

	}//end profileVersionViolations()

	/**
	 * The dataset node with its DQV quality measurements added (PQM-002).
	 *
	 * @param array $dataset The dataset node.
	 *
	 * @return array The node, unchanged when no quality scorer is available.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	public function withQualityMeasurements(array $dataset): array {
		if ($this->qualityService !== null) {
			$dataset['dqv:hasQualityMeasurement'] = $this->qualityService->dqvMeasurements($this->qualityService->scoreDataset($dataset));
		}

		return $dataset;

	}//end withQualityMeasurements()
}//end class
