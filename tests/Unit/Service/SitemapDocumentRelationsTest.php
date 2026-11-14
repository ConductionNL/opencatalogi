<?php

/**
 * Tests for SitemapDocumentRelations.
 *
 * @category Test
 * @package  Unit\Service
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
 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md
 */

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SitemapDocumentRelations;
use PHPUnit\Framework\TestCase;

/**
 * Which documents of one publication say they belong together.
 *
 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md
 */
class SitemapDocumentRelationsTest extends TestCase {

	private const URL = 'https://example.com/apps/opencatalogi/api/woo/pub-1';

	/**
	 * A file at a download URL.
	 *
	 * @param string $name The file name.
	 * @param array<int, string> $labels Its labels.
	 *
	 * @return array<string, mixed>
	 */
	private static function file(string $name, array $labels = []): array {
		return ['downloadUrl' => 'https://example.com/' . $name, 'title' => $name, 'labels' => $labels];
	}

	/**
	 * A single document carries no relation.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	public function testASingleDocumentHasNoRelation(): void {
		$this->assertSame([], SitemapDocumentRelations::forFiles(files: [self::file('a.pdf', ['main-document'])], publicationUrl: self::URL, publicationTitle: 'Besluit'));
	}

	/**
	 * Three files and no main document: each is part of the publication.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	public function testWithoutAMainDocumentEachIsPartOfThePublication(): void {
		$relations = SitemapDocumentRelations::forFiles(files: [self::file('a.pdf'), self::file('b.pdf'), self::file('c.pdf')], publicationUrl: self::URL, publicationTitle: 'Besluit');

		$expected = ['diwoo:isPartOf' => ['@resource' => self::URL, '#text' => 'Besluit']];
		$this->assertSame([0 => $expected, 1 => $expected, 2 => $expected], $relations);
	}

	/**
	 * One main document lists the others in order; each other points at it.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	public function testAMainDocumentListsItsParts(): void {
		$files = [self::file('bijlage1.pdf'), self::file('besluit.pdf', ['concept', 'main-document']), self::file('bijlage2.pdf')];
		$relations = SitemapDocumentRelations::forFiles(files: $files, publicationUrl: self::URL, publicationTitle: 'Besluit');

		$toMain = ['diwoo:isPartOf' => ['@resource' => 'https://example.com/besluit.pdf', '#text' => 'besluit.pdf']];
		$this->assertSame(
			[
				0 => $toMain,
				1 => ['diwoo:hasParts' => ['diwoo:hasPart' => [
					['@resource' => 'https://example.com/bijlage1.pdf', '#text' => 'bijlage1.pdf'],
					['@resource' => 'https://example.com/bijlage2.pdf', '#text' => 'bijlage2.pdf'],
				]]],
				2 => $toMain,
			],
			$relations
		);
		$this->assertSame(1, SitemapDocumentRelations::mainDocumentCount(files: $files));
	}

	/**
	 * Two main documents fall back to the publication and are counted.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	public function testTwoMainDocumentsFallBackToThePublication(): void {
		$files = [self::file('a.pdf', ['main-document']), self::file('b.pdf', ['main-document'])];
		$relations = SitemapDocumentRelations::forFiles(files: $files, publicationUrl: self::URL, publicationTitle: 'Besluit');

		$this->assertSame(self::URL, $relations[0]['diwoo:isPartOf']['@resource']);
		$this->assertSame(self::URL, $relations[1]['diwoo:isPartOf']['@resource']);
		$this->assertSame(2, SitemapDocumentRelations::mainDocumentCount(files: $files));
		$this->assertSame(0, SitemapDocumentRelations::mainDocumentCount(files: [['labels' => 'main-document'], ['downloadUrl' => 'x']]));
	}
}//end class
