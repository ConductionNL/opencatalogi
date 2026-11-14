<?php

/**
 * WooIndexFieldMapper unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four Woo-index fields come from integriq's mapping when it answers, and
 * from the sitemap's own reads when it does not (REQ-WIM-001, REQ-WIM-002,
 * REQ-WIM-003). The listener answers on integriq's real event class (the test
 * copy under tests/Stubs when integriq is not installed).
 *
 * @category Test
 * @package  Unit\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\Integriq\Event\MappingExecutionRequestedEvent;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\SitemapDocumentMapper;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenCatalogi\Service\Woo\WooIndexFieldMapper;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The mapper over a dispatcher whose listener answers as integriq would.
 */
class WooIndexFieldMapperTest extends TestCase {

	/**
	 * The events the listener saw.
	 *
	 * @var array<int, MappingExecutionRequestedEvent>
	 */
	private array $seen = [];

	/**
	 * A mapper whose listener runs the given answer on each event.
	 *
	 * @param callable(MappingExecutionRequestedEvent): void $answer How integriq answers.
	 *
	 * @return WooIndexFieldMapper
	 */
	private function mapper(callable $answer): WooIndexFieldMapper {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($answer): void {
				$this->seen[] = $event;
				$answer($event);
			}
		);

		return new WooIndexFieldMapper(dispatcher: $dispatcher);
	}//end mapper()

	/**
	 * A publication with an id and a title.
	 *
	 * @param string $id The publication id.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(string $id = 'pub-1'): array {
		return [
			'@self' => ['id' => $id],
			'title' => 'Besluit',
			'summary' => 'Besluit windpark Oostpolder',
			'wooCategory' => 'infocat014',
			'soortHandeling' => 'vaststelling',
		];
	}//end publication()

	/**
	 * The output's non-empty fields are used, and the event names the mapping, the app and the input.
	 *
	 * @return void
	 */
	public function testOutputFieldsAreUsed(): void {
		$mapper = $this->mapper(static function (MappingExecutionRequestedEvent $event): void {
			$event->setOutput([
				'publisher' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855',
				'officieleTitel' => 'Besluit windpark Oostpolder',
				'informatiecategorie' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
				'soortHandeling' => 'vaststelling',
				'other' => 'ignored',
			]);
		});

		$answer = $mapper->map(publication: self::publication(), organisation: ['name' => 'Gemeente Tilburg', 'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855']);

		self::assertSame('Besluit windpark Oostpolder', $answer['fields']['officieleTitel']);
		self::assertSame(['publisher', 'officieleTitel', 'informatiecategorie', 'soortHandeling'], array_keys($answer['fields']));
		self::assertSame(array_fill_keys(WooIndexFieldMapper::FIELDS, 'mapping'), $answer['origin']);
		self::assertNull($answer['refusal']);

		$event = $this->seen[0];
		self::assertSame('woo-index-publication', $event->getMappingSlug());
		self::assertSame('opencatalogi', $event->getSourceApp());
		self::assertSame(
			[
				'title' => 'Besluit',
				'summary' => 'Besluit windpark Oostpolder',
				'name' => 'Gemeente Tilburg',
				'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855',
				'tooiCategorieUri' => '',
				'category' => 'infocat014',
				'soortHandeling' => 'vaststelling',
			],
			$event->getInput()
		);
	}//end testOutputFieldsAreUsed()

	/**
	 * An empty or missing output field falls back for that field only.
	 *
	 * @return void
	 */
	public function testAnEmptyOutputFieldFallsBackForThatFieldOnly(): void {
		$answer = $this->mapper(static function (MappingExecutionRequestedEvent $event): void {
			$event->setOutput(['officieleTitel' => 'Titel', 'publisher' => '  ', 'soortHandeling' => 7]);
		})->map(publication: self::publication());

		self::assertSame(['officieleTitel' => 'Titel'], $answer['fields']);
		self::assertSame(
			['publisher' => 'built-in', 'officieleTitel' => 'mapping', 'informatiecategorie' => 'built-in', 'soortHandeling' => 'built-in'],
			$answer['origin']
		);
	}//end testAnEmptyOutputFieldFallsBackForThatFieldOnly()

	/**
	 * A not-allowed refusal leaves every field built in and is kept.
	 *
	 * @return void
	 */
	public function testANotAllowedRefusalFallsBackAndIsKept(): void {
		$answer = $this->mapper(static function (MappingExecutionRequestedEvent $event): void {
			$event->refuse('opencatalogi may not run woo-index-publication', 'not-allowed');
		})->map(publication: self::publication());

		self::assertSame([], $answer['fields']);
		self::assertSame(array_fill_keys(WooIndexFieldMapper::FIELDS, 'built-in'), $answer['origin']);
		self::assertSame(['code' => 'not-allowed', 'reason' => 'opencatalogi may not run woo-index-publication'], $answer['refusal']);
	}//end testANotAllowedRefusalFallsBackAndIsKept()

	/**
	 * A throwing listener counts as `failed`, and nothing breaks.
	 *
	 * @return void
	 */
	public function testAThrowingListenerCountsAsFailed(): void {
		$answer = $this->mapper(static function (): void {
			throw new RuntimeException('mapping engine down');
		})->map(publication: self::publication());

		self::assertSame([], $answer['fields']);
		self::assertSame(['code' => 'failed', 'reason' => 'mapping engine down'], $answer['refusal']);
	}//end testAThrowingListenerCountsAsFailed()

	/**
	 * Unanswered events, and an absent integriq, leave everything built in.
	 *
	 * @return void
	 */
	public function testWithoutAnAnswerOrWithoutIntegriqEverythingIsBuiltIn(): void {
		$unanswered = $this->mapper(static function (): void {
		})->map(publication: self::publication());
		self::assertSame(['fields' => [], 'origin' => array_fill_keys(WooIndexFieldMapper::FIELDS, 'built-in'), 'refusal' => null], $unanswered);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');
		$absent = new WooIndexFieldMapper(dispatcher: $dispatcher, eventClass: 'OCA\\Missing\\Event');
		self::assertFalse($absent->isAvailable());
		self::assertSame([], $absent->map(publication: self::publication())['fields']);
	}//end testWithoutAnAnswerOrWithoutIntegriqEverythingIsBuiltIn()

	/**
	 * One dispatch per publication per request, however many files it has.
	 *
	 * @return void
	 */
	public function testOneDispatchPerPublication(): void {
		$mapper = $this->mapper(static function (MappingExecutionRequestedEvent $event): void {
			$event->setOutput(['officieleTitel' => 'T']);
		});

		$mapper->map(publication: self::publication('pub-1'));
		$mapper->map(publication: self::publication('pub-1'));
		$mapper->map(publication: self::publication('pub-2'));

		self::assertCount(2, $this->seen);
	}//end testOneDispatchPerPublication()

	/**
	 * A mapped category that is not a member of the informatiecategorieen list
	 * is omitted and reported, like a built-in one (REQ-WIM-003).
	 *
	 * @return void
	 */
	public function testAMappedCategoryOutsideTheListIsAViolation(): void {
		$mapper = $this->mapper(static function (MappingExecutionRequestedEvent $event): void {
			$event->setOutput(['informatiecategorie' => 'vergaderstukken-zonder-uri', 'publisher' => 'gm0855', 'soortHandeling' => 'gestempeld']);
		});
		$documents = $this->documentMapper(fieldMapper: $mapper);

		$violations = [];
		$entry = $documents->map(
			publication: self::publication() + ['tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855'],
			file: ['downloadUrl' => 'https://example.com/doc.pdf', 'extension' => 'pdf'],
			violations: $violations
		);

		$axes = array_column($violations, 'axis');
		self::assertContains('informatiecategorie', $axes);
		self::assertContains('publisher', $axes, 'a mapped publisher that is not a TOOI organisation URI');
		self::assertContains('soortHandeling', $axes);
		self::assertArrayNotHasKey('diwoo:classificatiecollectie', $entry['diwoo:Document']['diwoo:DiWoo']);
		self::assertArrayNotHasKey('@resource', $entry['diwoo:Document']['diwoo:DiWoo']['diwoo:publisher']);
	}//end testAMappedCategoryOutsideTheListIsAViolation()

	/**
	 * A document mapper over the real vocabulary and category registry.
	 *
	 * @param WooIndexFieldMapper|null $fieldMapper The field mapper.
	 *
	 * @return SitemapDocumentMapper
	 */
	private function documentMapper(?WooIndexFieldMapper $fieldMapper): SitemapDocumentMapper {
		$tooi = new TooiVocabularyService();

		return new SitemapDocumentMapper(
			tooiVocabulary: $tooi,
			categories: new WooCategoryRegistry($tooi, $this->createMock(SettingsService::class), $this->createMock(LoggerInterface::class), new LocalCategoryAdmission($tooi)),
			openRegister: new OpenRegisterLookup($this->createMock(ContainerInterface::class), $this->createMock(IAppManager::class)),
			fieldMapper: $fieldMapper
		);
	}//end documentMapper()

}//end class
