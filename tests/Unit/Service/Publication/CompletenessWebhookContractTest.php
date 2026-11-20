<?php

/**
 * Contract test for the completeness webhook recipe: the recipe's filters,
 * run over the payloads OpenRegister builds for three successive updates,
 * match only the update that made the publication complete.
 *
 * The payload is built the way OpenRegister's WebhookEventListener builds the
 * ObjectUpdatedEvent payload (`object`, `newObject`, `oldObject`, `register`,
 * `schema`, openregister#10572). The filter is OpenRegister's own
 * WebhookService::passesFilters() when that class is installed, else the same
 * rule: every filter key, in dot notation, equals the payload value.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-the-source-system-is-told-through-an-openregister-webhook-req-pts-003
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\CompletenessService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversNothing
 */
final class CompletenessWebhookContractTest extends TestCase {

	/**
	 * The recipe's filters, as docs/features/completeness-webhook.md gives them.
	 *
	 * @var array<string, string>
	 */
	public const RECIPE = [
		'oldObject.completeness'  => CompletenessService::INCOMPLETE,
		'newObject.completeness'  => CompletenessService::COMPLETE,
		'newObject.@self.owner'   => 'zaaksysteem',
	];

	/**
	 * Of three successive updates, only the one from incomplete to complete passes; narrowing to a register and schema keeps it.
	 *
	 * @return void
	 */
	public function testTheFilterMatchesOnlyTheCompletingUpdate(): void {
		$states = [
			['title' => 'Besluit', 'completeness' => 'incomplete'],
			['title' => 'Besluit', 'completeness' => 'incomplete', 'expectedDocuments' => 2],
			['title' => 'Besluit', 'completeness' => 'complete', 'completeAt' => '2026-11-14T10:00:00+00:00'],
			['title' => 'Besluit (edited)', 'completeness' => 'complete', 'completeAt' => '2026-11-14T10:00:00+00:00'],
		];

		$passes = [];
		for ($i = 1; $i < count($states); $i++) {
			$payload  = $this->updatePayload(old: $states[$i - 1], new: $states[$i]);
			$passes[] = $this->passes(filters: self::RECIPE, payload: $payload);
		}

		self::assertSame([false, true, false], $passes);

		$narrowed = self::RECIPE + ['register' => '3', 'schema' => '12'];
		self::assertTrue($this->passes(filters: $narrowed, payload: $this->updatePayload(old: $states[1], new: $states[2])));
		self::assertFalse($this->passes(filters: ['schema' => '99'] + self::RECIPE, payload: $this->updatePayload(old: $states[1], new: $states[2])));
		self::assertFalse($this->passes(filters: self::RECIPE, payload: $this->updatePayload(old: $states[1], new: $states[2], owner: 'someone-else')));
	}//end testTheFilterMatchesOnlyTheCompletingUpdate()

	/**
	 * The ObjectUpdatedEvent payload OpenRegister's WebhookEventListener dispatches.
	 *
	 * @param array<string, mixed> $old   The publication before the update.
	 * @param array<string, mixed> $new   The publication after it.
	 * @param string               $owner The owner of the publication.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function updatePayload(array $old, array $new, string $owner = 'zaaksysteem'): array {
		$before = $this->serialised(data: $old, owner: $owner);
		$after  = $this->serialised(data: $new, owner: $owner);

		return [
			'objectType' => 'object',
			'action'     => 'update',
			'object'     => $after,
			'objectUuid' => 'pub-1',
			'register'   => '3',
			'schema'     => '12',
			'timestamp'  => '2026-11-14T10:00:00+00:00',
			'newObject'  => $after,
			'oldObject'  => $before,
		];
	}//end updatePayload()

	/**
	 * A publication as ObjectEntity::jsonSerialize() answers it, its owner in `@self`.
	 *
	 * @param array<string, mixed> $data  The properties.
	 * @param string               $owner The owner.
	 *
	 * @return array<string, mixed> The serialised object.
	 */
	private function serialised(array $data, string $owner): array {
		$entity = new ObjectEntity();
		$entity->setUuid('pub-1');
		$entity->setRegister('3');
		$entity->setSchema('12');
		$entity->setOwner($owner);
		$entity->setObject($data);
		$object = $entity->jsonSerialize();
		// The real ObjectEntity puts its owner in @self (getObjectArray()); the test stub leaves it out.
		$object['@self']['owner'] = ($object['@self']['owner'] ?? $owner);

		return $object;
	}//end serialised()

	/**
	 * Whether a payload passes these filters, by OpenRegister's rule.
	 *
	 * @param array<string, mixed> $filters The webhook's filters.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function passes(array $filters, array $payload): bool {
		$service = 'OCA\OpenRegister\Service\WebhookService';
		$webhook = 'OCA\OpenRegister\Db\Webhook';
		if (class_exists($service) === true && class_exists($webhook) === true && method_exists($webhook, 'setFiltersArray') === true) {
			$hook = new $webhook();
			$hook->setFiltersArray($filters);
			$class  = new ReflectionClass($service);
			$method = $class->getMethod('passesFilters');

			return (bool)$method->invoke($class->newInstanceWithoutConstructor(), $hook, $payload);
		}

		foreach ($filters as $key => $value) {
			$actual = $payload;
			foreach (explode('.', $key) as $part) {
				$actual = (is_array($actual) === true && isset($actual[$part]) === true) ? $actual[$part] : null;
			}

			if ($actual !== $value) {
				return false;
			}
		}

		return true;
	}//end passes()
}//end class
