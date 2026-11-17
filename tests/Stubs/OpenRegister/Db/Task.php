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
 * The fields the opencatalogi tests read; the getters and setters are Entity's magic ones, as on the real class.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getSequenceUuid()
 * @method void setSequenceUuid(?string $sequenceUuid)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $completedBy)
 * @method string|null getResultText()
 * @method void setResultText(?string $resultText)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 */
class Task extends Entity {
	public const STATE_COMPLETED = 'completed';
	public const STATE_TERMINATED = 'terminated';

	protected ?string $uuid = null;
	protected ?string $state = null;
	protected ?string $outcome = null;
	protected ?string $objectUuid = null;
	protected ?string $sequenceUuid = null;
	protected ?string $completedBy = null;
	protected ?string $resultText = null;
	protected ?string $comment = null;
}//end class
