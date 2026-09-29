<?php

/**
 * OpenCatalogi PLOOI Delivery Service.
 *
 * Delivers a publication that turned public to PLOOI, the delivery API of
 * open.overheid.nl, when a catalogue that holds it has PLOOI delivery on, and
 * stores the outcome on the publication. A failed delivery is stored as failed
 * with its reason; it never blocks or undoes the publishing itself.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Delivers public publications to PLOOI and records the outcome.
 *
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */
class PlooiDeliveryService {

	/**
	 * The publication properties this service writes.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['plooiStatus', 'plooiDeliveredAt', 'plooiIdentifier', 'plooiReason'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings, for the catalog register and schema and the object service.
	 * @param NationalIndexService $indexService The national channels.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly NationalIndexService $indexService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Deliver one publication to PLOOI when a catalogue that holds it asks for it.
	 *
	 * @param string $uuid The publication.
	 * @param string $register Its register id.
	 * @param string $schema Its schema id.
	 *
	 * @return array<string, string>|null The PLOOI fields stored, or null when no catalogue asks for PLOOI.
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 */
	public function deliver(string $uuid, string $register, string $schema): ?array {
		if ($this->isInPlooiCatalogue(register: $register, schema: $schema) === false) {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$entity = $objectService?->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$publication = $entity->jsonSerialize();
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$fields = ['plooiDeliveredAt' => $now];

		try {
			$result = $this->indexService->deliverToPlooi(document: $this->document(publication: $publication, uuid: $uuid));
			$fields['plooiStatus'] = 'delivered';
			$fields['plooiIdentifier'] = (string)($result['identifier'] ?? '');
			$fields['plooiReason'] = '';
		} catch (IndexUnreachableException $e) {
			$fields['plooiStatus'] = 'failed';
			$fields['plooiReason'] = $e->getMessage();
			$this->logger->warning('[PlooiDeliveryService] PLOOI delivery of ' . $uuid . ' failed: ' . $e->getMessage());
		}

		$record = $publication;
		unset($record['@self']);
		$objectService->saveObject(
			object: array_merge($record, $fields),
			register: $register,
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false,
			silent: true
		);

		return $fields;

	}//end deliver()

	/**
	 * The DiWoo metadata and document links sent to PLOOI.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $uuid Its uuid.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function document(array $publication, string $uuid): array {
		$links = [];
		foreach ((array)($publication['@self']['files'] ?? []) as $file) {
			if (is_array($file) === true && (string)($file['downloadUrl'] ?? '') !== '') {
				$links[] = ['title' => (string)($file['title'] ?? $file['name'] ?? ''), 'url' => (string)$file['downloadUrl']];
			}
		}

		return [
			'identifier' => $uuid,
			'title' => (string)($publication['title'] ?? ''),
			'description' => (string)($publication['summary'] ?? ($publication['description'] ?? '')),
			'publicationDate' => (string)($publication['publicationDate'] ?? ''),
			'organisation' => (string)($publication['organization'] ?? ''),
			'documents' => $links,
		];

	}//end document()

	/**
	 * Whether a catalogue with PLOOI delivery on holds this register and schema.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return bool True when one does.
	 */
	private function isInPlooiCatalogue(string $register, string $schema): bool {
		$configuration = ($this->settingsService->getSettings()['configuration'] ?? []);
		$catalogRegister = (string)($configuration['catalog_register'] ?? '');
		$catalogSchema = (string)($configuration['catalog_schema'] ?? '');
		$objectService = $this->settingsService->getObjectService();
		if ($catalogRegister === '' || $catalogSchema === '' || $objectService === null) {
			return false;
		}

		$result = $objectService->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $catalogRegister, 'schema' => $catalogSchema],
				'plooiDelivery' => true,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($result['results'] ?? []) as $catalog) {
			if (is_object($catalog) === true && method_exists($catalog, 'jsonSerialize') === true) {
				$catalog = $catalog->jsonSerialize();
			}

			if (is_array($catalog) === false || ($catalog['plooiDelivery'] ?? false) !== true) {
				continue;
			}

			$registers = array_map('strval', (array)($catalog['registers'] ?? []));
			$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));
			if (in_array($register, $registers, true) === true && in_array($schema, $schemas, true) === true) {
				return true;
			}
		}

		return false;

	}//end isInPlooiCatalogue()
}//end class
