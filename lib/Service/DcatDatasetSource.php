<?php

/**
 * One publication on its way to a DCAT dataset node.
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
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * The publication, its dataset IRI and its published attachments, plus the
 * controlled-vocabulary violations found while mapping it.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
 */
final class DcatDatasetSource {

	/**
	 * Controlled-vocabulary violations found while mapping this publication.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $violations = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $publication The publication object (jsonSerialize shape).
	 * @param string $datasetIri The canonical public dataset IRI.
	 * @param array<int, array> $files Published file attachments (formatFiles shape).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function __construct(
		public readonly array $publication,
		public readonly string $datasetIri,
		public readonly array $files = [],
	) {
	}//end __construct()
}//end class
