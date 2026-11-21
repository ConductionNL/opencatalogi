<?php

/**
 * A permanent delete of a draft that was refused before anything was removed.
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
 * Nothing was removed. The reason says which proof did not hold.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeRefusedException extends RuntimeException {

	/**
	 * The publication is not a draft or in review.
	 */
	public const NOT_A_DRAFT = 'not-a-draft';

	/**
	 * The publication carries a first release stamp.
	 */
	public const RELEASED = 'released';

	/**
	 * A depublication record names the publication.
	 */
	public const WITHDRAWN = 'withdrawn';

	/**
	 * The audit trail holds an entry in which the publication was published.
	 */
	public const PUBLISHED_IN_TRAIL = 'published-in-trail';

	/**
	 * A proof or the list of what would be removed could not be read.
	 */
	public const UNVERIFIABLE = 'unverifiable';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the reason constants.
	 * @param string $evidence What proves it: a date, a record id or a step, empty when there is none.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(public readonly string $reason, public readonly string $evidence = '') {
		parent::__construct(message: trim('The permanent delete was refused: ' . $reason . ' ' . $evidence));
	}//end __construct()
}//end class
