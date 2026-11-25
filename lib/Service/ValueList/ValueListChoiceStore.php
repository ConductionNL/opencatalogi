<?php

/**
 * OpenCatalogi Value List Choice Store.
 *
 * Reads and writes the organisation's choices about value-list entries: the
 * `valueListChoice` objects in the publication register. Every write goes
 * through OpenRegister's save, so
 * {@see \OCA\OpenCatalogi\Listener\ValueListChoiceListener} guards it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;

/**
 * The stored choices of one value list, keyed by entry URI.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
 */
class ValueListChoiceStore {
	use ReadsObjectProperties;

	/**
	 * The app-config key of the valueListChoice schema.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'value_list_choice_schema';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService and the register configuration.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * The choices of one list, keyed by the URI of the entry they are about.
	 *
	 * Read as the system: choices are configuration every signed-in user may
	 * read, and the public endpoint serves only the explanation of a visible
	 * entry, which is published on purpose.
	 *
	 * @param string $scheme The list, e.g. `informatiecategorieen`.
	 *
	 * @return array<string, array{order: int|null, localLabel: string, explanation: string, hidden: bool, active: bool}>
	 *
	 * @throws RuntimeException When OpenRegister or the schema configuration is not there, or the read fails.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
	 */
	public function forScheme(string $scheme): array {
		$choices = [];
		foreach ($this->stored(scheme: $scheme) as $stored) {
			$choices[(string)$stored['entry']] = self::choiceOf(stored: $stored);
		}

		return $choices;

	}//end forScheme()

	/**
	 * Save the organisation's choice about one entry, merged onto the choice stored before.
	 *
	 * Written as the system after the caller's administrator check; the
	 * pre-save guard still refuses a field a choice does not own.
	 *
	 * @param array<string, mixed> $choice The choice: `scheme` and `entry` name the entry, the other keys are the fields to change.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister or the schema configuration is not there.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function save(array $choice): void {
		$scheme = (string)($choice['scheme'] ?? '');
		$entry = (string)($choice['entry'] ?? '');
		$existing = null;
		$object = [];
		foreach ($this->stored(scheme: $scheme) as $stored) {
			if ($stored['entry'] === $entry) {
				$existing = (string)($stored['id'] ?? '');
				$object = $stored;
				unset($object['id'], $object['uuid'], $object['@self']);
				break;
			}
		}

		if ($existing === '') {
			$existing = null;
		}

		$target = $this->target();
		$this->openRegister->objectService()->saveObject(
			object: array_merge($object, $choice),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target['register'], schema: $target['schema']), uuid: $existing),
			_rbac: false,
			_multitenancy: false
		);

	}//end save()

	/**
	 * The stored choices of one list, each with its `id` and a non-empty `entry`.
	 *
	 * @param string $scheme The list.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister or the schema configuration is not there.
	 */
	private function stored(string $scheme): array {
		$target = $this->target();
		$found = $this->openRegister->objectService()->searchObjectsPaginated(
			query: ['@self' => $target, 'scheme' => $scheme, '_limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ((array)($found['results'] ?? []) as $row) {
			$stored = $this->asRecord(object: $row);
			$stored['entry'] = trim((string)($stored['entry'] ?? ''));
			if ($stored['entry'] !== '' && (string)($stored['scheme'] ?? '') === $scheme) {
				$rows[] = $stored;
			}
		}

		return $rows;

	}//end stored()

	/**
	 * The publication register and the valueListChoice schema.
	 *
	 * @return array{register: string, schema: string}
	 *
	 * @throws RuntimeException When OpenRegister is not installed or either is not configured.
	 */
	private function target(): array {
		if ($this->openRegister->isInstalled() === false) {
			throw new RuntimeException('OpenRegister is not available, so the value list choices cannot be reached.');
		}

		try {
			$target = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: self::SCHEMA_KEY);
		} catch (\Throwable $e) {
			throw new RuntimeException('The value list choice schema is not configured: ' . $e->getMessage(), 0, $e);
		}

		return ['register' => (string)($target['register'] ?? ''), 'schema' => (string)($target['schema'] ?? '')];

	}//end target()

	/**
	 * The choice a stored object holds, in a fixed shape.
	 *
	 * @param array<string, mixed> $stored The stored object.
	 *
	 * @return array{order: int|null, localLabel: string, explanation: string, hidden: bool, active: bool}
	 */
	private static function choiceOf(array $stored): array {
		$order = null;
		if (is_numeric($stored['order'] ?? null) === true) {
			$order = (int)$stored['order'];
		}

		return [
			'order' => $order,
			'localLabel' => trim((string)($stored['localLabel'] ?? '')),
			'explanation' => trim((string)($stored['explanation'] ?? '')),
			'hidden' => (($stored['hidden'] ?? false) === true),
			'active' => (($stored['active'] ?? false) === true),
		];

	}//end choiceOf()
}//end class
