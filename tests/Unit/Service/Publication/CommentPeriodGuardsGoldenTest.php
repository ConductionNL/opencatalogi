<?php

/**
 * Golden tests for two guards of a comment period: which announcement urls
 * are accepted (and what is stored), and the period's state at the exact
 * edges of its window.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
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

use DateTimeImmutable;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\CommentPeriodService
 */
class CommentPeriodGuardsGoldenTest extends TestCase {

	/**
	 * The service, without its collaborators doing anything.
	 *
	 * @return CommentPeriodService
	 */
	private function service(): CommentPeriodService {
		return new CommentPeriodService(
			$this->getMockBuilder(TermRoll::class)->disableOriginalConstructor()->getMock(),
			$this->createMock(IAppConfig::class),
			$this->createMock(IURLGenerator::class)
		);
	}

	/**
	 * Announcement urls and what each answers: the stored url, or the refusal.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function announcements(): array {
		$none = 'A comment period needs the official announcement it was published with. A period nobody was told about is not a public comment period.';
		$https = 'The announcement has to be an absolute https url.';
		$host = 'The announcement has to point at officielebekendmakingen.nl, which is where an official announcement of a Dutch public body appears. A link to an internal page does not make a period publicly announced.';
		return [
			'the host itself, padded' => ['  https://officielebekendmakingen.nl/gmb-2026-1.html ', 'https://officielebekendmakingen.nl/gmb-2026-1.html'],
			'a subdomain' => ['https://zoek.officielebekendmakingen.nl/gmb-2026-1', 'https://zoek.officielebekendmakingen.nl/gmb-2026-1'],
			'upper case' => ['HTTPS://ZOEK.OfficieleBekendmakingen.NL/x', 'HTTPS://ZOEK.OfficieleBekendmakingen.NL/x'],
			'blank' => ['   ', 'refused: ' . $none],
			'http' => ['http://officielebekendmakingen.nl/x', 'refused: ' . $https],
			'no host' => ['https:///gmb-2026-1', 'refused: ' . $https],
			'https without a host' => ['https:gmb-2026-1', 'refused: ' . $https],
			'relative' => ['/gmb-2026-1', 'refused: ' . $https],
			'look-alike host' => ['https://evilofficielebekendmakingen.nl/x', 'refused: ' . $host],
			'host as a path' => ['https://intranet.example.nl/officielebekendmakingen.nl', 'refused: ' . $host],
		];
	}

	/**
	 * What one announcement url answers.
	 *
	 * @param string $url The url.
	 * @param string $expected The stored url, or "refused: " and the message.
	 *
	 * @return void
	 */
	#[DataProvider('announcements')]
	public function testAnnouncement(string $url, string $expected): void {
		$method = new ReflectionMethod(CommentPeriodService::class, 'validateAnnouncement');
		$method->setAccessible(true);
		try {
			$actual = $method->invoke($this->service(), $url);
		} catch (DomainException $e) {
			$actual = 'refused: ' . $e->getMessage();
		}

		$this->assertSame($expected, $actual);
	}

	/**
	 * The state at the exact edges of the window, and an unreadable window.
	 *
	 * @return void
	 */
	public function testStateAtTheEdges(): void {
		$period = ['startDate' => '2026-03-01T00:00:00+00:00', 'endDate' => '2026-03-29T23:59:59+00:00'];
		$at = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when);
		$service = $this->service();

		$this->assertSame(
			['upcoming', 'open', 'open', 'open', 'closed'],
			[
				$service->state($period, $at('2026-02-28T23:59:59+00:00')),
				$service->state($period, $at('2026-03-01T00:00:00+00:00')),
				$service->state($period, $at('2026-03-01T01:00:00+01:00')),
				$service->state($period, $at('2026-03-29T23:59:59+00:00')),
				$service->state($period, $at('2026-03-30T00:00:00+00:00')),
			]
		);

		try {
			$service->state(['startDate' => 'soon', 'endDate' => '2026-03-29'], $at('2026-03-01'));
			$this->fail('An unreadable period must refuse.');
		} catch (DomainException $e) {
			$this->assertSame('This comment period has dates that cannot be read, so its state is refused rather than guessed.', $e->getMessage());
			$this->assertInstanceOf(\Exception::class, $e->getPrevious());
		}
	}
}
