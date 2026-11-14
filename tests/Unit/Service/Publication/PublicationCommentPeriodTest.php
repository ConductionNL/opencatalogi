<?php

/**
 * The comment period a reader sees on a publication.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\PublicationCommentPeriod;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationCommentPeriod
 * @uses \OCA\OpenCatalogi\Service\Publication\CommentPeriodService
 */
class PublicationCommentPeriodTest extends TestCase {

	/**
	 * The reader over an object service that answers $answer, or throws when it is a Throwable.
	 *
	 * @param mixed  $answer   The search answer.
	 * @param string $register The configured register.
	 *
	 * @return PublicationCommentPeriod
	 */
	private function reader(mixed $answer, string $register = '5'): PublicationCommentPeriod {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'publication_register' => $register,
				'comment_period_schema' => '9',
				default => '',
			}
		);
		$objects = new class ($answer) {
			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}
				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new PublicationCommentPeriod(
			new CommentPeriodService($this->createMock(TermRoll::class), $this->createMock(IAppConfig::class), $this->createMock(IURLGenerator::class)),
			$config,
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end reader()

	public function testAnUpcomingPeriodIsFoundThroughSelfId(): void {
		$period = ['publication' => 'pub-1', 'startDate' => '2030-01-01T00:00:00Z', 'endDate' => '2030-01-15T00:00:00Z', 'reactionFormUrl' => 'https://example.org/f'];
		$out = $this->reader(['results' => [$period]])->addTo(
			publication: ['@self' => ['id' => 'pub-1']],
			now: new \DateTimeImmutable('2029-12-01T00:00:00Z')
		);

		$this->assertSame('upcoming', $out['commentPeriod']['state']);
		$this->assertNull($out['commentPeriod']['reactionFormUrl']);
	}//end testAnUpcomingPeriodIsFoundThroughSelfId()

	public function testAPeriodOfAnotherPublicationIsNotAdded(): void {
		$out = $this->reader(['results' => [['publication' => 'other', 'startDate' => '2020-01-01', 'endDate' => '2020-02-01']]])->addTo(publication: ['id' => 'pub-1']);

		$this->assertSame(['id' => 'pub-1'], $out);
	}//end testAPeriodOfAnotherPublicationIsNotAdded()

	public function testAFailedReadOrNoConfigurationAddsNothing(): void {
		$this->assertSame(['id' => 'pub-1'], $this->reader(new \RuntimeException('down'))->addTo(publication: ['id' => 'pub-1']));
		$this->assertSame(['id' => 'pub-1'], $this->reader(['results' => []], register: '')->addTo(publication: ['id' => 'pub-1']));
		$this->assertSame(['title' => 'No id'], $this->reader(['results' => []])->addTo(publication: ['title' => 'No id']));
	}//end testAFailedReadOrNoConfigurationAddsNothing()
}//end class
