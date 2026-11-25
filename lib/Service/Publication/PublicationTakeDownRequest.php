<?php

/**
 * One withdrawal: the publication, its properties, why, who, and what the tombstone says.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * The input of PublicationTakeDown::withdraw().
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
final class PublicationTakeDownRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $id         The publication id.
	 * @param array<string, mixed> $properties The publication's properties as read with RBAC.
	 * @param string               $reason     Why it is withdrawn.
	 * @param string               $actor      Who withdraws it, for the record.
	 * @param array<string, mixed> $tombstone  `publicReason` and `tombstoneShowsTitle` as chosen; empty values keep the schema's default.
	 *
	 * @spec exclude value object constructor.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $properties,
		public readonly string $reason,
		public readonly string $actor,
		public readonly array $tombstone = [],
	) {
	}//end __construct()
}//end class
