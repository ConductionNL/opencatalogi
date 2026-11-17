<?php

/**
 * Stub of the OpenRegister class of the same name, for unit tests run without
 * OpenRegister installed. Mirrors the real signature on openregister main.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getAnchorObjectUuid()
 * @method void setAnchorObjectUuid(?string $anchorObjectUuid)
 * @method string|null getChainKey()
 * @method void setChainKey(?string $chainKey)
 */
class TaskSequence extends Entity {
	protected ?string $uuid = null;
	protected ?string $anchorObjectUuid = null;
	protected ?string $chainKey = null;
}//end class
