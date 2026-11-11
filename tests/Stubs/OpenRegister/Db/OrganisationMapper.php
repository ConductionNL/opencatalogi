<?php

/**
 * Stub for OCA\OpenRegister\Db\OrganisationMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use RuntimeException;

/**
 * Minimal stub for OrganisationMapper: the two lookups the sitemap reads.
 *
 * The real methods return an Organisation entity; the stub declares object,
 * because the entity is not stubbed and the tests return a stand-in.
 */
class OrganisationMapper {

	/**
	 * Find an organisation by UUID.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return object The organisation.
	 */
	public function findByUuid(string $uuid): object {
		throw new RuntimeException('Organisation not found: ' . $uuid);
	}//end findByUuid()

	/**
	 * Find an organisation, following any merge to the surviving one.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return object The surviving organisation.
	 */
	public function findByUuidFollowingMerge(string $uuid): object {
		throw new RuntimeException('Organisation not found: ' . $uuid);
	}//end findByUuidFollowingMerge()
}//end class
