<?php

/**
 * One pass of the comment-period withdrawal.
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
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;

/**
 * The object service, register, period schema and moment one withdrawal pass works with.
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */
final class CommentPeriodWithdrawalRun {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The consumed OR ObjectService.
	 * @param string $register The register.
	 * @param string $schema The comment period schema.
	 * @param DateTimeImmutable $moment The moment the pass runs at.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly DateTimeImmutable $moment,
	) {

	}//end __construct()
}//end class
