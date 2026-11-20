<?php

/**
 * OpenCatalogi Map Stored Refusal Grounds repair step.
 *
 * Maps the refusal grounds stored on `wooAssessment` objects from
 * OpenCatalogi's earlier list of codes to dossiq's settled codes (REQ-WVC-003).
 * Five of the earlier codes named a different ground than the settled code of
 * the same spelling, because the settled list numbers art. 5.1 lid 2 from the
 * statute as it stands; those are mapped by meaning. A code that does not map
 * unambiguously is left as it is, flagged on the assessment and listed in the
 * output. Nothing is guessed. Each mapped assessment is marked with the
 * settled list's version, so a second run changes nothing.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Maps OpenCatalogi's earlier refusal ground codes to dossiq's settled codes, once.
 *
 * @spec openspec/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
 */
class MapStoredRefusalGrounds implements IRepairStep {

	/**
	 * The earlier codes whose settled code is spelled differently, by meaning.
	 *
	 * Earlier list (label) to settled list (label):
	 * 5.1.2.f Bescherming van het milieu -> 5.1.2.g Bescherming van het milieu;
	 * 5.1.2.g Beveiliging van personen en bedrijven -> 5.1.2.h the same;
	 * 5.1.2.h Het goed functioneren van de Staat -> 5.1.2.i the same;
	 * 5.1.2.i Geadresseerde neemt eerst kennis -> 5.1.4 the same;
	 * 5.2.a Persoonlijke beleidsopvattingen in documenten voor intern beraad -> 5.2.1 the same.
	 * The other earlier codes (5.1.1.a-d, 5.1.2.a-e) name the same ground as
	 * the settled code of the same spelling and stay.
	 */
	public const EARLIER_TO_SETTLED = [
		'5.1.2.f' => '5.1.2.g',
		'5.1.2.g' => '5.1.2.h',
		'5.1.2.h' => '5.1.2.i',
		'5.1.2.i' => '5.1.4',
		'5.2.a' => '5.2.1',
	];

	/**
	 * Earlier codes that stay as spelled.
	 */
	public const EARLIER_UNCHANGED = [
		'5.1.1.a',
		'5.1.1.b',
		'5.1.1.c',
		'5.1.1.d',
		'5.1.2.a',
		'5.1.2.b',
		'5.1.2.c',
		'5.1.2.d',
		'5.1.2.e',
	];

	/**
	 * Assessments read per page.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * At most this many pages, so a store that never ends a page stops.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param TransparencyWorkflowServiceInterface $woo    The Woo register, schema and object service.
	 * @param LoggerInterface                      $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TransparencyWorkflowServiceInterface $woo,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function getName(): string {
		return 'Map the refusal grounds stored on Woo assessments to the settled list';
	}//end getName()

	/**
	 * Map every unmarked assessment that cites a ground.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function run(IOutput $output): void {
		$objects = $this->woo->getObjectService();
		$register = $this->woo->getRegister();
		$schema = $this->woo->getAssessmentSchema();
		if ($objects === null || $register === null || $schema === null) {
			$output->info('MapStoredRefusalGrounds: the Woo assessment schema is not configured; nothing to do.');
			return;
		}

		$tally = ['mapped' => 0, 'failed' => 0, 'unmapped' => []];
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			try {
				$rows = (array)($objects->searchObjectsPaginated(
					query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE_SIZE, '_page' => $page],
					_rbac: false,
					_multitenancy: false
				)['results'] ?? []);
			} catch (Throwable $e) {
				$this->logger->error('MapStoredRefusalGrounds: reading assessments failed', ['exception' => $e->getMessage()]);
				$output->warning('MapStoredRefusalGrounds: reading assessments failed; nothing more was mapped.');
				break;
			}

			foreach ($rows as $row) {
				$tally = $this->mapOne(
					objects: $objects,
					target: new ObjectFindTarget(register: $register, schema: $schema),
					assessment: self::asArray(row: $row),
					tally: $tally
				);
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		$output->info(
			'MapStoredRefusalGrounds: ' . $tally['mapped'] . ' assessment(s) mapped, '
			. count($tally['unmapped']) . ' with a code that could not be mapped, '
			. $tally['failed'] . ' could not be saved.'
		);
		foreach ($tally['unmapped'] as $uuid => $codes) {
			$output->warning('MapStoredRefusalGrounds: assessment ' . $uuid . ' keeps unmapped code(s) ' . implode(', ', $codes));
		}
	}//end run()

	/**
	 * The settled codes for a list of earlier codes, and the codes that do not map.
	 *
	 * @param array<int, string> $codes The stored codes.
	 *
	 * @return array{mapped: array<int, string>, unmapped: array<int, string>} The result.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public static function map(array $codes): array {
		$mapped = [];
		$unmapped = [];
		foreach ($codes as $code) {
			$code = trim((string)$code);
			if (isset(self::EARLIER_TO_SETTLED[$code]) === true) {
				$mapped[] = self::EARLIER_TO_SETTLED[$code];
				continue;
			}

			if (in_array($code, self::EARLIER_UNCHANGED, true) === false) {
				// Kept as stored: a code without one meaning is never guessed.
				$unmapped[] = $code;
			}

			$mapped[] = $code;
		}

		return ['mapped' => array_values(array_unique($mapped)), 'unmapped' => $unmapped];
	}//end map()

	/**
	 * Map one assessment when it is unmarked and cites a ground.
	 *
	 * @param object                                                         $objects    OpenRegister's object service.
	 * @param ObjectFindTarget                                               $target     The register and schema.
	 * @param array<string, mixed>                                           $assessment The assessment.
	 * @param array{mapped: int, failed: int, unmapped: array<string, array>} $tally     The running tally.
	 *
	 * @return array{mapped: int, failed: int, unmapped: array<string, array>} The tally.
	 */
	private function mapOne(object $objects, ObjectFindTarget $target, array $assessment, array $tally): array {
		$grounds = $assessment['weigeringsgronden'] ?? [];
		if (is_array($grounds) === false || $grounds === []
			|| (string)($assessment['groundsListVersion'] ?? '') !== ''
		) {
			return $tally;
		}

		$uuid = (string)($assessment['id'] ?? ($assessment['@self']['id'] ?? ''));
		$result = self::map(codes: $grounds);
		unset($assessment['@self']);
		$assessment['weigeringsgronden'] = $result['mapped'];
		$assessment['unmappedGrounds'] = $result['unmapped'];
		$assessment['groundsListVersion'] = RefusalGrounds::SETTLED_LIST_VERSION;

		try {
			$objects->saveObject(object: $assessment, options: new ObjectSaveOptions(target: $target), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error('MapStoredRefusalGrounds: saving an assessment failed', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
			$tally['failed']++;
			return $tally;
		}

		$tally['mapped']++;
		if ($result['unmapped'] !== []) {
			$tally['unmapped'][$uuid] = $result['unmapped'];
		}

		return $tally;
	}//end mapOne()

	/**
	 * A search row as a plain array.
	 *
	 * @param mixed $row The row: an array, or an entity with jsonSerialize().
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end asArray()
}//end class
