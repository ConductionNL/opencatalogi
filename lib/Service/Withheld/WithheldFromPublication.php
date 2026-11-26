<?php

/**
 * The public `withheld` list of a publication: generic (decision 182), for
 * any publication a catalogue holds; the Woo decision is the first user.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A catalogue that opted in (`showWithheld`, REQ-PDP-004) shows on a public
 * publication which of its documents were withheld from publication and on
 * which grounds: one entry per withheld assessment of the publication batch
 * (`wooBatch`, the existing Woo workflow) it was made from, and one per
 * `withheldDocument` record an app wrote (REQ-WDW-003), ordered by
 * position. Each entry carries `position`, `grounds` (codes) and
 * `groundDetails` (`{code, article, label}`); a batch entry also carries its
 * `title` when the assessment's `titlePublic` is true. Nothing else.
 *
 * Absent, never empty, when the catalogue did not opt in, cannot be resolved,
 * the list cannot be read, or there is nothing to list: an empty list would say
 * nothing was withheld, which nothing recorded cannot tell.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Withheld
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Withheld;

use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the public `withheld` list and adds it to a publication response.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */
class WithheldFromPublication implements WithheldFromPublicationInterface {

	/**
	 * Constructor.
	 *
	 * @param WithheldFromPublicationStore $store         The OpenRegister reads.
	 * @param LoggerInterface              $logger        Records an unreadable list.
	 * @param RefusalGrounds|null          $refusalGrounds Resolves a batch entry's codes; none leaves them unresolved.
	 */
	public function __construct(
		private readonly WithheldFromPublicationStore $store,
		private readonly LoggerInterface $logger,
		private readonly ?RefusalGrounds $refusalGrounds = null,
	) {
	}//end __construct()

	/**
	 * The response with `withheld` added when the catalogue opted in, unchanged otherwise.
	 *
	 * @param array<string, mixed> $response The rendered publication.
	 * @param array<string, mixed> $catalog The catalogue it was read through.
	 * @param string $publicationId The publication.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-24-publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
	 */
	public function addForCatalog(array $response, array $catalog, string $publicationId): array {
		if ($this->optedIn(catalog: $catalog) === false) {
			return $response;
		}

		return $this->withList(response: $response, publicationId: $publicationId);
	}//end addForCatalog()

	/**
	 * The response with `withheld` added when every catalogue holding the publication opted in.
	 *
	 * For a read without a catalogue (the federation endpoint). The catalogue is resolved from the
	 * publication's register and schema; none found means unresolved, and so no key.
	 *
	 * @param array<string, mixed> $response The rendered publication, with `@self`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function addForPublication(array $response): array {
		$self = (array)($response['@self'] ?? []);
		$publicationId = (string)($response['id'] ?? ($self['id'] ?? ''));
		$catalogs = [];
		if ($publicationId !== '') {
			$catalogs = $this->holdingCatalogs(self: $self);
		}

		if ($this->allOptedIn(catalogs: $catalogs) === false) {
			return $response;
		}

		return $this->withList(response: $response, publicationId: $publicationId);
	}//end addForPublication()

	/**
	 * The catalogues holding a publication's register and schema; none when
	 * either is missing or the catalogues cannot be read.
	 *
	 * @param array<string, mixed> $self The publication's `@self`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function holdingCatalogs(array $self): array {
		$register = $this->referenceId(value: ($self['register'] ?? ''));
		$schema = $this->referenceId(value: ($self['schema'] ?? ''));
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->store->catalogsHolding(register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('WithheldFromPublication: the catalogues could not be read', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end holdingCatalogs()

	/**
	 * Whether there is a holding catalogue and every one of them opted in.
	 *
	 * @param array<int, array<string, mixed>> $catalogs The holding catalogues.
	 *
	 * @return bool
	 */
	private function allOptedIn(array $catalogs): bool {
		if ($catalogs === []) {
			return false;
		}

		foreach ($catalogs as $catalog) {
			if ($this->optedIn(catalog: $catalog) === false) {
				return false;
			}
		}

		return true;
	}//end allOptedIn()

	/**
	 * The merged list, ordered by position.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function entries(string $publicationId): array {
		$assessments = $this->store->batchAssessments(publicationId: $publicationId);
		$entries = array_merge(
			$this->batchEntries(assessments: $assessments, grounds: $this->groundsByCode(assessments: $assessments)),
			$this->storedEntries(rows: $this->store->storedEntries(publicationId: $publicationId))
		);
		usort($entries, static fn (array $one, array $two): int => $one['position'] <=> $two['position']);

		return $entries;
	}//end entries()

	/**
	 * Add the list, or leave the key out when it cannot be read or holds nothing.
	 *
	 * An empty list is never added: nothing recorded (a Woo decision whose `record()` has not
	 * run yet, or was refused) cannot be told from nothing withheld, and `withheld: []` would
	 * state the second.
	 *
	 * @param array<string, mixed> $response The rendered publication.
	 * @param string $publicationId The publication.
	 *
	 * @return array<string, mixed>
	 */
	private function withList(array $response, string $publicationId): array {
		try {
			$entries = $this->entries(publicationId: $publicationId);
			if ($entries !== []) {
				$response['withheld'] = $entries;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'WithheldFromPublication: the withheld list could not be read',
				['publication' => $publicationId, 'exception' => $e->getMessage()]
			);
		}

		return $response;
	}//end withList()

