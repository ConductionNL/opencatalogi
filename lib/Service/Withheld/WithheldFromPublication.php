<?php

/**
 * The public `withheld` list of a publication: generic (decision 182), for
 * any publication a catalogue holds; the Woo decision is the first user.
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
 * or the list cannot be read: an empty list would say nothing was withheld.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Withheld;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the public `withheld` list and adds it to a publication response.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */
class WithheldFromPublication {

	/**
	 * Constructor.
	 *
	 * @param WithheldFromPublicationStore $store  The OpenRegister reads.
	 * @param LoggerInterface     $logger Records an unreadable list.
	 */
	public function __construct(
		private readonly WithheldFromPublicationStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The response with `withheld` added when the catalogue opted in, unchanged otherwise.
	 *
	 * @param array<string, mixed> $response      The rendered publication.
	 * @param array<string, mixed> $catalog       The catalogue it was read through.
	 * @param string               $publicationId The publication.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
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
		$register = (string)($self['register'] ?? '');
		$schema = (string)($self['schema'] ?? '');
		if ($publicationId === '' || $register === '' || $schema === '') {
			return $response;
		}

		try {
			$catalogs = $this->store->catalogsHolding(register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('WithheldFromPublication: the catalogues could not be read', ['exception' => $e->getMessage()]);
			return $response;
		}

		if ($catalogs === []) {
			return $response;
		}

		foreach ($catalogs as $catalog) {
			if ($this->optedIn(catalog: $catalog) === false) {
				return $response;
			}
		}

		return $this->withList(response: $response, publicationId: $publicationId);
	}//end addForPublication()

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
		$entries = array_merge(
			$this->batchEntries(assessments: $this->store->batchAssessments(publicationId: $publicationId)),
			$this->storedEntries(rows: $this->store->storedEntries(publicationId: $publicationId))
		);
		usort($entries, static fn (array $one, array $two): int => $one['position'] <=> $two['position']);

		return $entries;
	}//end entries()

	/**
	 * Add the list, or leave the key out when it cannot be read.
	 *
	 * @param array<string, mixed> $response      The rendered publication.
	 * @param string               $publicationId The publication.
	 *
	 * @return array<string, mixed>
	 */
	private function withList(array $response, string $publicationId): array {
		try {
			$response['withheld'] = $this->entries(publicationId: $publicationId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WithheldFromPublication: the withheld list could not be read',
				['publication' => $publicationId, 'exception' => $e->getMessage()]
			);
		}

		return $response;
	}//end withList()

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
	 * `groundDetails` answers each code with empty `article` and `label`: resolving them is
	 * `RefusalGrounds`' (woo-value-lists-on-the-concept-register), and an unresolved code is never
	 * given a guessed label.
	 *
	 * @param list<array<string, mixed>> $assessments The batch's assessments in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function batchEntries(array $assessments): array {
		$entries = [];
		foreach ($assessments as $index => $assessment) {
			if ((string)($assessment['assessment'] ?? '') !== 'niet_openbaar') {
				continue;
			}

			$codes = array_values(array_map('strval', (array)($assessment['weigeringsgronden'] ?? [])));
			$entry = [
				'position' => ($index + 1),
				'grounds' => $codes,
				'groundDetails' => array_map(static fn (string $code): array => ['code' => $code, 'article' => '', 'label' => ''], $codes),
			];
			if (($assessment['titlePublic'] ?? false) === true && (string)($assessment['fileName'] ?? '') !== '') {
				$entry['title'] = (string)$assessment['fileName'];
			}

			$entries[] = $entry;
		}

		return $entries;
	}//end batchEntries()

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
			$details = [];
			foreach ((array)($row['grounds'] ?? []) as $ground) {
				$details[] = [
					'code' => (string)($ground['code'] ?? ''),
					'article' => (string)($ground['article'] ?? ''),
					'label' => (string)($ground['label'] ?? ''),
				];
			}

			$entries[] = [
				'position' => (int)($row['position'] ?? 0),
				'grounds' => array_column($details, 'code'),
				'groundDetails' => $details,
			];
		}

		return $entries;
	}//end storedEntries()
}//end class
