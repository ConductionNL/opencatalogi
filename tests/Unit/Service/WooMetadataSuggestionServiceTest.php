<?php

/**
 * The rules floor of the Woo metadata suggestions (woo-metadata-suggestions
 * REQ-WMS-001): one case per field, one for an unresolvable value, and one for a
 * field already set. The TOOI value lists are the real service; only the
 * category registry, which reads OpenRegister, is a double.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\WooMetadataSuggestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WooMetadataSuggestionService::suggestFromRules().
 */
#[CoversClass(WooMetadataSuggestionService::class)]
#[UsesClass(TooiVocabularyService::class)]
class WooMetadataSuggestionServiceTest extends TestCase {

	private const ORG_TOOI = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0363';

	/**
	 * The service over the real value lists and a registry of the given categories.
	 *
	 * @param array<string, array<string, mixed>> $categories Code to record, each with `schemas`.
	 *
	 * @return WooMetadataSuggestionService
	 */
	private function service(array $categories=[]): WooMetadataSuggestionService {
		$tooi = new TooiVocabularyService();
		$records = [];
		foreach ($tooi->informatiecategorieList() as $code => $member) {
			$records[$code] = [
				'code' => $code,
				'label' => $member['label'],
				'tooiUri' => $member['uri'],
				'schemas' => [],
			];
		}

		foreach ($categories as $code => $record) {
			$records[$code] = array_merge(($records[$code] ?? ['code' => $code, 'label' => $code, 'tooiUri' => '']), $record);
		}

		$registry = $this->createMock(InformationCategoryRegistryInterface::class);
		$registry->method('all')->willReturn($records);
		$registry->method('schemasFor')->willReturnCallback(
			static fn (string $code): array => (array)($records[$code]['schemas'] ?? [])
		);

		return new WooMetadataSuggestionService(tooiVocabulary: $tooi, categories: $registry);
	}

	/**
	 * A record synchronised into a schema only one category publishes gets that
	 * category, labelled and with the schema named in the reason.
	 *
	 * @return void
	 */
	public function testTheOnlyCategoryOfTheSchemaIsProposed(): void {
		$code = array_key_first((new TooiVocabularyService())->informatiecategorieList());
		$proposals = $this->service([$code => ['schemas' => ['besluit']]])->suggestFromRules(
			publication: ['title' => 'Besluit'],
			context: ['schemaSlug' => 'besluit']
		);

		$this->assertCount(expectedCount: 1, haystack: $proposals);
		$this->assertSame(expected: 'wooCategory', actual: $proposals[0]['field']);
		$this->assertSame(expected: $code, actual: $proposals[0]['value']);
		$this->assertSame(expected: 'rules', actual: $proposals[0]['source']);
		$this->assertStringContainsString(needle: '"besluit"', haystack: $proposals[0]['reason']);
		$this->assertNotSame(expected: '', actual: $proposals[0]['valueLabel']);
	}

	/**
	 * Two categories publishing the schema, or none, propose nothing.
	 *
	 * @return void
	 */
	public function testAnAmbiguousOrUnknownSchemaProposesNoCategory(): void {
		$codes = array_slice(array_keys((new TooiVocabularyService())->informatiecategorieList()), 0, 2);
		$service = $this->service([$codes[0] => ['schemas' => ['besluit']], $codes[1] => ['schemas' => ['besluit']]]);

		$this->assertSame(expected: [], actual: $service->suggestFromRules(publication: [], context: ['schemaSlug' => 'besluit']));
		$this->assertSame(expected: [], actual: $service->suggestFromRules(publication: [], context: ['schemaSlug' => 'other']));
		$this->assertSame(expected: [], actual: $service->suggestFromRules(publication: [], context: []));
	}

	/**
	 * A local category whose TOOI uri does not resolve is not proposed.
	 *
	 * @return void
	 */
	public function testAnUnresolvableValueIsNotProposed(): void {
		$service = $this->service(['lokaal-x' => ['label' => 'Local', 'tooiUri' => 'https://example.org/not-a-tooi-member', 'schemas' => ['besluit']]]);

		$this->assertSame(expected: [], actual: $service->suggestFromRules(publication: [], context: ['schemaSlug' => 'besluit']));

		$this->assertSame(
			expected: [],
			actual: $service->suggestFromRules(
				publication: [],
				context: ['catalogOrganization' => ['id' => 'org-1', 'name' => 'Org', 'tooiIdentifier' => 'not-a-tooi-id']]
			)
		);
	}

	/**
	 * The catalogue's organisation is proposed when it carries a TOOI identifier.
	 *
	 * @return void
	 */
	public function testTheCataloguesOrganisationIsProposed(): void {
		$proposals = $this->service()->suggestFromRules(
			publication: ['organization' => ''],
			context: ['catalogOrganization' => ['id' => 'org-1', 'name' => 'Gemeente Amsterdam', 'tooiIdentifier' => self::ORG_TOOI]]
		);

		$this->assertSame(
			expected: [
				[
					'field' => 'organization',
					'value' => 'org-1',
					'valueLabel' => 'Gemeente Amsterdam',
					'source' => 'rules',
					'reason' => 'The catalogue is published by "Gemeente Amsterdam" (' . self::ORG_TOOI . ').',
				],
			],
			actual: $proposals
		);
	}

	/**
	 * How the record arrived decides the handling type.
	 *
	 * @return void
	 */
	public function testTheHandlingTypeFollowsHowTheRecordArrived(): void {
		$service = $this->service();

		$synchronised = $service->suggestFromRules(publication: [], context: ['origin' => 'synchronisation']);
		$this->assertSame(expected: 'soortHandeling', actual: $synchronised[0]['field']);
		$this->assertSame(expected: 'ontvangst', actual: $synchronised[0]['value']);

		$handedOver = $service->suggestFromRules(publication: [], context: ['origin' => 'case']);
		$this->assertSame(expected: 'vaststelling', actual: $handedOver[0]['value']);
		$this->assertStringContainsString(needle: 'case application', haystack: $handedOver[0]['reason']);

		$this->assertSame(expected: [], actual: $service->suggestFromRules(publication: [], context: ['origin' => 'manual']));
	}

	/**
	 * A field an editor already set gets no proposal.
	 *
	 * @return void
	 */
	public function testAFieldAlreadySetGetsNoProposal(): void {
		$code = array_key_first((new TooiVocabularyService())->informatiecategorieList());
		$proposals = $this->service([$code => ['schemas' => ['besluit']]])->suggestFromRules(
			publication: ['soortHandeling' => 'vaststelling', 'wooCategory' => $code, 'organization' => 'org-9'],
			context: [
				'schemaSlug' => 'besluit',
				'origin' => 'synchronisation',
				'catalogOrganization' => ['id' => 'org-1', 'name' => 'Org', 'tooiIdentifier' => self::ORG_TOOI],
			]
		);

		$this->assertSame(expected: [], actual: $proposals);
	}

	/**
	 * A field and value a suggestion already exists for is not proposed twice.
	 *
	 * @return void
	 */
	public function testAnExistingSuggestionIsNotRepeated(): void {
		$proposals = $this->service()->suggestFromRules(
			publication: [],
			context: [
				'origin' => 'synchronisation',
				'catalogOrganization' => ['id' => 'org-1', 'name' => 'Org', 'tooiIdentifier' => self::ORG_TOOI],
				'existing' => [['field' => 'soortHandeling', 'value' => 'ontvangst']],
			]
		);

		$this->assertSame(expected: ['organization'], actual: array_column($proposals, 'field'));
	}
}
