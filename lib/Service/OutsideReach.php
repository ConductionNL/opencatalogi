<?php

/**
 * What can still leave the instance outside OpenCatalogi: enabled OpenRegister
 * webhooks and integriq synchronisations.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Names, never stops: staging is OpenCatalogi's, and these belong to OpenRegister
 * and integriq. Either app may be absent; a list that cannot be read is empty.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
class OutsideReach {

	private const WEBHOOK_MAPPER = 'OCA\OpenRegister\Db\WebhookMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    DI container (OpenRegister's webhook mapper, lazily).
	 * @param OpenRegisterLookupInterface $openRegister Reaches OpenRegister's object service.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {
	}//end __construct()

	/**
	 * The enabled webhooks and synchronisations, by name.
	 *
	 * @return array{webhooks: list<string>, synchronisations: list<string>}
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function list(): array {
		return ['webhooks' => $this->webhooks(), 'synchronisations' => $this->synchronisations()];
	}//end list()

	/**
	 * The names of OpenRegister's enabled webhooks.
	 *
	 * @return list<string>
	 */
	private function webhooks(): array {
		try {
			$webhooks = $this->container->get(self::WEBHOOK_MAPPER)->findEnabled();
		} catch (Throwable) {
			return [];
		}

		$names = [];
		foreach ((array)$webhooks as $webhook) {
			if (is_object($webhook) === true && method_exists($webhook, 'getName') === true) {
				$names[] = (string)$webhook->getName();
			}
		}

		return $names;
	}//end webhooks()

	/**
	 * The names of integriq's synchronisations that are not disabled.
	 *
	 * @return list<string>
	 */
	private function synchronisations(): array {
		try {
			$found = $this->openRegister->installedObjectService()->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'synchronization'], 'limit' => 500],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return [];
		}

		return self::enabledNames(rows: (array)($found['results'] ?? $found));
	}//end synchronisations()

	/**
	 * The names of the rows that are not disabled.
	 *
	 * @param array<int|string, mixed> $rows The synchronisation rows.
	 *
	 * @return list<string>
	 */
	private static function enabledNames(array $rows): array {
		$names = [];
		foreach ($rows as $row) {
			$data = self::dataOf(row: $row);
			if (($data['status'] ?? '') !== 'disabled') {
				$names[] = (string)($data['name'] ?? '');
			}
		}

		return $names;
	}//end enabledNames()

	/**
	 * A row's data, whether an entity or an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function dataOf(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return (array)$row;
	}//end dataOf()
}//end class
