<?php

/**
 * Contract of MarkupRenderService, for callers in other components.
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

/**
 * Contract of MarkupRenderService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface MarkupRenderServiceInterface {
	/**
	 * Render a body of markup.
	 *
	 * @param string $markup The body.
	 *
	 * @return array{html: string, truncated: boolean}
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
	 */
	public function render(string $markup): array;
}//end interface
