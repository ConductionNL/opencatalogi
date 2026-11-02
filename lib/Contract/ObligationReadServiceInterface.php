<?php

/**
 * Contract of ObligationReadService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

use DateTimeInterface;
use RuntimeException;

/**
 * Contract of ObligationReadService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface ObligationReadServiceInterface {
	/**
	 * Read every enabled source and assemble the overview.
	 *
	 * Besides the assembled overview the answer carries `dueThisWeek`,
	 * `publishedThisMonth` and `bySource` (per source: outstanding, late and
	 * the next due date), and the obligations ordered late first, then by due
	 * date.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The overview.
	 *
	 * @throws RuntimeException When the sources themselves cannot be read.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function read(?DateTimeInterface $now=null): array;
}//end interface
