<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins three small branching units before they are split: what
 * PortalInput::attachment() accepts and refuses, how QualityService scores
 * one distribution, and which state PublicationStateService::stateOf() gives
 * at the edges of a publication's window.
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Service\Portal\PortalInput;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\QualityService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for three small units.
 */
class SmallBranchesCharacterisationTest extends TestCase {

	/**
	 * Attachment values and what they give ('refused' for a refusal).
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function attachments(): array {
		return [
			'null' => [null, null],
			'empty' => ['', null],
			'digits' => ['42', '42'],
			'an int' => [42, '42'],
			'zero' => [0, '0'],
			'twenty digits' => [str_repeat('9', 20), str_repeat('9', 20)],
			'twenty-one digits' => [str_repeat('9', 21), 'refused'],
			'a negative int' => [-1, 'refused'],
			'a float' => [1.5, 'refused'],
			'an array' => [['1'], 'refused'],
			'false' => [false, 'refused'],
			'padded' => [' 42', 'refused'],
			'a newline after' => ["42\n", '42' . "\n"],
		];

	}//end attachments()

	/**
	 * The attachment value is a file id or nothing.
	 *
	 * @param mixed $value The value.
	 * @param string|null $expected The id, null, or 'refused'.
	 *
	 * @return void
	 */
	#[DataProvider('attachments')]
	public function testAnAttachmentIsAFileIdOrNothing(mixed $value, ?string $expected): void {
		try {
			$actual = (new PortalInput())->attachment($value);
		} catch (PortalInputException $e) {
			$this->assertSame('attachment must be a file id', $e->getMessage());
			$actual = 'refused';
		}

		$this->assertSame($expected, $actual);
	}//end testAnAttachmentIsAFileIdOrNothing()

	/**
	 * Distributions and their score.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function distributions(): array {
		return [
			'nothing' => [[], 0],
			'a format' => [['dct:format' => 'x'], 40],
			'an empty format list' => [['dct:format' => []], 0],
			'an empty format text' => [['dct:format' => ''], 40],
			'a null format' => [['dct:format' => null], 0],
			'a media type' => [['dcat:mediaType' => 'text/plain'], 30],
			'an empty media type' => [['dcat:mediaType' => ''], 0],
			'an empty media type list' => [['dcat:mediaType' => []], 30],
			'an open format' => [['dct:format' => 'http://publications.europa.eu/resource/authority/file-type/CSV'], 70],
			'everything' => [['dct:format' => 'http://publications.europa.eu/resource/authority/file-type/CSV', 'dcat:mediaType' => 'text/csv'], 100],
		];

	}//end distributions()

	/**
	 * A distribution scores 40 for a format, 30 for a media type and 30 for an open format.
	 *
	 * @param array<string, mixed> $distribution The distribution.
	 * @param int $expected The score.
	 *
	 * @return void
	 */
	#[DataProvider('distributions')]
	public function testADistributionIsScored(array $distribution, int $expected): void {
		$service = new QualityService($this->createMock(IAppConfig::class));
		$score = (new \ReflectionMethod($service, 'distributionScore'))->invoke($service, $distribution);

		$this->assertSame($expected, $score);
	}//end testADistributionIsScored()

	/**
	 * Publications and their state at noon on 2026-06-15.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function publications(): array {
		$now = '2026-06-15T12:00:00+00:00';
		return [
			'archived wins' => [['status' => 'archived', 'publicationDate' => '2026-01-01'], 'archived'],
			'nothing set' => [[], 'draft'],
			'published earlier' => [['publicationDate' => '2026-01-01T00:00:00+00:00'], 'public'],
			'published at this moment' => [['publicationDate' => $now], 'public'],
			'published a second later' => [['publicationDate' => '2026-06-15T12:00:01+00:00'], 'scheduled'],
			'withdrawn at this moment' => [['publicationDate' => '2026-01-01', 'depublicationDate' => $now], 'withdrawn'],
			'withdrawal a second later' => [['publicationDate' => '2026-01-01', 'depublicationDate' => '2026-06-15T12:00:01+00:00'], 'public'],
			'withdrawn without a publication date' => [['depublicationDate' => '2026-01-01'], 'withdrawn'],
			'an unreadable publication date' => [['publicationDate' => 'soon'], 'draft'],
			'an unreadable withdrawal date' => [['publicationDate' => '2026-01-01', 'depublicationDate' => 'never'], 'public'],
			'another status' => [['status' => 'concept', 'publicationDate' => '2026-01-01'], 'public'],
		];

	}//end publications()

	/**
	 * The state follows the archive status, the withdrawal date and the publication date, in that order.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $expected The state.
	 *
	 * @return void
	 */
	#[DataProvider('publications')]
	public function testThePublicationState(array $publication, string $expected): void {
		$state = (new PublicationStateService())->stateOf($publication, new DateTimeImmutable('2026-06-15T12:00:00+00:00'));

		$this->assertSame($expected, $state);
	}//end testThePublicationState()
}//end class
