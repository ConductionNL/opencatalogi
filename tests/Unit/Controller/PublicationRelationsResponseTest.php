<?php

/**
 * Unit tests for the relations a public publication answer carries.
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
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Service\Publication\PublicationRelations;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-PRS-002: `relations` read from OpenRegister's uses and used rows, only what the public read path returns.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationRelations
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationLinkRules
 */
class PublicationRelationsResponseTest extends TestCase {

	private const A = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const B = 'bbbbbbbb-0000-4000-8000-000000000002';

	private PublicationQueryServiceInterface&MockObject $queryService;

	private ObjectService&MockObject $objectService;

	private bool $anonymous = true;

	protected function setUp(): void {
		$this->queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$this->queryService->method('applyReadRuleGuardToRows')->willReturnArgument(0);
		$this->queryService->method('isAnonymous')->willReturnCallback(fn (): bool => $this->anonymous);
		$this->queryService->method('isObjectPublic')->willReturnCallback(
			static fn (array $objectData): bool => ($objectData['publicationDate'] ?? null) !== null
		);
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * B replaces A: B reads "vervangt A" outgoing, A reads "wordt vervangen door B" incoming.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testBothSidesReadTheRelation(): void {
		$this->objectService->method('getObjectUses')->willReturnCallback(
			fn (string $objectId): array => ['results' => ($objectId === self::B ? [$this->row(id: self::A, title: 'Parkeerbesluit 2024', label: 'vervangt', direction: 'outgoing')] : [])]
		);
		$this->objectService->method('getObjectUsedBy')->willReturnCallback(
			fn (string $objectId): array => ['results' => ($objectId === self::A ? [$this->row(id: self::B, title: 'Parkeerbesluit 2027', label: 'wordt vervangen door', direction: 'incoming')] : [])]
		);

		$this->assertSame(
			[[
				'type' => 'replaces',
				'label' => 'vervangt',
				'direction' => 'outgoing',
				'id' => self::A,
				'title' => 'Parkeerbesluit 2024',
				'url' => 'https://example.nl/index.php/apps/opencatalogi/api/woo/' . self::A,
			]],
			$this->relationsOf(id: self::B)
		);
		$this->assertSame(
			[[
				'type' => 'replaces',
				'label' => 'wordt vervangen door',
				'direction' => 'incoming',
				'id' => self::B,
				'title' => 'Parkeerbesluit 2027',
				'url' => 'https://example.nl/index.php/apps/opencatalogi/api/woo/' . self::B,
			]],
			$this->relationsOf(id: self::A)
		);
	}//end testBothSidesReadTheRelation()

	/**
	 * A public publication that amends a draft: an anonymous reader gets no relation naming the draft.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testARelationToANonPublicPublicationIsLeftOut(): void {
		$this->objectService->method('getObjectUses')->willReturn(
			['results' => [$this->row(id: self::A, title: 'Concept', label: 'wijzigt', direction: 'outgoing', property: 'amends', published: false)]]
		);
		$this->objectService->method('getObjectUsedBy')->willReturn(['results' => []]);

		$relations = $this->relationsOf(id: self::B);

		$this->assertSame([], $relations);
		$this->assertStringNotContainsString(self::A, (string)json_encode($relations));
	}//end testARelationToANonPublicPublicationIsLeftOut()

	/**
	 * A logged-in caller's rows are already filtered by OpenRegister RBAC, so the published predicate is not applied again.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testALoggedInCallerKeepsWhatRbacReturned(): void {
		$this->anonymous = false;
		$this->objectService->method('getObjectUses')->willReturn(
			['results' => [$this->row(id: self::A, title: 'Concept', label: 'wijzigt', direction: 'outgoing', property: 'amends', published: false)]]
		);
		$this->objectService->method('getObjectUsedBy')->willReturn(['results' => []]);

		$this->assertSame([self::A], array_column($this->relationsOf(id: self::B), 'id'));
	}//end testALoggedInCallerKeepsWhatRbacReturned()

	/**
	 * Rows outside the catalogue, rows of other properties, rows without a relation block, and the publication itself are left out.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testOnlyPublicationRelationsInsideTheCatalogueCount(): void {
		$outside = $this->row(id: 'c', title: 'Elders', label: 'vervangt', direction: 'outgoing');
		$outside['@self']['schema'] = 99;
		$otherProperty = $this->row(id: 'd', title: 'Thema', label: 'thema', direction: 'outgoing', property: 'themes');
		$noBlock = $this->row(id: 'e', title: 'Los', label: '', direction: 'outgoing');
		unset($noBlock['relation']);
		$itself = $this->row(id: self::B, title: 'Zelf', label: 'hoort bij', direction: 'incoming', property: 'relatedTo');
		$kept = $this->row(id: 'f', title: 'Hoort erbij', label: 'hoort bij', direction: 'incoming', property: 'relatedTo');
		$this->objectService->method('getObjectUses')->willReturn(['results' => [$outside, $otherProperty, $noBlock, 'not a row']]);
		$this->objectService->method('getObjectUsedBy')->willReturn(['results' => [$itself, $kept]]);

		$this->assertSame(['f'], array_column($this->relationsOf(id: self::B), 'id'));
	}//end testOnlyPublicationRelationsInsideTheCatalogueCount()

	/**
	 * Both lookups run with RBAC on and their rows pass the read-rule guard; a row the guard drops is not shown.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testTheLookupsRunWithRbacAndTheRowsPassTheGuard(): void {
		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->expects($this->exactly(2))->method('applyReadRuleGuardToRows')->willReturn(['results' => []]);
		$queryService->method('isAnonymous')->willReturn(true);
		$this->objectService->expects($this->once())->method('getObjectUses')
			->with(self::B, ['_limit' => 100, '_offset' => 0], true, true)
			->willReturn(['results' => [$this->row(id: self::A, title: 'T', label: 'vervangt', direction: 'outgoing')]]);
		$this->objectService->expects($this->once())->method('getObjectUsedBy')
			->with(self::B, ['_limit' => 100, '_offset' => 0], true, true)
			->willReturn(['results' => []]);

		$this->assertSame([], $this->relationsOf(id: self::B, queryService: $queryService));
	}//end testTheLookupsRunWithRbacAndTheRowsPassTheGuard()

	/**
	 * A failing lookup gives no relations, not a partial list.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function testAFailingLookupGivesNoRelations(): void {
		$this->objectService->method('getObjectUses')->willReturn(['results' => [$this->row(id: self::A, title: 'T', label: 'vervangt', direction: 'outgoing')]]);
		$this->objectService->method('getObjectUsedBy')->willThrowException(new RuntimeException('down'));

		$this->assertSame([], $this->relationsOf(id: self::B));
	}//end testAFailingLookupGivesNoRelations()

	/**
	 * The relations of one publication in catalogue `woo` (registers 1, schemas 3).
	 *
	 * @param string $id The publication.
	 * @param PublicationQueryServiceInterface|null $queryService Another query service double.
	 *
	 * @return array<int, array<string, mixed>> The relations.
	 */
	private function relationsOf(string $id, ?PublicationQueryServiceInterface $queryService = null): array {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://example.nl' . $path);

		return (new PublicationRelations(queryService: ($queryService ?? $this->queryService), urlGenerator: $urls))
			->forPublication(objectService: $this->objectService, publicationId: $id, registers: [1], schemas: [3], catalogSlug: 'woo');
	}//end relationsOf()

	/**
	 * One uses/used row as OpenRegister answers it.
	 *
	 * @param string $id The related object's uuid.
	 * @param string $title Its title.
	 * @param string $label The label for the direction read.
	 * @param string $direction outgoing or incoming.
	 * @param string $property The declaring property.
	 * @param bool $published Whether it is published now.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $id, string $title, string $label, string $direction, string $property = 'replaces', bool $published = true): array {
		return [
			'id' => $id,
			'title' => $title,
			'publicationDate' => ($published === true ? '2026-01-01T00:00:00+00:00' : null),
			'@self' => ['id' => $id, 'register' => 1, 'schema' => 3],
			'relation' => ['property' => $property, 'type' => $property, 'direction' => $direction, 'displayLabel' => $label],
		];
	}//end row()
}//end class
