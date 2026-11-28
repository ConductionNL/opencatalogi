<?php

/**
 * A harvest feed request the app refuses, with the status and field to show.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Harvest
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
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Harvest;

use RuntimeException;

/**
 * The refusal: 403 for a non-administrator, 404 for a feed that is not this
 * app's, 422 naming the field a save broke.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */
final class HarvestFeedRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int         $status  The HTTP status to answer with.
	 * @param string      $message What was refused and why.
	 * @param string|null $field   The field the refusal is shown on, if any.
	 */
	public function __construct(
		public readonly int $status,
		string $message,
		public readonly ?string $field=null,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The answer body.
	 *
	 * @return array{error: string, field?: string} The error and the field it is shown on.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.2
	 */
	public function toArray(): array {
		$body = ['error' => $this->getMessage()];
		if ($this->field !== null) {
			$body['field'] = $this->field;
		}

		return $body;
	}//end toArray()
}//end class
