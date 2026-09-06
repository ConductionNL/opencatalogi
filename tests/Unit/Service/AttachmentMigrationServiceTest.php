<?php

/**
 * Unit tests for the document-to-file migration rules.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\AttachmentMigrationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks the rules that decide what a migrated attachment keeps.
 */
class AttachmentMigrationServiceTest extends TestCase {

	/**
	 * @var AttachmentMigrationService
	 */
	private AttachmentMigrationService $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new AttachmentMigrationService(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A bare uuid string identifies the publication by id.
	 *
	 * @return void
	 */
	public function testABareUuidIsReadAsAnId(): void {
		$link = $this->service->readPublicationLink(link: 'a98463c8-aabf-4ec4-81fb-74e443e75296');

		$this->assertSame('a98463c8-aabf-4ec4-81fb-74e443e75296', $link['id']);
		$this->assertNull($link['slug']);

	}//end testABareUuidIsReadAsAnId()

	/**
	 * A bare string that is not a uuid is a slug. Treating it as a uuid finds
	 * nothing and reports the publication as missing, which reads as data loss
	 * rather than a lookup mistake.
	 *
	 * @return void
	 */
	public function testABareNonUuidIsReadAsASlug(): void {
		$link = $this->service->readPublicationLink(link: 'jaarverslag-2024');

		$this->assertNull($link['id']);
		$this->assertSame('jaarverslag-2024', $link['slug']);

	}//end testABareNonUuidIsReadAsASlug()

	/**
	 * The oldest link shape on live data carries only a slug and a title.
	 *
	 * @return void
	 */
	public function testASlugOnlyObjectIsReadAsASlug(): void {
		$link = $this->service->readPublicationLink(
			link: ['slug' => 'content-search-pub', 'title' => 'Content Search Publication']
		);

		$this->assertNull($link['id']);
		$this->assertSame('content-search-pub', $link['slug']);

	}//end testASlugOnlyObjectIsReadAsASlug()

	/**
	 * An object carrying an id yields the id, and the slug alongside it.
	 *
	 * @return void
	 */
	public function testAnObjectWithAnIdYieldsBoth(): void {
		$link = $this->service->readPublicationLink(
			link: ['id' => 'a98463c8-aabf-4ec4-81fb-74e443e75296', 'slug' => 'pub']
		);

		$this->assertSame('a98463c8-aabf-4ec4-81fb-74e443e75296', $link['id']);
		$this->assertSame('pub', $link['slug']);

	}//end testAnObjectWithAnIdYieldsBoth()

	/**
	 * A link that identifies nothing yields nothing, so the caller skips the
	 * document rather than attaching it somewhere arbitrary.
	 *
	 * @return void
	 */
	public function testAnEmptyLinkIdentifiesNothing(): void {
		foreach (['', '   ', null, [], ['slug' => ''], 42] as $value) {
			$link = $this->service->readPublicationLink(link: $value);
			$this->assertNull($link['id']);
			$this->assertNull($link['slug']);
		}

	}//end testAnEmptyLinkIdentifiesNothing()

	/**
	 * Summary and description are two pieces of prose about the same
	 * attachment, so both are carried rather than one overwriting the other.
	 *
	 * @return void
	 */
	public function testSummaryAndDescriptionAreBothKept(): void {
		$metadata = $this->service->fileMetadataFor(
			document: ['summary' => 'Short form.', 'description' => 'The long form.']
		);

		$this->assertStringContainsString('Short form.', $metadata['description']);
		$this->assertStringContainsString('The long form.', $metadata['description']);

	}//end testSummaryAndDescriptionAreBothKept()

	/**
	 * When the two are the same text, it appears once rather than twice.
	 *
	 * @return void
	 */
	public function testIdenticalProseIsNotDuplicated(): void {
		$metadata = $this->service->fileMetadataFor(
			document: ['summary' => 'Same text.', 'description' => 'Same text.']
		);

		$this->assertSame('Same text.', $metadata['description']);

	}//end testIdenticalProseIsNotDuplicated()

	/**
	 * A document with no prose yields no description, rather than an empty
	 * string that would overwrite whatever the file already carried.
	 *
	 * @return void
	 */
	public function testNoProseYieldsNoDescription(): void {
		$this->assertNull($this->service->fileMetadataFor(document: ['title' => 'T'])['description']);

	}//end testNoProseYieldsNoDescription()

	/**
	 * The title becomes a label, which is how the file keeps a human name
	 * distinct from its filename.
	 *
	 * @return void
	 */
	public function testTheTitleBecomesALabel(): void {
		$this->assertSame(
			['Jaarverslag 2024'],
			$this->service->fileMetadataFor(document: ['title' => 'Jaarverslag 2024'])['labels']
		);

	}//end testTheTitleBecomesALabel()

	/**
	 * The publication window is carried over.
	 *
	 * @return void
	 */
	public function testTheWindowIsCarriedOver(): void {
		$metadata = $this->service->fileMetadataFor(
			document: [
				'publicationDate' => '2024-01-01T00:00:00+00:00',
				'depublicationDate' => '2030-01-01T00:00:00+00:00',
			]
		);

		$this->assertSame('2024-01-01T00:00:00+00:00', $metadata['published']);
		$this->assertSame('2030-01-01T00:00:00+00:00', $metadata['depublished']);

	}//end testTheWindowIsCarriedOver()

	/**
	 * An unparseable date becomes null, never the migration's own runtime. A
	 * window starting when the command ran would publish every attachment at
	 * that moment, which is the opposite of preserving what a publisher set.
	 *
	 * @return void
	 */
	public function testAnUnparseableDateBecomesNullNotNow(): void {
		foreach (['not a date', '', '   ', null, 12345, []] as $value) {
			$this->assertNull($this->service->readDate(value: $value));
		}

	}//end testAnUnparseableDateBecomesNullNotNow()

	/**
	 * A document carrying no dates yields an open window rather than one that
	 * starts now.
	 *
	 * @return void
	 */
	public function testNoDatesYieldNoWindow(): void {
		$metadata = $this->service->fileMetadataFor(document: ['title' => 'T']);

		$this->assertNull($metadata['published']);
		$this->assertNull($metadata['depublished']);

	}//end testNoDatesYieldNoWindow()
}//end class
