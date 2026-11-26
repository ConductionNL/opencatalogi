<?php

/**
 * The configuration import failed; the failure is already in the log.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use RuntimeException;
use Throwable;

/**
 * The configuration import failed. Its message names no path or OpenRegister
 * internal; the original failure is its previous exception and is already logged.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-4
 */
class SettingsLoadFailedException extends RuntimeException {

	/**
	 * What a caller is told.
	 *
	 * @var string
	 */
	public const MESSAGE = 'Failed to load settings. The details are in the Nextcloud log.';

	/**
	 * Constructor.
	 *
	 * @param Throwable $previous The failure the import ran into.
	 *
	 * @spec openspec/changes/admin-settings/tasks.md#task-4
	 */
	public function __construct(Throwable $previous) {
		parent::__construct(self::MESSAGE, 0, $previous);
	}//end __construct()
}//end class
