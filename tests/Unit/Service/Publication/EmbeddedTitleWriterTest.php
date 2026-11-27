<?php

/**
 * Unit tests for EmbeddedTitleWriter.
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
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\EmbeddedTitle;
use OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter;
use OCA\OpenCatalogi\Service\Publication\OfficeTitleUpdate;
use OCA\OpenCatalogi\Service\Publication\PdfTitleUpdate;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

require_once __DIR__ . '/PdfReadBack.php';

/**
 * The title written into a published PDF or office file, and every case that
 * leaves the file alone. The PDF results are read back by following their own
 * cross-reference to each object, so a wrong offset fails the test.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter
 * @covers \OCA\OpenCatalogi\Service\Publication\PdfTitleUpdate
 * @covers \OCA\OpenCatalogi\Service\Publication\PdfSyntax
 * @covers \OCA\OpenCatalogi\Service\Publication\OfficeTitleUpdate
 * @covers \OCA\OpenCatalogi\Service\Publication\TitleNotWritten
 * @covers \OCA\OpenCatalogi\Service\Publication\EmbeddedTitle
 */
class EmbeddedTitleWriterTest extends TestCase {

	private const TITLE = 'Besluit parkeerregulering binnenstad 2027';

	private const IDENTIFIER = '7b9f6c1e-2a43-4d8e-9f10-3c5b8a7d6e21';

