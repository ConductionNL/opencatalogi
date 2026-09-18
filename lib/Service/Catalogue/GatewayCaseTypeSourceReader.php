<?php

/**
 * Reads an external case type catalogue through integriq's gateway.
 *
 * This class holds no transport: it asks integriq's CallService for the
 * registered source and reads what comes back. When integriq is absent, or
 * the call fails, it raises ExternalCatalogueUnreachableException. It never
 * returns an empty list to stand in for "we could not ask", because that is
 * the reading an administrator would act on by assuming the national
 * catalogue had nothing for them.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Catalogue;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The gateway-backed reader for an external case type catalogue.
 */
class GatewayCaseTypeSourceReader implements CaseTypeSourceReader {

	/**
	 * The integriq services this reader will accept as its gateway, in order.
	 *
	 * The app id moves per app, so both the old and the new class name are
	 * tried. Pointing at one name nothing answers to would make the whole
	 * import a silent no-op.
	 *
	 * @var array<int, string>
	 */
	private const GATEWAY_SERVICES = [
		'OCA\Integriq\Service\CallService',
		'OCA\OpenConnector\Service\CallService',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for resolving the gateway.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve integriq's call service, or say the source is unreachable.
	 *
	 * @return object The gateway call service.
	 *
	 * @throws ExternalCatalogueUnreachableException When no gateway is installed.
	 */
	private function gateway(): object {
		foreach (self::GATEWAY_SERVICES as $service) {
			try {
				return $this->container->get($service);
			} catch (\Throwable $e) {
				continue;
			}
		}

		$this->logger->warning('[GatewayCaseTypeSourceReader] No gateway is installed, so no external catalogue can be asked.');

		throw new ExternalCatalogueUnreachableException(
			message: 'The external case type catalogue cannot be asked: the gateway is not installed.'
		);

	}//end gateway()

	/**
	 * Read one definition from the source.
	 *
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 *
	 * @return array<string, mixed> The definition.
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function fetchDefinition(string $sourceId, string $externalId): array {
		$answer = $this->call(sourceId: $sourceId, endpoint: 'zaaktypen/' . rawurlencode($externalId));

		if (isset($answer['results']) === true && is_array($answer['results']) === true) {
			$answer = ($answer['results'][0] ?? []);
		}

		if (is_array($answer) === false || $answer === []) {
			throw new ExternalCatalogueUnreachableException(
				message: 'The external case type catalogue answered nothing readable for ' . $externalId . '.'
			);
		}

		return $answer;

	}//end fetchDefinition()

	/**
	 * List the definitions the source offers.
	 *
	 * @param string $sourceId The registered external catalogue.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function listDefinitions(string $sourceId): array {
		$answer = $this->call(sourceId: $sourceId, endpoint: 'zaaktypen');

		$results = ($answer['results'] ?? $answer);
		if (is_array($results) === false) {
			throw new ExternalCatalogueUnreachableException(
				message: 'The external case type catalogue answered something this app cannot read.'
			);
		}

		return array_values(array_filter($results, 'is_array'));

	}//end listDefinitions()

	/**
	 * Hand the ask to the gateway and read the answer.
	 *
	 * @param string $sourceId The registered external catalogue.
	 * @param string $endpoint The endpoint at the source.
	 *
	 * @return array<string, mixed> The decoded answer.
	 *
	 * @throws ExternalCatalogueUnreachableException When the call fails.
	 */
	private function call(string $sourceId, string $endpoint): array {
		$gateway = $this->gateway();

		try {
			$response = $gateway->call(source: $sourceId, endpoint: $endpoint, method: 'GET');
		} catch (\Throwable $e) {
			$this->logger->warning('[GatewayCaseTypeSourceReader] The gateway call failed: ' . $e->getMessage());
			throw new ExternalCatalogueUnreachableException(
				message: 'The external case type catalogue could not be reached: ' . $e->getMessage(),
				code: 0,
				previous: $e
			);
		}

		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$response = $response->getResponse();
		}

		if (is_string($response) === true) {
			$decoded = json_decode($response, true);
			if (is_array($decoded) === false) {
				throw new ExternalCatalogueUnreachableException(
					message: 'The external case type catalogue answered something this app cannot read.'
				);
			}

			return $decoded;
		}

		if (is_array($response) === true) {
			if (isset($response['body']) === true && is_string($response['body']) === true) {
				$decoded = json_decode($response['body'], true);
				if (is_array($decoded) === true) {
					return $decoded;
				}
			}

			return $response;
		}

		throw new ExternalCatalogueUnreachableException(
			message: 'The external case type catalogue answered something this app cannot read.'
		);

	}//end call()
}//end class
