<?php

/**
 * Reads and writes Woo requests through the consumed OpenRegister ObjectService.
 *
 * Split out of the controller so the controller is about HTTP and this is about
 * storage. An unconfigured register raises rather than returning an empty result:
 * a request store that answers "no requests" when it was never configured hides a
 * statutory backlog behind a clean-looking screen.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The storage surface for Woo requests.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */
class WooRequestStore {

	/**
	 * How many requests the terms report reads.
	 *
	 * @var int
	 */
	private const PAGE = 1000;

	/**
	 * Cached OpenRegister ObjectService.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Holds the register and schema identifiers.
	 * @param ContainerInterface $container Server container, for the consumed OR ObjectService.
	 * @param IAppManager $appManager Answers whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Resolve the consumed OpenRegister ObjectService.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	private function objects(): object {
		if ($this->objectService === null) {
			// ADR-083: ask the app manager before reaching into the container, so
			// an instance without OpenRegister gets a named refusal instead of a
			// container error nobody can act on.
			if ($this->appManager->isInstalled('openregister') === false) {
				throw new RuntimeException(
					message: 'OpenRegister is not installed, so Woo requests cannot be read or written.'
				);
			}

			try {
				$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (\Throwable $e) {
				throw new RuntimeException(
					message: 'OpenRegister is unavailable, so Woo requests cannot be read or written.',
					code: 0,
					previous: $e
				);
			}
		}

		return $this->objectService;

	}//end objects()

	/**
	 * The register and schema the requests live in.
	 *
	 * There is no empty-string fallback. An unconfigured context raises, naming
	 * the key, so an operator sees a fixable error instead of a screen reporting
	 * no Woo requests.
	 *
	 * @return array{register: string, schema: string} The identifiers.
	 *
	 * @throws RuntimeException When either key is unset.
	 *
	 * @spec exclude pure config plumbing — resolves the configured request register.
	 */
	private function target(): array {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', 'woo_request_schema', ''));
		if ($register === '' || $schema === '') {
			throw new RuntimeException(
				message: 'Register configuration is not set for publication_register / woo_request_schema. '
					. 'Run the OpenCatalogi setup wizard or POST to /api/settings/load to initialise registers and schemas.'
			);
		}

		return ['register' => $register, 'schema' => $schema];

	}//end target()

	/**
	 * Write a request.
	 *
	 * @param array<string, mixed> $record The request.
	 * @param string $uuid The id to write to, empty for a new one.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RuntimeException When the register is unresolvable or the write fails.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function save(array $record, string $uuid = ''): array {
		$target = $this->target();

		return $this->asArray(
			object: $this->objects()->saveObject(
				object: $record,
				register: $target['register'],
				schema: $target['schema'],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			)
		);

	}//end save()

	/**
	 * Read one request, or null when there is no such request.
	 *
	 * @param string $requestId The request.
	 *
	 * @return array<string, mixed>|null The request.
	 *
	 * @throws RuntimeException When the register is unresolvable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function find(string $requestId): ?array {
		$target = $this->target();

		try {
			$found = $this->asArray(
				object: $this->objects()->find(
					id: $requestId,
					register: $target['register'],
					schema: $target['schema']
				)
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($found === []) {
			return null;
		}

		$found['id'] = ($found['id'] ?? $requestId);

		return $found;

	}//end find()

	/**
	 * Every stored request.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @throws RuntimeException When the register is unresolvable or unreadable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function all(): array {
		$target = $this->target();
		$result = $this->objects()->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $target['register'], 'schema' => $target['schema']],
				'_limit' => self::PAGE,
			],
			_rbac: false,
			_multitenancy: false
		);

		$requests = [];
		foreach ((array)($result['results'] ?? []) as $row) {
			$requests[] = $this->asArray(object: $row);
		}

		return $requests;

	}//end all()

	/**
	 * Read an object's properties, whatever shape OpenRegister returned.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;

	}//end asArray()
}//end class