	/**
	 * What the double's putContent() received, null when it was not called.
	 */
	private ?string $written = null;

	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The writer under test.
	 *
	 * @return EmbeddedTitleWriter
	 */
	private function writer(?\Psr\Container\ContainerInterface $container = null): EmbeddedTitleWriter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = strtr($message, ['{file}' => (string)($context['file'] ?? ''), '{error}' => (string)($context['error'] ?? '')]);
			}
		);

		return new EmbeddedTitleWriter(new PdfTitleUpdate(), new OfficeTitleUpdate(), $logger, $container);
	}//end writer()

	/**
	 * A stored file holding bytes, recording what is written to it.
	 *
	 * @param string          $bytes    The content.
	 * @param \Throwable|null $putFails Thrown by putContent().
	 *
	 * @return File
	 */
	private function file(string $bytes, ?\Throwable $putFails = null): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('getId')->willReturn(200);
		$file->method('getContent')->willReturn($bytes);
		$file->method('putContent')->willReturnCallback(
			function (string $data) use ($putFails): void {
				if ($putFails !== null) {
					throw $putFails;
				}

				$this->written = $data;
			}
		);

		return $file;
	}//end file()

	/**
	 * A PDF fixture.
	 *
	 * @param string $name The fixture name.
	 *
	 * @return string
	 */
	private static function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/pdf/' . $name . '.pdf');
	}//end fixture()

	public function testThePdfInfoAndXmpCarryTheTitle(): void {
		$outcome = $this->writer()->write(file: $this->file(self::fixture('plain')), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(EmbeddedTitleWriter::WRITTEN, $outcome['status']);
		$this->assertArrayNotHasKey('reason', $outcome);
		$read = PdfReadBack::read((string)$this->written);
		$this->assertSame(self::TITLE, $read['title']);
		$this->assertSame(self::TITLE, $read['xmpTitle']);
		$this->assertSame(self::IDENTIFIER, $read['xmpIdentifier']);
		$this->assertNull($read['xmpPublisher'], 'without a known publisher no dc:publisher is written');
		$this->assertNull($read['xmpRsin']);
		$this->assertSame(hash('sha256', (string)$this->written), $outcome['hashAfter']);
	}//end testThePdfInfoAndXmpCarryTheTitle()

	/**
	 * The publisher's name and RSIN go into the XMP beside the title: `dc:publisher` and `oc:rsin` (REQ-PRO-002).
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function testTheRsinIsEmbeddedBesideTheTitle(): void {
		$plain = self::fixture('plain');
		$outcome = $this->writer()->write(file: $this->file($plain), facts: self::withPublisher());

		$this->assertSame(EmbeddedTitleWriter::WRITTEN, $outcome['status']);
		$read = PdfReadBack::read((string)$this->written);
		$this->assertSame(self::TITLE, $read['xmpTitle']);
		$this->assertSame('Gemeente Zuiddrecht & Co', $read['xmpPublisher']);
		$this->assertSame('002220647', $read['xmpRsin']);
		$this->assertStringContainsString('xmlns:oc="' . PdfTitleUpdate::XMP_NAMESPACE . '"', $read['xmp']);
		$this->assertStringStartsWith($plain, (string)$this->written, 'the update is still appended after the last byte');
	}//end testTheRsinIsEmbeddedBesideTheTitle()

	/**
	 * A docx gets the publisher in `docProps/custom.xml`, declared with its content type and relationship;
	 * a second write updates the values and adds nothing twice (REQ-PRO-002).
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function testADocxCarriesThePublisherInCustomProperties(): void {
		$docx = self::package([
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
			'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"/>',
		]);
		$outcome = $this->writer()->write(file: $this->file($docx), facts: self::withPublisher());

		$this->assertSame(EmbeddedTitleWriter::WRITTEN, $outcome['status']);
		$custom = self::part((string)$this->written, 'docProps/custom.xml');
		$this->assertSame(['Gemeente Zuiddrecht & Co', '002220647'], self::texts($custom, 'lpwstr'));
		$this->assertSame(['publisher' => '2', 'rsin' => '3'], self::pids($custom));
		$types = self::part((string)$this->written, '[Content_Types].xml');
		$this->assertStringContainsString('PartName="/docProps/custom.xml"', $types);
		$this->assertStringContainsString('application/vnd.openxmlformats-officedocument.custom-properties+xml', $types);
		$rels = self::part((string)$this->written, '_rels/.rels');
		$this->assertStringContainsString('Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties" Target="docProps/custom.xml"', $rels);

		$this->writer()->write(
			file: $this->file((string)$this->written),
			facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER, publisher: 'Gemeente Noorddrecht', rsin: '002220647')
		);
		$again = self::part((string)$this->written, 'docProps/custom.xml');
		$this->assertSame(['Gemeente Noorddrecht', '002220647'], self::texts($again, 'lpwstr'));
		$this->assertSame(1, substr_count(self::part((string)$this->written, '[Content_Types].xml'), 'custom.xml'));
		$this->assertSame(1, substr_count(self::part((string)$this->written, '_rels/.rels'), 'custom.xml'));
	}//end testADocxCarriesThePublisherInCustomProperties()

	/**
	 * A docx that has custom properties keeps them and gets the publisher's after the highest `pid`.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function testExistingCustomPropertiesAreKept(): void {
		$docx = self::package([
			'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"/>',
			'docProps/custom.xml' => '<?xml version="1.0"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="7" name="kenmerk"><vt:lpwstr>K-1</vt:lpwstr></property></Properties>',
		]);
		$this->writer()->write(
			file: $this->file($docx),
			facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER, publisher: null, rsin: '002220647')
		);

		$custom = self::part((string)$this->written, 'docProps/custom.xml');
		$this->assertSame(['kenmerk' => '7', 'rsin' => '8'], self::pids($custom));
		$this->assertSame(['K-1', '002220647'], self::texts($custom, 'lpwstr'));
	}//end testExistingCustomPropertiesAreKept()

	/**
	 * A docx without its package relationships cannot declare a new custom part, so it is skipped
	 * when there is a publisher to write; without one it still gets its title.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function testADocxThatCannotDeclareTheCustomPartIsSkipped(): void {
		$docx = self::package([
			'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"/>',
		]);
		$outcome = $this->writer()->write(file: $this->file($docx), facts: self::withPublisher());

		$this->assertSame(['skipped', 'no-metadata-part'], [$outcome['status'], $outcome['reason']]);
		$this->assertNull($this->written);
	}//end testADocxThatCannotDeclareTheCustomPartIsSkipped()

	/**
	 * An odt gets the publisher and RSIN as user-defined fields beside the identifier.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function testAnOdtCarriesThePublisherInUserDefinedFields(): void {
		$meta = '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><office:meta/></office:document-meta>';
		$odt = self::package(['mimetype' => 'application/vnd.oasis.opendocument.text', 'meta.xml' => $meta]);
		$this->writer()->write(file: $this->file($odt), facts: self::withPublisher());
		$this->writer()->write(file: $this->file((string)$this->written), facts: self::withPublisher());

		$written = self::part((string)$this->written, 'meta.xml');
		$this->assertSame([self::IDENTIFIER, 'Gemeente Zuiddrecht & Co', '002220647'], self::texts($written, 'user-defined'));
	}//end testAnOdtCarriesThePublisherInUserDefinedFields()

	/**
	 * Facts with a known publisher.
	 *
	 * @return EmbeddedTitle
	 */
	private static function withPublisher(): EmbeddedTitle {
		return new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER, publisher: 'Gemeente Zuiddrecht & Co', rsin: '002220647');
	}//end withPublisher()

	/**
	 * The `pid` of each custom property, by name.
	 *
	 * @param string $xml The custom.xml.
	 *
	 * @return array<string, string>
	 */
	private static function pids(string $xml): array {
		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$pids = [];
		foreach ($doc->getElementsByTagNameNS('*', 'property') as $node) {
			$pids[$node->getAttribute('name')] = $node->getAttribute('pid');
		}

		return $pids;
	}//end pids()

	public function testTheVerifiedRedactedBytesAreAnExactPrefix(): void {
		$verified = self::fixture('plain');
		$outcome = $this->writer()->write(file: $this->file($verified), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(hash('sha256', $verified), $outcome['hashBefore']);
		$this->assertGreaterThan(strlen($verified), strlen((string)$this->written));
		$this->assertSame(hash('sha256', $verified), hash('sha256', substr((string)$this->written, 0, strlen($verified))));
		$this->assertNotSame($outcome['hashBefore'], $outcome['hashAfter']);
	}//end testTheVerifiedRedactedBytesAreAnExactPrefix()

	public function testTheUpdatePointsBackAtTheLastCrossReferenceAndKeepsTheOtherInfoEntries(): void {
		$before = self::fixture('incremental');
		$this->writer()->write(file: $this->file($before), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$read = PdfReadBack::read((string)$this->written);
		$this->assertSame(PdfReadBack::lastStartxref($before), $read['prev']);
		$this->assertSame(self::TITLE, $read['title']);
		$this->assertStringContainsString("5 0 obj\n<< /Title (Second revision) >>", (string)$this->written, 'the earlier revision stays in the file');

		$plain = self::fixture('plain');
		$this->writer()->write(file: $this->file($plain), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$info = PdfReadBack::read((string)$this->written)['info'];
		$this->assertStringContainsString('/Producer (Word \\(16\\))', $info);
		$this->assertStringContainsString('/Author <FEFF0041>', $info);
		$this->assertStringNotContainsString('concept v3', $info);
		$this->assertStringContainsString('/ID [<0123456789ABCDEF0123456789ABCDEF>', PdfReadBack::lastTrailer((string)$this->written));
	}//end testTheUpdatePointsBackAtTheLastCrossReferenceAndKeepsTheOtherInfoEntries()

	public function testAPdfWithACrossReferenceStreamGetsAStreamUpdate(): void {
		$before = self::fixture('xref-stream');
		$outcome = $this->writer()->write(file: $this->file($before), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(EmbeddedTitleWriter::WRITTEN, $outcome['status']);
		$read = PdfReadBack::read((string)$this->written);
		$this->assertTrue($read['stream']);
		$this->assertSame(PdfReadBack::lastStartxref($before), $read['prev']);
		$this->assertSame([self::TITLE, self::TITLE, self::IDENTIFIER], [$read['title'], $read['xmpTitle'], $read['xmpIdentifier']]);
		$this->assertStringStartsWith($before, (string)$this->written);
	}//end testAPdfWithACrossReferenceStreamGetsAStreamUpdate()

	public function testASignedPdfIsSkippedWithTheReason(): void {
		$signed = self::fixture('signed');
		$outcome = $this->writer()->write(file: $this->file($signed), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(
			['status' => 'skipped', 'hashBefore' => hash('sha256', $signed), 'hashAfter' => hash('sha256', $signed), 'reason' => 'signed'],
			$outcome
		);
		$this->assertNull($this->written);
	}//end testASignedPdfIsSkippedWithTheReason()

	public function testAnEncryptedPdfIsSkipped(): void {
		$outcome = $this->writer()->write(file: $this->file(self::fixture('encrypted')), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(['skipped', 'encrypted'], [$outcome['status'], $outcome['reason']]);
		$this->assertNull($this->written);
	}//end testAnEncryptedPdfIsSkipped()

	/**
	 * Damaged PDFs: no cross-reference, a root that is not a dictionary, an
	 * unclosed string, a root inside an object stream, a truncated dictionary.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function damagedPdfs(): array {
		$plain = self::fixture('plain');
		return [
			'no startxref' => [str_replace('startxref', 'startxrf', $plain)],
			'catalog not a dictionary' => [str_replace('<< /Type /Catalog /Pages 2 0 R >>', '[1 2 3]', $plain)],
			'unclosed string in the info' => [str_replace('(Microsoft Word - concept v3)', '(Microsoft Word', $plain)],
			'catalog not in the file itself' => [str_replace('1 0 obj', '1 0 xbj', $plain)],
			'startxref past the end' => [preg_replace('/startxref\s+\d+/', "startxref\n999999", $plain)],
			'trailer without root' => [str_replace('/Root 1 0 R', '', $plain)],
			'truncated' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages\nstartxref\n9\n%%EOF\n"],
		];
	}//end damagedPdfs()

	/**
	 * @dataProvider damagedPdfs
	 */
	public function testADamagedPdfIsSkippedAsUnparseable(string $bytes): void {
		$outcome = $this->writer()->write(file: $this->file($bytes), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(['skipped', 'unparseable'], [$outcome['status'], $outcome['reason']]);
		$this->assertNull($this->written);
	}//end testADamagedPdfIsSkippedAsUnparseable()

	public function testADocxCarriesTheTitleInCoreProperties(): void {
		$docx = self::package([
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
			'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Microsoft Word - concept v3</dc:title><dc:creator>Jan</dc:creator></cp:coreProperties>',
		]);
		$outcome = $this->writer()->write(file: $this->file($docx), facts: new EmbeddedTitle(title: self::TITLE . ' & bijlagen', identifier: self::IDENTIFIER));

		$this->assertSame(EmbeddedTitleWriter::WRITTEN, $outcome['status']);
		$core = self::part((string)$this->written, 'docProps/core.xml');
		$this->assertSame([self::TITLE . ' & bijlagen'], self::texts($core, 'title'));
		$this->assertSame([self::IDENTIFIER], self::texts($core, 'identifier'));
		$this->assertSame(['Jan'], self::texts($core, 'creator'));
		$this->assertSame(
			'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
			self::part((string)$this->written, 'word/document.xml')
		);
	}//end testADocxCarriesTheTitleInCoreProperties()

	public function testAnOdtCarriesTheTitleAndAUserDefinedIdentifierInMeta(): void {
		$meta = '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><office:meta>'
			. '<meta:user-defined meta:name="kenmerk">K-1</meta:user-defined></office:meta></office:document-meta>';
		$odt = self::package(['mimetype' => 'application/vnd.oasis.opendocument.text', 'meta.xml' => $meta]);
		$this->writer()->write(file: $this->file($odt), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$written = self::part((string)$this->written, 'meta.xml');
		$this->assertSame([self::TITLE], self::texts($written, 'title'));
		$this->assertSame(['K-1', self::IDENTIFIER], self::texts($written, 'user-defined'));

		$this->writer()->write(file: $this->file((string)$this->written), facts: new EmbeddedTitle(title: 'Tweede titel', identifier: 'id-2'));
		$again = self::part((string)$this->written, 'meta.xml');
		$this->assertSame(['Tweede titel'], self::texts($again, 'title'));
		$this->assertSame(['K-1', 'id-2'], self::texts($again, 'user-defined'), 'the identifier field is reused, not added twice');
	}//end testAnOdtCarriesTheTitleAndAUserDefinedIdentifierInMeta()

	public function testAPackageWithoutMetadataOrWithBrokenMetadataIsSkipped(): void {
		$outcome = $this->writer()->write(file: $this->file(self::package(['word/document.xml' => '<x/>'])), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'no-metadata-part'], [$outcome['status'], $outcome['reason']]);

		$outcome = $this->writer()->write(file: $this->file(self::package(['docProps/core.xml' => '<not xml'])), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'unparseable'], [$outcome['status'], $outcome['reason']]);

		$outcome = $this->writer()->write(file: $this->file(self::package(['meta.xml' => '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>'])), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'no-metadata-part'], [$outcome['status'], $outcome['reason']]);

		$outcome = $this->writer()->write(file: $this->file("PK\x03\x04 not a zip"), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'unparseable'], [$outcome['status'], $outcome['reason']]);
		$this->assertNull($this->written);
	}//end testAPackageWithoutMetadataOrWithBrokenMetadataIsSkipped()

	public function testAFailureLeavesTheBytesAndSaysFailed(): void {
		$plain = self::fixture('plain');
		$outcome = $this->writer()->write(file: $this->file($plain, new NotPermittedException('read-only storage')), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));

		$this->assertSame(
			['status' => 'failed', 'hashBefore' => hash('sha256', $plain), 'hashAfter' => hash('sha256', $plain), 'reason' => 'write-error'],
			$outcome
		);
		$this->assertSame(['[EmbeddedTitleWriter] The title was not written into besluit.pdf: read-only storage'], $this->warnings);
	}//end testAFailureLeavesTheBytesAndSaysFailed()

	public function testAnUnreadableFileFailsWithoutAHash(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('kwijt.pdf');
		$file->method('getContent')->willThrowException(new NotPermittedException('gone'));
		$file->expects($this->never())->method('putContent');

		$this->assertSame(
			['status' => 'failed', 'hashBefore' => null, 'hashAfter' => null, 'reason' => 'write-error'],
			$this->writer()->write(file: $file, facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER))
		);
		$this->assertSame(['[EmbeddedTitleWriter] The title was not written into kwijt.pdf: gone'], $this->warnings);
	}//end testAnUnreadableFileFailsWithoutAHash()

	public function testAnotherTypeAndAMissingTitleAreSkipped(): void {
		$outcome = $this->writer()->write(file: $this->file('plain text'), facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'unsupported-type'], [$outcome['status'], $outcome['reason']]);

		$outcome = $this->writer()->write(file: $this->file(self::fixture('plain')), facts: new EmbeddedTitle(title: '  ', identifier: self::IDENTIFIER));
		$this->assertSame(['skipped', 'no-title'], [$outcome['status'], $outcome['reason']]);
		$this->assertNull($this->written);
	}//end testAnotherTypeAndAMissingTitleAreSkipped()

	/**
	 * A zip package with these parts.
	 *
	 * @param array<string, string> $parts Content by part name.
	 *
	 * @return string
	 */
	private static function package(array $parts): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'oc-test-');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end package()

	/**
	 * One part of a zip package.
	 *
	 * @param string $bytes The package.
	 * @param string $name  The part.
	 *
	 * @return string
	 */
	private static function part(string $bytes, string $name): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'oc-test-');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$zip->open($path);
		$part = (string)$zip->getFromName($name);
		$zip->close();
		unlink($path);
		return $part;
	}//end part()

	/**
	 * The texts of the elements with a local name.
	 *
	 * @param string $xml   The XML.
	 * @param string $local The local name.
	 *
	 * @return array<int, string>
	 */
	private static function texts(string $xml, string $local): array {
		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$texts = [];
		foreach ($doc->getElementsByTagNameNS('*', $local) as $node) {
			$texts[] = $node->textContent;
		}

		return $texts;
	}//end texts()
	/**
	 * A container with OpenRegister's real verifier and a verdict store double
	 * that records what it keeps.
	 *
	 * @param bool $keeps Whether the store keeps the verdict.
	 *
	 * @return \Psr\Container\ContainerInterface
	 */
	private function withVerifier(bool $keeps = true): \Psr\Container\ContainerInterface {
		$class = 'OCA\\OpenRegister\\Service\\Anonymisation\\RedactionIrreversibilityVerifier';
		if (class_exists(class: $class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s RedactionIrreversibilityVerifier is not on the autoload path.');
		}

		$store = new class ($keeps) {

			/**
			 * What record() received.
			 *
			 * @var array<int, array{fileId: int, verification: array<string, mixed>}>
			 */
			public array $kept = [];

			/**
			 * Constructor.
			 *
			 * @param bool $keeps Whether a verdict is kept.
			 */
			public function __construct(private readonly bool $keeps) {
			}//end __construct()

			/**
			 * Mirrors OpenRegister RedactionVerdictStore::record().
			 *
			 * @param int   $fileId       The copy.
			 * @param array $verification The verdict.
			 *
			 * @return bool
			 */
			public function record(int $fileId, array $verification): bool {
				$this->kept[] = ['fileId' => $fileId, 'verification' => $verification];
				return $this->keeps;
			}//end record()
		};
		$this->store = $store;

		$container = $this->createMock(originalClassName: \Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				$class => new $class(),
				'OCA\\OpenRegister\\Service\\Anonymisation\\RedactionVerdictStore' => $store,
			}
		);

		return $container;
	}//end withVerifier()

	/**
	 * The verdict store double of the last withVerifier().
	 *
	 * @var object|null
	 */
	private ?object $store = null;

	/**
	 * Task 2.2b: OpenRegister's verifier runs again on the bytes with the
	 * title, against the redacted values; a clean verdict writes them and is
	 * kept on the copy.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 *
	 * @return void
	 */
	public function testTheVerifierRunsAgainOnTheWrittenBytes(): void {
		$writer = $this->writer(container: $this->withVerifier());

		$outcome = $writer->write(
			file: $this->file(bytes: self::fixture(name: 'plain')),
			facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER),
			redaction: ['values' => ['Jan Jansen', '0612345678'], 'outputMode' => 'pdf']
		);

		$this->assertSame(expected: EmbeddedTitleWriter::WRITTEN, actual: $outcome['status']);
		$this->assertSame(expected: 'clean', actual: $outcome['verdict']);
		$this->assertNotNull(actual: $this->written);
		$this->assertCount(expectedCount: 1, haystack: $this->store->kept);
		$this->assertSame(expected: 200, actual: $this->store->kept[0]['fileId']);
		$this->assertSame(expected: 'clean', actual: $this->store->kept[0]['verification']['verdict']);
		$this->assertTrue(condition: $this->store->kept[0]['verification']['mayBePublished']);
	}//end testTheVerifierRunsAgainOnTheWrittenBytes()

	/**
	 * Task 2.2b: a title that brings a redacted value back is found by the
	 * verifier, and the verified bytes are left as they were.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 *
	 * @return void
	 */
	public function testATitleThatBringsBackARedactedValueIsNotWritten(): void {
		$plain = self::fixture(name: 'plain');
		$writer = $this->writer(container: $this->withVerifier());

		$outcome = $writer->write(
			file: $this->file(bytes: $plain),
			facts: new EmbeddedTitle(title: 'Besluit op het verzoek van Jan Jansen', identifier: self::IDENTIFIER),
			redaction: ['values' => ['Jan Jansen'], 'outputMode' => 'pdf']
		);

		$this->assertSame(expected: EmbeddedTitleWriter::SKIPPED, actual: $outcome['status']);
		$this->assertSame(expected: EmbeddedTitleWriter::VERIFIER, actual: $outcome['reason']);
		$this->assertSame(expected: 'leaking', actual: $outcome['verdict']);
		$this->assertSame(expected: hash('sha256', $plain), actual: $outcome['hashAfter']);
		$this->assertNull(actual: $this->written, message: 'nothing is written');
		$this->assertSame(expected: [], actual: $this->store->kept);
	}//end testATitleThatBringsBackARedactedValueIsNotWritten()

	/**
	 * Task 2.2b: without the verifier, or with an output mode it cannot read,
	 * a redacted copy is not written to; a document that was not redacted is.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 *
	 * @return void
	 */
	public function testWithoutAVerdictARedactedCopyIsNotWritten(): void {
		$facts = new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER);
		$redaction = ['values' => ['Jan Jansen'], 'outputMode' => 'pdf'];

		$outcome = $this->writer()->write(file: $this->file(bytes: self::fixture(name: 'plain')), facts: $facts, redaction: $redaction);
		$this->assertSame(expected: [EmbeddedTitleWriter::SKIPPED, EmbeddedTitleWriter::VERIFIER, 'unverifiable'], actual: [$outcome['status'], $outcome['reason'], $outcome['verdict']]);

		$broken = $this->createMock(originalClassName: \Psr\Container\ContainerInterface::class);
		$broken->method('get')->willThrowException(new \RuntimeException('not registered'));
		$outcome = $this->writer(container: $broken)->write(file: $this->file(bytes: self::fixture(name: 'plain')), facts: $facts, redaction: $redaction);
		$this->assertSame(expected: 'unverifiable', actual: $outcome['verdict']);
		$this->assertSame(expected: ['[EmbeddedTitleWriter] The redaction could not be verified again: RuntimeException'], actual: $this->warnings);

		$outcome = $this->writer(container: $this->withVerifier())->write(
			file: $this->file(bytes: self::fixture(name: 'plain')),
			facts: $facts,
			redaction: ['values' => [], 'outputMode' => 'pdf']
		);
		$this->assertSame(expected: 'unverifiable', actual: $outcome['verdict'], message: 'no redacted values is nothing to verify against');
		$this->assertNull(actual: $this->written);

		// An assessment from before the output mode was kept reads a PDF as a PDF.
		$outcome = $this->writer(container: $this->withVerifier())->write(
			file: $this->file(bytes: self::fixture(name: 'plain')),
			facts: $facts,
			redaction: ['values' => ['Jan Jansen'], 'outputMode' => '']
		);
		$this->assertSame(expected: EmbeddedTitleWriter::WRITTEN, actual: $outcome['status']);
	}//end testWithoutAVerdictARedactedCopyIsNotWritten()

	/**
	 * Task 2.2b: a clean verdict the store cannot keep is logged; the title is written.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 *
	 * @return void
	 */
	public function testAVerdictThatCannotBeKeptIsLogged(): void {
		$outcome = $this->writer(container: $this->withVerifier(keeps: false))->write(
			file: $this->file(bytes: self::fixture(name: 'plain')),
			facts: new EmbeddedTitle(title: self::TITLE, identifier: self::IDENTIFIER),
			redaction: ['values' => ['Jan Jansen'], 'outputMode' => 'pdf']
		);

		$this->assertSame(expected: EmbeddedTitleWriter::WRITTEN, actual: $outcome['status']);
		$this->assertSame(expected: ['[EmbeddedTitleWriter] The verdict of besluit.pdf was not kept'], actual: $this->warnings);
	}//end testAVerdictThatCannotBeKeptIsLogged()
}//end class
