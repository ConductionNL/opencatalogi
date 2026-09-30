<?php

/**
 * Unit tests for PublicationStateService.
 *
 * The state a publication is in, and the records the publish and withdraw
 * moves write. Every record is validated against the real register schema
 * with Opis, because a record the register refuses cannot be written at all.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the publication's visibility state and its records.
 */
class PublicationStateServiceTest extends TestCase {

	private PublicationStateService $states;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		$this->states = new PublicationStateService();
		$this->now = new DateTimeImmutable('2026-09-30T12:00:00+00:00');
	}

	/**
	 * A schema from a register file.
	 *
	 * @param string $file   The register file under lib/Settings.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $file, string $schema): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/' . $file), true);
		return $register['components']['schemas'][$schema];
	}

	/**
	 * Validate a record against the real schema, properties and required list.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $schema The schema.
	 */
	private function validates(array $record, array $schema): bool {
		foreach (array_keys($record) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], 'The schema declares no "' . $key . '".');
		}

		$shape = ['type' => 'object', 'properties' => $schema['properties'], 'required' => ($schema['required'] ?? [])];
		return (new Validator())->validate(json_decode((string)json_encode($record)), (string)json_encode($shape))->isValid();
	}

	/** REQ-PPW-001: a publication date last week and no depublication date reads public. */
	public function testAPublicationWithAPastPublicationDateIsPublic(): void {
		$this->assertSame('public', $this->states->stateOf(['publicationDate' => '2026-09-23T09:00:00+00:00'], $this->now));
	}

	/** REQ-PPW-001: a depublication date that has passed reads withdrawn. */
	public function testAPassedDepublicationDateIsWithdrawn(): void {
		$publication = ['publicationDate' => '2026-09-01T09:00:00+00:00', 'depublicationDate' => '2026-09-29T09:00:00+00:00'];
		$this->assertSame('withdrawn', $this->states->stateOf($publication, $this->now));
	}

	/** REQ-PPW-001: no publication date is a draft, a future one is scheduled, archived wins. */
	public function testDraftScheduledAndArchived(): void {
		$this->assertSame('draft', $this->states->stateOf(['title' => 'Besluit'], $this->now));
		$this->assertSame('scheduled', $this->states->stateOf(['publicationDate' => '2026-10-10T09:00:00+00:00'], $this->now));
		$this->assertSame('archived', $this->states->stateOf(['publicationDate' => '2026-09-01T09:00:00+00:00', 'status' => 'archived'], $this->now));
		// A depublication date still ahead does not withdraw it yet.
		$this->assertSame('public', $this->states->stateOf(['publicationDate' => '2026-09-01T09:00:00+00:00', 'depublicationDate' => '2026-12-01T09:00:00+00:00'], $this->now));
	}

	/** REQ-PPW-003: publish again clears the depublication date, and the register accepts the record. */
	public function testPublishAgainClearsTheDepublicationDate(): void {
		$stored = [
			'@self' => ['id' => 'p1'],
			'title' => 'Besluit',
			'publicationDate' => '2026-09-01T09:00:00+00:00',
			'depublicationDate' => '2026-09-29T09:00:00+00:00',
			'status' => 'published',
		];
		$record = $this->states->publishedRecord($stored, $this->now);

		$this->assertArrayNotHasKey('depublicationDate', $record);
		$this->assertArrayNotHasKey('@self', $record);
		$this->assertSame('public', $this->states->stateOf($record, $this->now));
		$this->assertTrue($this->validates($record, $this->schema('publication_register.json', 'publication')));
	}

	/** REQ-PPW-002: withdraw sets the depublication date to now, and the register accepts the record. */
	public function testWithdrawSetsTheDepublicationDateToNow(): void {
		$record = $this->states->withdrawnRecord(['title' => 'Besluit', 'publicationDate' => '2026-09-01T09:00:00+00:00'], $this->now);

		$this->assertSame('withdrawn', $this->states->stateOf($record, $this->now));
		$this->assertTrue($this->validates($record, $this->schema('publication_register.json', 'publication')));
	}

	/** REQ-PPW-002: the Woo-index always, PLOOI only when it was delivered there. */
	public function testTheChannelsAPublicationReached(): void {
		$this->assertSame(['national-woo-index'], $this->states->channelsReached(['plooiStatus' => 'failed']));
		$this->assertSame(['national-woo-index', 'plooi'], $this->states->channelsReached(['plooiStatus' => 'delivered']));
	}

	/**
	 * REQ-PPW-002/004: the depublication the move stores is one the register accepts.
	 *
	 * An unreached channel has no acknowledgement and no answer string from
	 * the channel yet. The raw record carries those as null, which the schema
	 * refuses; the stored one leaves them out.
	 */
	public function testTheStoredDepublicationIsOneTheRegisterAccepts(): void {
		$index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['withdraw'])
			->getMock();
		$index->method('withdraw')->willReturn(['channel' => 'plooi', 'acknowledgedAt' => null, 'answer' => null]);
		$raw = (new DepublicationService($index, $this->createMock(LoggerInterface::class)))->depublish(
			publication: ['id' => 'p1'],
			reason: 'Wrong annex attached',
			depublishedBy: 'redacteur',
			channels: ['plooi'],
			now: $this->now
		);
		$raw['file'] = '4711';

		$schema = $this->schema('register.d/publication-inspection-and-the-national-indexes.json', 'depublication');
		$this->assertFalse($this->validates($raw, $schema), 'The raw record with nulls should be refused, or this test proves nothing.');
		$this->assertTrue($this->validates($this->states->storable($raw), $schema));
	}
}
