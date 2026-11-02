<?php

/**
 * OpenCatalogi row flattening for the migration services.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Flatten an OpenRegister row into a field map with its uuid resolved.
 *
 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
 */
trait FlattensObjectRows {
	/**
	 * Flatten an object row into a field map with its uuid resolved.
	 *
	 * The uuid comes from @self uuid, @self id, the row's uuid or its id, in that order.
	 *
	 * @param mixed $row The row as the reader returned it.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function toFields(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		$self = ($row['@self'] ?? []);
		$uuid = '';
		if (is_array($self) === true) {
			$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ''));
		}

		if ($uuid === '') {
			$uuid = (string)($row['uuid'] ?? ($row['id'] ?? ''));
		}

		$row['uuid'] = $uuid;

		return $row;
	}//end toFields()
}//end trait
