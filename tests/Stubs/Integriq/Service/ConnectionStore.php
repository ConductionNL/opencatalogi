<?php

/**
 * Integriq ConnectionStore test stub.
 *
 * Mirrors findSource() and findSourceBySlug() of OCA\Integriq\Service\ConnectionStore
 * on integriq `development` (read 29 Sep 2026). Loaded only when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Integriq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Integriq's store of connections and sources.
 */
class ConnectionStore {

	/**
	 * Read a source by its uuid.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function findSource(string $uuid): ?ObjectEntity {
		return null;
	}//end findSource()

	/**
	 * Read a source by its slug.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function findSourceBySlug(string $slug): ?ObjectEntity {
		return null;
	}//end findSourceBySlug()
}//end class
