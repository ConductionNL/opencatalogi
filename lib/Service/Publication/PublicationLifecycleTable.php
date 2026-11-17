<?php

/**
 * Publication lifecycle table
 *
 * The transition table of the publication lifecycle, read from the schema the
 * app ships (base register plus fragments), never from a copy
 * (publication-lifecycle-on-or REQ-PLC-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Service\SettingsService;

/**
 * Reads the shipped publication lifecycle.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */
class PublicationLifecycleTable {

	/**
	 * The directory the shipped register lives in.
	 *
	 * @var string
	 */
	private readonly string $settingsDir;

	/**
	 * Constructor.
	 *
	 * @param string|null $settingsDir The lib/Settings directory; the app's own when null.
	 */
	public function __construct(?string $settingsDir = null) {
		$this->settingsDir = ($settingsDir ?? dirname(__DIR__, 2) . '/Settings');
	}//end __construct()

	/**
	 * The stored states, the initial and final states and every named transition.
	 *
	 * @return array{field: string, states: list<string>, initial: string|null, final: list<string>, transitions: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
	 */
	public function table(): array {
		$schema = SettingsService::shippedSchema(settingsDir: $this->settingsDir, slug: 'publication');
		$lifecycle = ($schema['x-openregister-lifecycle'] ?? []);
		$field = (string)($lifecycle['field'] ?? 'status');

		$initial = null;
		if (isset($lifecycle['initial']) === true) {
			$initial = (string)$lifecycle['initial'];
		}

		$transitions = [];
		foreach ((array)($lifecycle['transitions'] ?? []) as $action => $transition) {
			$transitions[] = self::row(action: (string)$action, transition: (array)$transition);
		}

		return [
			'field' => $field,
			'states' => array_values((array)($schema['properties'][$field]['enum'] ?? [])),
			'initial' => $initial,
			'final' => array_values((array)($lifecycle['final'] ?? [])),
			'transitions' => $transitions,
		];
	}//end table()

	/**
	 * One transition as published: its action, from, to, label, description and inputs.
	 *
	 * The guard class and the actions are internal, so they are left out.
	 *
	 * @param string               $action     The action name.
	 * @param array<string, mixed> $transition The declared transition.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $action, array $transition): array {
		return [
			'action' => $action,
			'from' => array_values((array)($transition['from'] ?? [])),
			'to' => (string)($transition['to'] ?? ''),
			'label' => (string)($transition['label'] ?? $action),
			'description' => (string)($transition['description'] ?? ''),
			'inputs' => array_values((array)($transition['inputs'] ?? [])),
		];
	}//end row()
}//end class
