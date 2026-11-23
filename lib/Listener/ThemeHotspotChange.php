<?php

/**
 * OpenCatalogi Theme Hotspot Change.
 *
 * Whether a save of an object changes the archive hotspot marking of a subject:
 * the one question both the guard before the save and the audit after it ask.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;

/**
 * Says whether a save changes a subject's archive hotspot marking.
 *
 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
 */
class ThemeHotspotChange {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the theme schema.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {

	}//end __construct()

	/**
	 * Whether the save of a subject changes its marking.
	 *
	 * @param ObjectEntity $written The object as written.
	 * @param ObjectEntity|null $stored The object as stored before, null on create.
	 *
	 * @return bool True when the object is a subject and its marking changes.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	public function changes(ObjectEntity $written, ?ObjectEntity $stored): bool {
		$schema = (string)$written->getSchema();
		if ($schema === '' || $schema !== trim($this->config->getValueString('opencatalogi', 'theme_schema', ''))) {
			return false;
		}

		$before = false;
		if ($stored !== null) {
			$before = $this->isMarked(entity: $stored);
		}

		return $this->isMarked(entity: $written) !== $before;

	}//end changes()

	/**
	 * Whether a subject is marked as an archive hotspot.
	 *
	 * @param ObjectEntity $entity The subject.
	 *
	 * @return bool True when marked.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	public function isMarked(ObjectEntity $entity): bool {
		return (($entity->getObject() ?? [])['archiveHotspot'] ?? false) === true;

	}//end isMarked()
}//end class
