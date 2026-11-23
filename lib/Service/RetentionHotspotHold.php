<?php

/**
 * OpenCatalogi Retention Hotspot Hold.
 *
 * A publication filed under a subject that is an archive hotspot is kept
 * permanently, so OpenCatalogi's own retention takes no action on it. Before
 * the retention run depublishes or archives a publication, this resolves its
 * subjects: a hotspot among them holds the publication and names the subject,
 * and a subject that cannot be read holds it as unresolved, failing closed.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IAppConfig;
use Throwable;

/**
 * Says whether an archive hotspot holds a publication back from its retention action.
 *
 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-opencatalogis-own-retention-takes-no-action-under-a-hotspot-req-tha-003
 */
class RetentionHotspotHold {

	/**
	 * The outcome of a publication under a hotspot subject.
	 */
	public const BY_HOTSPOT = 'heldByHotspot';

	/**
	 * The outcome of a publication whose subjects cannot be read.
	 */
	public const UNRESOLVED = 'heldUnresolved';

	/**
	 * What the report says of a publication under a hotspot; the subject's title follows.
	 */
	public const KEPT = 'Kept permanently: archive hotspot ';

	/**
	 * The subjects read so far in this run, by id: its data, or null when it could not be read.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $subjects = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the theme register and schema.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {

	}//end __construct()

	/**
	 * The hold on a publication: by a hotspot (with the subject's title), unresolved, or null when none.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return array{outcome: string, subject?: string}|null The hold.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-opencatalogis-own-retention-takes-no-action-under-a-hotspot-req-tha-003
	 */
	public function holdOf(object $objectService, array $publication): ?array {
		$unresolved = false;
		foreach (self::subjectIds(publication: $publication) as $id) {
			$subject = $this->subject(objectService: $objectService, id: $id);
			if ($subject === null) {
				$unresolved = true;
				continue;
			}

			if (($subject['archiveHotspot'] ?? false) === true) {
				return ['outcome' => self::BY_HOTSPOT, 'subject' => (string)($subject['title'] ?? $id)];
			}
		}

		if ($unresolved === true) {
			return ['outcome' => self::UNRESOLVED];
		}

		return null;

	}//end holdOf()

	/**
	 * The ids of the subjects a publication is filed under.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return array<int, string> The ids.
	 */
	private static function subjectIds(array $publication): array {
		$ids = [];
		foreach ((array)($publication['themes'] ?? []) as $theme) {
			if (is_array($theme) === true) {
				$theme = ($theme['id'] ?? ($theme['uuid'] ?? ''));
			}

			if (is_string($theme) === true && trim($theme) !== '') {
				$ids[] = trim($theme);
			}
		}

		return array_values(array_unique($ids));

	}//end subjectIds()

	/**
	 * One subject's data, read once per run with RBAC off, or null when it cannot be read.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $id The subject's id.
	 *
	 * @return array<string, mixed>|null The subject.
	 */
	private function subject(object $objectService, string $id): ?array {
		if (array_key_exists($id, $this->subjects) === false) {
			$this->subjects[$id] = $this->read(objectService: $objectService, id: $id);
		}

		return $this->subjects[$id];

	}//end subject()

	/**
	 * Read a subject from the configured theme register and schema.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $id The subject's id.
	 *
	 * @return array<string, mixed>|null The subject, or null when it is missing or the read fails.
	 */
	private function read(object $objectService, string $id): ?array {
		$options = new ObjectFindOptions(register: $this->configured(key: 'theme_register'), schema: $this->configured(key: 'theme_schema'));

		try {
			$found = $objectService->find(id: $id, options: $options, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			$found = $found->jsonSerialize();
		}

		if (is_array($found) === false || $found === []) {
			return null;
		}

		return $found;

	}//end read()

	/**
	 * A configured id, or null when it is not set.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string|null The id.
	 */
	private function configured(string $key): ?string {
		$value = trim($this->config->getValueString('opencatalogi', $key, ''));
		if ($value === '') {
			return null;
		}

		return $value;

	}//end configured()
}//end class