	/**
	 * The id of a `@self` register or schema, which an `_extend` turns from an id into an object.
	 *
	 * @param mixed $value The id, or the extended object holding it.
	 *
	 * @return string The id, or '' when there is none.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? '');
		}

		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end referenceId()

	/**
	 * Whether a catalogue opted in. Only a literal true counts.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return bool
	 */
	private function optedIn(array $catalog): bool {
		return ($catalog['showWithheld'] ?? false) === true;
	}//end optedIn()

	/**
	 * One entry per withheld assessment; position is the document's place in the batch, from 1.
	 *
	 * @param list<array<string, mixed>>                                 $assessments The batch's assessments in order.
	 * @param array<string, array{code: string, article: string, label: string}> $grounds     The resolvable grounds by code.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function batchEntries(array $assessments, array $grounds): array {
		$entries = [];
		foreach ($assessments as $index => $assessment) {
			if ((string)($assessment['assessment'] ?? '') !== 'niet_openbaar') {
				continue;
			}

			$entries[] = $this->batchEntry(position: ($index + 1), assessment: $assessment, grounds: $grounds);
		}

		return $entries;
	}//end batchEntries()

	/**
	 * The refusal grounds by code, read once for a publication, and only when a batch entry needs them.
	 *
	 * Read through `RefusalGrounds` (dossiq's list, or the vendored snapshot without dossiq).
	 * When there is no reader or the list cannot be read, nothing resolves: every batch code is
	 * then answered with empty `article` and `label`, never a guessed label, and the rest of the
	 * withheld list still reads.
	 *
	 * @param list<array<string, mixed>> $assessments The batch's assessments.
	 *
	 * @return array<string, array{code: string, article: string, label: string}>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	private function groundsByCode(array $assessments): array {
		if ($this->refusalGrounds === null || $this->withholdsAny(assessments: $assessments) === false) {
			return [];
		}

		try {
			$list = $this->refusalGrounds->list();
		} catch (Throwable $e) {
			$this->logger->warning(
				'WithheldFromPublication: the refusal grounds could not be read, so the batch grounds are answered without labels',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$byCode = [];
		foreach ($list as $ground) {
			$code = (string)($ground['code'] ?? '');
			$label = (string)($ground['label'] ?? '');
			if ($code === '' || $label === '') {
				continue;
			}

			$byCode[$code] ??= ['code' => $code, 'article' => (string)($ground['article'] ?? ''), 'label' => $label];
		}

		return $byCode;
	}//end groundsByCode()

	/**
	 * Whether any assessment of the batch withheld its document.
	 *
	 * @param list<array<string, mixed>> $assessments The batch's assessments.
	 *
	 * @return bool
	 */
	private function withholdsAny(array $assessments): bool {
		foreach ($assessments as $assessment) {
			if ((string)($assessment['assessment'] ?? '') === 'niet_openbaar') {
				return true;
			}
		}

		return false;
	}//end withholdsAny()

	/**
	 * One withheld assessment's entry, with its title only when the title is
	 * public (a literal true) and there is a file name.
	 *
	 * `groundDetails` gives each code the article and label `RefusalGrounds` holds for it, and
	 * a code it does not hold its code with empty `article` and `label`.
	 *
	 * @param int                                                          $position   The document's place in the batch, from 1.
	 * @param array<string, mixed>                                         $assessment The assessment.
	 * @param array<string, array{code: string, article: string, label: string}> $grounds    The resolvable grounds by code.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	private function batchEntry(int $position, array $assessment, array $grounds): array {
		$codes = array_values(array_map('strval', (array)($assessment['weigeringsgronden'] ?? [])));
		$entry = [
			'position' => $position,
			'grounds' => $codes,
			'groundDetails' => array_map(
				static fn (string $code): array => ($grounds[$code] ?? ['code' => $code, 'article' => '', 'label' => '']),
				$codes
			),
		];
		if (($assessment['titlePublic'] ?? false) === true && (string)($assessment['fileName'] ?? '') !== '') {
			$entry['title'] = (string)$assessment['fileName'];
		}

		return $entry;
	}//end batchEntry()

	/**
	 * One entry per stored row, with the details stored at recording time and no title.
	 *
	 * @param list<array<string, mixed>> $rows The `withheldDocument` rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function storedEntries(array $rows): array {
		$entries = [];
		foreach ($rows as $row) {
			$details = $this->groundDetails(grounds: ($row['grounds'] ?? []));
			$entries[] = [
				'position' => (int)($row['position'] ?? 0),
				'grounds' => array_column($details, 'code'),
				'groundDetails' => $details,
			];
		}

		return $entries;
	}//end storedEntries()

	/**
	 * The code, article and label of every ground stored on a row.
	 *
	 * @param mixed $grounds The row's grounds.
	 *
	 * @return list<array{code: string, article: string, label: string}>
	 */
	private function groundDetails(mixed $grounds): array {
		$details = [];
		foreach ((array)$grounds as $ground) {
			$details[] = [
				'code' => (string)($ground['code'] ?? ''),
				'article' => (string)($ground['article'] ?? ''),
				'label' => (string)($ground['label'] ?? ''),
			];
		}

		return $details;
	}//end groundDetails()
}//end class
