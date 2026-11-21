<?php

/**
 * A permanent delete of a draft that stopped part way.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
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
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use RuntimeException;

/**
 * The publication is kept. The removed list names what was already gone.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeFailedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{kind: string, id: string, name: string}> $removed What was removed before the failure.
	 * @param array{kind: string, id: string, name: string} $failedOn The item whose removal failed.
	 * @param bool $publicationKept Whether the publication itself is still there.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(
		public readonly array $removed,
		public readonly array $failedOn,
		public readonly bool $publicationKept = true,
	) {
		parent::__construct(message: 'The permanent delete stopped at ' . $failedOn['kind'] . ' ' . $failedOn['id']);
	}//end __construct()
}//end class
