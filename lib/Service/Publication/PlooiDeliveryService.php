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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\PlooiDeliveryServiceInterface;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\DryRunRecorder;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use Psr\Log\LoggerInterface;

/**
 * Delivers public publications to PLOOI and records the outcome.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */
class PlooiDeliveryService implements PlooiDeliveryServiceInterface {

	/**
	 * The publication properties this service writes.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['plooiStatus', 'plooiDeliveredAt', 'plooiIdentifier', 'plooiReason', 'plooiDocuments'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings, for the catalog register and schema and the object service.
	 * @param NationalIndexService $indexService The national channels.
	 * @param LoggerInterface $logger Logger.
	 * @param DryRunRecorder|null $dryRuns Holds the delivery as a dry run in staging (REQ-STG-002).
	 * @param PipelineIncidents|null $incidents Tells the incident group about a failure (REQ-WNO-004).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly NationalIndexService $indexService,
		private readonly LoggerInterface $logger,
		// Holds the delivery in staging (instance-staging-mode REQ-STG-002).
		// Nullable and last, so every existing construction site keeps working.
		private readonly ?DryRunRecorder $dryRuns = null,
		// Tells the incident group about a failed delivery (REQ-WNO-004). Nullable and last.
		private readonly ?PipelineIncidents $incidents = null,
	) {

	}//end __construct()

	/**
	 * Deliver one publication to PLOOI, deliver its update, or withdraw it.
	 *
	 * A delivery carries the documents PLOOI held from the last delivery that
	 * are no longer public as `withdrawnDocuments`, so a removed annex is
	 * withdrawn by the same call that sends the update (REQ-WNO-001). A
	 * withdrawal of the whole record goes through the PLOOI channel's
	 * withdrawal and leaves `plooiStatus` `withdrawn`.
	 *
	 * @param string $uuid     The publication.
	 * @param string $register Its register id.
	 * @param string $schema   Its schema id.
	 * @param bool   $withdraw True to withdraw the whole record.
	 *
	 * @return array<string, mixed>|null The PLOOI fields stored, the dry run in staging (nothing stored), or null when no catalogue asks for PLOOI.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-change-to-a-delivered-publication-is-delivered-again-req-wno-001
	 */
	public function deliver(string $uuid, string $register, string $schema, bool $withdraw=false): ?array {
		if ($this->isInPlooiCatalogue(register: $register, schema: $schema) === false) {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$entity = $objectService?->find(
			id: $uuid,
			options: new ObjectFindOptions(register: $register, schema: $schema),
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return null;
		}

		$publication = $entity->jsonSerialize();
		$document = $this->document(publication: $publication, uuid: $uuid);
		// In staging the document is kept as a dry run, and the publication is left as it is:
		// no plooiStatus and no plooiDeliveredAt claim a delivery that never happened.
		$held = $this->dryRuns?->hold(channel: 'plooi', payload: $document);
		if ($held !== null) {
			return $held;
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$fields = ['plooiDeliveredAt' => $now];

		try {
			if ($withdraw === true) {
				$reference = (string)($publication['plooiIdentifier'] ?? '');
				if ($reference === '') {
					$reference = $uuid;
				}

				$this->indexService->withdraw(
					channel: NationalIndexService::CHANNEL_PLOOI,
					publicationId: $reference,
					reason: 'The publication is no longer public.'
				);
				$fields['plooiStatus'] = 'withdrawn';
				$fields['plooiDocuments'] = [];
			} else {
				$result = $this->indexService->deliverToPlooi(document: $document);
				$fields['plooiStatus'] = 'delivered';
				$fields['plooiIdentifier'] = (string)($result['identifier'] ?? '');
				$fields['plooiDocuments'] = array_column($document['documents'], 'url');
			}

			$fields['plooiReason'] = '';
		} catch (IndexUnreachableException $e) {
			$fields['plooiStatus'] = 'failed';
			$fields['plooiReason'] = $e->getMessage();
			$this->logger->warning('[PlooiDeliveryService] PLOOI delivery of ' . $uuid . ' failed: ' . $e->getMessage());
			// The same kind, subject and message as deliverToPlooi() raises, so one failure is one incident.
			$this->incidents?->raise(
				kind: PipelineIncidents::KIND_PLOOI,
				subject: $uuid,
				message: PipelineIncidents::plooiFailure(
					title: (string)($publication['title'] ?? ''),
					reason: $e->getMessage(),
					withdrawal: $withdraw
				)
			);
		}

		$record = $publication;
		unset($record['@self']);
		$objectService->saveObject(
			object: array_merge($record, $fields),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $register, schema: $schema),
				uuid: $uuid,
				policy: new SavePolicy(silent: true)
			),
			_rbac: false,
			_multitenancy: false
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

		$current = array_column($links, 'url');
		$withdrawn = array_values(
			array_diff(array_map('strval', (array)($publication['plooiDocuments'] ?? [])), $current)
		);

		$document = [
			'identifier' => $uuid,
			'title' => (string)($publication['title'] ?? ''),
			'description' => (string)($publication['summary'] ?? ($publication['description'] ?? '')),
			'publicationDate' => (string)($publication['publicationDate'] ?? ''),
			'organisation' => (string)($publication['organization'] ?? ''),
			'documents' => $links,
		];
		if ($withdrawn !== []) {
			$document['withdrawnDocuments'] = $withdrawn;
		}

		return $document;

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
		foreach ($this->plooiCatalogues() as $catalog) {
			if ($this->covers(catalog: $catalog, register: $register, schema: $schema) === true) {
				return true;
			}
		}

		return false;
	}//end isInPlooiCatalogue()

	/**
	 * The catalogues that have PLOOI delivery on.
	 *
	 * @return array<int, mixed> The catalogues, as entities or arrays; empty when the catalog register is not set.
	 */
	private function plooiCatalogues(): array {
		$configuration = ($this->settingsService->getSettings()['configuration'] ?? []);
		$catalogRegister = (string)($configuration['catalog_register'] ?? '');
		$catalogSchema = (string)($configuration['catalog_schema'] ?? '');
		$objectService = $this->settingsService->getObjectService();
		if ($catalogRegister === '' || $catalogSchema === '' || $objectService === null) {
			return [];
		}

		$result = $objectService->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $catalogRegister, 'schema' => $catalogSchema],
				'plooiDelivery' => true,
			],
			_rbac: false,
			_multitenancy: false
		);

		return ($result['results'] ?? []);
	}//end plooiCatalogues()

	/**
	 * Whether one catalogue has PLOOI on and holds this register and schema.
	 *
	 * @param mixed $catalog The catalogue, as an entity or an array.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return bool True when it does.
	 */
	private function covers(mixed $catalog, string $register, string $schema): bool {
		if (is_object($catalog) === true && method_exists($catalog, 'jsonSerialize') === true) {
			$catalog = $catalog->jsonSerialize();
		}

		if (is_array($catalog) === false || ($catalog['plooiDelivery'] ?? false) !== true) {
			return false;
		}

		$registers = array_map('strval', (array)($catalog['registers'] ?? []));
		$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));
		return in_array($register, $registers, true) === true && in_array($schema, $schemas, true) === true;
	}//end covers()
}//end class
