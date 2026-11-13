<?php

/**
 * Tests for CommentPeriodRequest: the publication id a comment period opens on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Contract;

use OCA\OpenCatalogi\Contract\CommentPeriodRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommentPeriodRequest::class)]
class CommentPeriodRequestTest extends TestCase {

	private function request(array $publication): CommentPeriodRequest {
		return new CommentPeriodRequest(
			publication: $publication,
			termDays: 42,
			legalRemedy: 'bezwaar',
			announcementUrl: 'https://example.org/notice',
			automaticWithdrawal: true,
			openedBy: 'admin'
		);
	}

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function publications(): array {
		return [
			'a plain id'                 => [['id' => 'pub-1'], 'pub-1'],
			'surrounding space trimmed'  => [['id' => "  pub-2\n"], 'pub-2'],
			'a numeric id as text'       => [['id' => 17], '17'],
			'no id'                      => [['title' => 'x'], ''],
			'a null id'                  => [['id' => null], ''],
			'only whitespace'            => [['id' => '   '], ''],
		];
	}

	#[DataProvider('publications')]
	public function testThePublicationIdIsTheTrimmedIdOrEmpty(array $publication, string $expected): void {
		self::assertSame($expected, $this->request(publication: $publication)->publicationId());
	}

	public function testTheRequestKeepsWhatItWasGiven(): void {
		$request = $this->request(publication: ['id' => 'pub-1']);

		self::assertSame(['id' => 'pub-1'], $request->publication);
		self::assertSame(42, $request->termDays);
		self::assertSame('bezwaar', $request->legalRemedy);
		self::assertTrue($request->automaticWithdrawal);
		self::assertSame('admin', $request->openedBy);
	}
}
