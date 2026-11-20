<?php
/**
 * Unit tests for SourceIdentifierFilter.
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

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\SourceIdentifierFilter;
use PHPUnit\Framework\TestCase;

/**
 * The `sourceIdentifier` filter of the public list and search (REQ-PRS-003).
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\SourceIdentifierFilter
 */
class SourceIdentifierFilterTest extends TestCase {

	/**
	 * The value splits at its first colon; both halves are required.
	 *
	 * @return void
	 */
	public function testTheValueNamesASystemAndAnIdentifier(): void {
		$this->assertSame(['system' => 'decos', 'identifier' => 'Z-2026-0412'], SourceIdentifierFilter::parse(value: 'decos:Z-2026-0412'));
		$this->assertSame(['system' => 'urn', 'identifier' => 'nl:gm0363:42'], SourceIdentifierFilter::parse(value: ' urn : nl:gm0363:42 '));
		$this->assertNull(SourceIdentifierFilter::parse(value: 'Z-2026-0412'));
		$this->assertNull(SourceIdentifierFilter::parse(value: ':Z-1'));
		$this->assertNull(SourceIdentifierFilter::parse(value: 'decos: '));
		$this->assertNull(SourceIdentifierFilter::parse(value: ['decos:Z-1']));
		$this->assertNull(SourceIdentifierFilter::parse(value: null));
	}//end testTheValueNamesASystemAndAnIdentifier()

	/**
	 * A public query matches the pair on a public entry only.
	 *
	 * @return void
	 */
	public function testAPublicQueryMatchesPublicEntriesOnly(): void {
		$query = SourceIdentifierFilter::forPublicQuery(
			query: ['_limit' => 20, 'sourceIdentifier' => 'decos:Z-1'],
			params: ['sourceIdentifier' => 'decos:Z-1']
		);

		$this->assertSame(
			['_limit' => 20, 'sourceIdentifiers' => ['contains' => ['system' => 'decos', 'identifier' => 'Z-1', 'public' => true]]],
			$query
		);
	}//end testAPublicQueryMatchesPublicEntriesOnly()

	/**
	 * A private identifier cannot be matched publicly: the caller's own
	 * filters on the property are dropped, whatever their shape.
	 *
	 * @return void
	 */
	public function testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly(): void {
		$probe = ['contains' => ['system' => 'case', 'identifier' => 'Z-1', 'public' => 'false']];

		$this->assertSame(
			['title' => 'x'],
			SourceIdentifierFilter::forPublicQuery(query: ['title' => 'x', 'sourceIdentifiers' => $probe], params: ['sourceIdentifiers' => $probe])
		);

		$query = SourceIdentifierFilter::forPublicQuery(
			query: ['sourceIdentifiers' => $probe],
			params: ['sourceIdentifiers' => $probe, 'sourceIdentifier' => 'case:Z-1']
		);
		$this->assertTrue($query['sourceIdentifiers']['contains']['public']);
	}//end testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly()

	/**
	 * A value that names no pair matches nothing instead of everything.
	 *
	 * @return void
	 */
	public function testAMalformedValueMatchesNothing(): void {
		$this->assertSame(
			['sourceIdentifiers' => ['contains' => []]],
			SourceIdentifierFilter::forPublicQuery(query: ['sourceIdentifier' => 'Z-1'], params: ['sourceIdentifier' => 'Z-1'])
		);
	}//end testAMalformedValueMatchesNothing()
}//end class
