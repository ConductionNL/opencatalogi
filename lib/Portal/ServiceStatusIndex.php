<?php

/**
 * OpenCatalogi Service Status Index.
 *
 * The status of the municipality's services as items of a portal's public
 * index (portaliq's `getPublicIndex` contract), so a site page can show it as
 * a table. The states are the states somebody set, read through the same
 * StatusPageService as the public status endpoint: a stale state says since
 * when it was set rather than claiming everything runs.
 *
 * @category Portal
 * @package  OCA\OpenCatalogi\Portal
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
 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Portal;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the service status components and shapes them as public index items.
 *
 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
 */
class ServiceStatusIndex {

	/**
	 * The index item type, and the kind a site page asks for.
	 */
	public const TYPE = 'serviceStatus';

	/**
	 * What a visitor reads for each state.
	 */
	public const STATE_LABELS = [
		'available'   => 'Werkt',
		'degraded'    => 'Verstoring',
		'unavailable' => 'Niet bereikbaar',
		'maintenance' => 'Onderhoud gepland',
	];

	/**
	 * The tone of each state's note.
	 */
	private const STATE_TONES = [
		'available'   => 'positive',
		'degraded'    => 'warning',
		'unavailable' => 'warning',
		'maintenance' => 'neutral',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config        App configuration.
	 * @param ContainerInterface $container     For OpenRegister's ObjectService.
	 * @param StatusPageServiceInterface  $statusService Renders the states with their staleness.
	 * @param LoggerInterface    $logger        The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly StatusPageServiceInterface $statusService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The kind a site page can ask for, with its columns.
	 *
	 * @return array<string, mixed> The kind.
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function kind(): array {
		return [
			'type'       => self::TYPE,
			'label'      => 'Status van de dienstverlening',
			'categories' => [],
			'filters'    => [],
			'columns'    => [
				['key' => 'service', 'label' => 'Dienst'],
				['key' => 'message', 'label' => 'Toelichting'],
				['key' => 'state', 'label' => 'Status'],
			],
		];

	}//end kind()

	/**
	 * The components as index items, in their order. A register that cannot be
	 * read gives no items, never a list that says everything works.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function items(?DateTimeInterface $now=null): array {
		$rendered = $this->statusService->render(
			components: $this->components(),
			stalenessHours: $this->config->getValueInt('opencatalogi', 'status_staleness_hours', StatusPageServiceInterface::DEFAULT_STALENESS_HOURS),
			now: $now
		);

		$items = [];
		foreach ($rendered['components'] as $index => $component) {
			if ($component['component'] === '') {
				continue;
			}

			$items[] = $this->item(component: $component, position: $index);
		}

		return $items;

	}//end items()

	/**
	 * One component as an index item.
	 *
	 * @param array<string, mixed> $component The rendered component.
	 * @param int                  $position  Its place in the order.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function item(array $component, int $position): array {
		$state = (string)$component['state'];
		$label = (self::STATE_LABELS[$state] ?? $state);

		$message = $component['message'];
		if ($message === '' && $state === 'available') {
			$message = 'Alles werkt zoals verwacht.';
		}

		$since = $this->since(setAt: $component['stateSetAt']);
		if ($since !== '' && $state !== 'available') {
			$message = trim($message.' Sinds '.$since.'.');
		}

		return [
			'id'       => self::TYPE.'-'.$position,
			'type'     => self::TYPE,
			'kind'     => 'Dienst',
			'title'    => $component['component'],
			'summary'  => $message,
			'badge'    => $label,
			'noteTone' => (self::STATE_TONES[$state] ?? 'neutral'),
			'cells'    => [
				'service' => $component['component'],
				'message' => $message,
				'state'   => $label,
			],
		];

	}//end item()

	/**
	 * The moment a state was set, in words.
	 *
	 * @param mixed $setAt The stored moment.
	 *
	 * @return string The moment, or '' when unknown.
	 */
	private function since(mixed $setAt): string {
		if (is_string($setAt) === false || $setAt === '') {
			return '';
		}

		try {
			$moment = new DateTimeImmutable($setAt);
		} catch (\Throwable $e) {
			return '';
		}

		$months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
		return $moment->format('j').' '.$months[((int)$moment->format('n') - 1)].', '.$moment->format('H:i');

	}//end since()

	/**
	 * The service status components as arrays.
	 *
	 * @return array<int, array<string, mixed>> The components; none when they cannot be read.
	 */
	private function components(): array {
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema = $this->config->getValueString('opencatalogi', 'service_status_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$result = $this->container->get('OCA\OpenRegister\Service\ObjectService')->searchObjectsPaginated(
				query: [
					'@self'  => ['register' => $register, 'schema' => $schema],
					'_limit' => 100,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[ServiceStatusIndex] the service status could not be read: '.$e->getMessage());
			return [];
		}

		return array_values(array_filter(array_map(fn (mixed $object): array => $this->asArray(object: $object), ($result['results'] ?? []))));

	}//end components()

	/**
	 * An OpenRegister object as an array of its properties.
	 *
	 * @param mixed $object The object or its array.
	 *
	 * @return array<string, mixed> The properties; empty when it is neither.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			return $object['object'];
		}

		return $object;

	}//end asArray()
}//end class
