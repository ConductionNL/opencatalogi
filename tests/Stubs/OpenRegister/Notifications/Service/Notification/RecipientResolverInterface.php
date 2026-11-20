<?php

/**
 * Stub for OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stub of the resolver an `expression` notification recipient names; mirrors the real signature.
 */
interface RecipientResolverInterface {
	/**
	 * The user ids a notification on this object goes to.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param array<string, mixed> $context The notification context.
	 *
	 * @return array<int, string>
	 */
	public function resolve(ObjectEntity $object, array $context): array;
}//end interface
