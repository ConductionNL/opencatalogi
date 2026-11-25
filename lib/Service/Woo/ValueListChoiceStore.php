<?php

/**
 * OpenCatalogi Value List Choice Store.
 *
 * Reads the organisation's choices about value-list entries: the
 * `valueListChoice` objects in the publication register. It only reads; the
 * administrator writes choices through OpenRegister, where
 * {@see \OCA\OpenCatalogi\Listener\ValueListChoiceListener} guards the save.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCP\IAppConfig;
use RuntimeException;

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
	 * The properties a choice holds; every other property is refused on save.
	 *
	 * @var list<string>
	 */
	public const PROPERTIES = ['scheme', 'entry', 'order', 'localLabel', 'explanation', 'hidden', 'active'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                  $config       Holds the register and schema ids.
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
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
		if ($this->openRegister->isInstalled() === false) {
			throw new RuntimeException('OpenRegister is not available, so the value list choices cannot be read.');
		}

		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', self::SCHEMA_KEY, ''));
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Register configuration is not set for publication_register / ' . self::SCHEMA_KEY . '.');
		}

		$result = $this->openRegister->objectService()->searchObjectsPaginated(
			query: ['@self' => ['register' => $register, 'schema' => $schema], 'scheme' => $scheme, '_limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		$choices = [];
		foreach ((array)($result['results'] ?? []) as $row) {
			$stored = $this->asRecord(object: $row);
			$entry = trim((string)($stored['entry'] ?? ''));
			if ($entry === '' || (string)($stored['scheme'] ?? '') !== $scheme) {
				continue;
			}

			$choices[$entry] = self::choiceOf(stored: $stored);
		}

		return $choices;

	}//end forScheme()

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
