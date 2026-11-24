<?php

/**
 * Sets the official title in an office file's core properties.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/**
 * Office Open XML (docx, xlsx, pptx): `dc:title` and `dc:identifier` in
 * `docProps/core.xml`. OpenDocument (odt, ods, odp): `dc:title` and a
 * user-defined `identifier` in `meta.xml`, which has no identifier element.
 * Without a known publisher only that one part of the package changes.
 *
 * The publisher's name and RSIN, when known, go into custom properties
 * `publisher` and `rsin`, because neither core properties set has a publisher
 * element: user-defined fields in ODF `meta.xml`, and `docProps/custom.xml` in
 * OOXML, which is added with its relationship and content type when the
 * package has none (REQ-PRO-002).
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */
class OfficeTitleUpdate {

	/**
	 * The reason a package without the metadata part this writer sets is left alone.
	 */
	public const NO_METADATA = 'no-metadata-part';

	private const DC = 'http://purl.org/dc/elements/1.1/';

	private const ODF_META = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';

	private const ODF_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

	private const OOXML_CORE = 'docProps/core.xml';

	private const ODF_META_PART = 'meta.xml';

	private const OOXML_CUSTOM = 'docProps/custom.xml';

	private const OOXML_CUSTOM_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';

	private const OOXML_VT_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';

	private const OOXML_CUSTOM_TYPE = 'application/vnd.openxmlformats-officedocument.custom-properties+xml';

	private const OOXML_CUSTOM_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties';

	private const OOXML_CONTENT_TYPES_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

	private const OOXML_RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * The format id every user-defined OOXML custom property carries.
	 */
	private const OOXML_CUSTOM_FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';

	/**
	 * Whether the bytes are a zip package.
	 *
	 * @param string $bytes The file.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function handles(string $bytes): bool {
		return str_starts_with($bytes, "PK\x03\x04");
	}//end handles()

	/**
	 * The package with the title set.
	 *
	 * @param string $bytes      The office file.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return string The changed package.
	 *
	 * @throws TitleNotWritten When it is not an office package or its metadata part cannot be read.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function apply(string $bytes, EmbeddedTitle $facts): string {
		$path = self::tempFile(bytes: $bytes);
		try {
			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
			}

			$this->setTitle(zip: $zip, facts: $facts);
			$zip->close();
			return (string)file_get_contents($path);
		} finally {
			unlink($path);
		}
	}//end apply()

	/**
	 * Set the title in whichever metadata part the package has.
	 *
	 * @param ZipArchive $zip        The open package.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When the package has neither part, or it is not readable XML.
	 */
	private function setTitle(ZipArchive $zip, EmbeddedTitle $facts): void {
		[$part, $xml] = self::metadataPart(zip: $zip);
		$doc = self::parsed(xml: $xml);

		if ($part === self::OOXML_CORE) {
			$this->element(parent: $doc->documentElement, name: 'dc:title')->textContent = $facts->title;
			$this->element(parent: $doc->documentElement, name: 'dc:identifier')->textContent = $facts->identifier;
			$this->setOoxmlPublisher(zip: $zip, facts: $facts);
		}

		if ($part === self::ODF_META_PART) {
			$this->setOdfTitle(doc: $doc, facts: $facts);
		}

		$zip->addFromString($part, (string)$doc->saveXML());
	}//end setTitle()

	/**
	 * The publisher's custom properties that are known: `publisher` and `rsin`.
	 *
	 * @param EmbeddedTitle $facts The facts.
	 *
	 * @return array<string, string> Property name to value.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	private static function publisherProperties(EmbeddedTitle $facts): array {
		return array_filter(
			['publisher' => (string)($facts->publisher ?? ''), 'rsin' => (string)($facts->rsin ?? '')],
			static fn (string $value): bool => $value !== ''
		);
	}//end publisherProperties()

	/**
	 * Write the publisher's name and RSIN into `docProps/custom.xml`, adding the
	 * part, its relationship and its content type when the package has none.
	 *
	 * @param ZipArchive    $zip   The open package.
	 * @param EmbeddedTitle $facts The facts.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When an existing part is not readable XML.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	private function setOoxmlPublisher(ZipArchive $zip, EmbeddedTitle $facts): void {
		$properties = self::publisherProperties(facts: $facts);
		if ($properties === []) {
			return;
		}

		$xml = $zip->getFromName(self::OOXML_CUSTOM);
		if ($xml === false) {
			$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<Properties xmlns="' . self::OOXML_CUSTOM_NS . '" xmlns:vt="' . self::OOXML_VT_NS . '"/>';
			$this->registerCustomPart(zip: $zip);
		}

		$doc = self::parsed(xml: $xml);
		foreach ($properties as $name => $value) {
			self::setCustomProperty(doc: $doc, name: $name, value: $value);
		}

		$zip->addFromString(self::OOXML_CUSTOM, (string)$doc->saveXML());
	}//end setOoxmlPublisher()

	/**
	 * Set one custom property's text value, adding it with the next free `pid` when absent.
	 *
	 * @param DOMDocument $doc   The parsed custom.xml.
	 * @param string      $name  The property name.
	 * @param string      $value Its value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	private static function setCustomProperty(DOMDocument $doc, string $name, string $value): void {
		$root = $doc->documentElement;
		$pid = 1;
		foreach ($root->getElementsByTagNameNS(self::OOXML_CUSTOM_NS, 'property') as $property) {
			if ($property instanceof DOMElement === false) {
				continue;
			}

			$pid = max($pid, (int)$property->getAttribute('pid'));
			if ($property->getAttribute('name') === $name) {
				while ($property->firstChild !== null) {
					$property->removeChild($property->firstChild);
				}

				$property->appendChild($doc->createElementNS(self::OOXML_VT_NS, 'vt:lpwstr', ''))->textContent = $value;
				return;
			}
		}

		$property = $doc->createElementNS(self::OOXML_CUSTOM_NS, 'property');
		$property->setAttribute('fmtid', self::OOXML_CUSTOM_FMTID);
		$property->setAttribute('pid', (string)($pid + 1));
		$property->setAttribute('name', $name);
		$property->appendChild($doc->createElementNS(self::OOXML_VT_NS, 'vt:lpwstr', ''))->textContent = $value;
		$root->appendChild($property);
	}//end setCustomProperty()

	/**
	 * Declare `docProps/custom.xml` in the package: its content type and its package relationship.
	 *
	 * @param ZipArchive $zip The open package.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When the content types or the relationships cannot be read.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	private function registerCustomPart(ZipArchive $zip): void {
		$types = $zip->getFromName('[Content_Types].xml');
		$rels = $zip->getFromName('_rels/.rels');
		if ($types === false || $rels === false) {
			throw new TitleNotWritten(self::NO_METADATA);
		}

		$typesDoc = self::parsed(xml: $types);
		$override = $typesDoc->createElementNS(self::OOXML_CONTENT_TYPES_NS, 'Override');
		$override->setAttribute('PartName', '/' . self::OOXML_CUSTOM);
		$override->setAttribute('ContentType', self::OOXML_CUSTOM_TYPE);
		$typesDoc->documentElement->appendChild($override);
		$zip->addFromString('[Content_Types].xml', (string)$typesDoc->saveXML());

		$relsDoc = self::parsed(xml: $rels);
		$ids = [];
		foreach ($relsDoc->getElementsByTagNameNS(self::OOXML_RELS_NS, 'Relationship') as $relationship) {
			if ($relationship instanceof DOMElement === true) {
				$ids[] = $relationship->getAttribute('Id');
			}
		}

		$number = (count($ids) + 1);
		while (in_array('rId' . $number, $ids, true) === true) {
			$number++;
		}

		$relationship = $relsDoc->createElementNS(self::OOXML_RELS_NS, 'Relationship');
		$relationship->setAttribute('Id', 'rId' . $number);
		$relationship->setAttribute('Type', self::OOXML_CUSTOM_REL);
		$relationship->setAttribute('Target', self::OOXML_CUSTOM);
		$relsDoc->documentElement->appendChild($relationship);
		$zip->addFromString('_rels/.rels', (string)$relsDoc->saveXML());
	}//end registerCustomPart()

	/**
	 * The package's metadata part and its XML: OOXML core properties, else ODF meta.xml.
	 *
	 * @param ZipArchive $zip The open package.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws TitleNotWritten When the package has neither.
	 */
	private static function metadataPart(ZipArchive $zip): array {
		foreach ([self::OOXML_CORE, self::ODF_META_PART] as $part) {
			$xml = $zip->getFromName($part);
			if ($xml !== false) {
				return [$part, $xml];
			}
		}

		throw new TitleNotWritten(self::NO_METADATA);
	}//end metadataPart()

	/**
	 * The metadata XML parsed without network access.
	 *
	 * @param string $xml The part's XML.
	 *
	 * @return DOMDocument
	 *
	 * @throws TitleNotWritten When it does not parse.
	 */
	private static function parsed(string $xml): DOMDocument {
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $doc->documentElement === null) {
			throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
		}

		return $doc;
	}//end parsed()

	/**
	 * A temporary file holding the package.
	 *
	 * @param string $bytes The office file.
	 *
	 * @return string Its path.
	 *
	 * @throws RuntimeException When no temporary file can be written.
	 */
	private static function tempFile(string $bytes): string {
		$path = tempnam(sys_get_temp_dir(), 'oc-title-');
		if ($path === false || file_put_contents($path, $bytes) === false) {
			throw new RuntimeException('No temporary file for the office package');
		}

		return $path;
	}//end tempFile()

	/**
	 * Set `dc:title` and the user-defined `identifier` inside `office:meta`.
	 *
	 * @param DOMDocument $doc        The parsed meta.xml.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When meta.xml has no `office:meta`.
	 */
	private function setOdfTitle(DOMDocument $doc, EmbeddedTitle $facts): void {
		$meta = $doc->getElementsByTagNameNS(self::ODF_OFFICE, 'meta')->item(0);
		if ($meta instanceof DOMElement === false) {
			throw new TitleNotWritten(self::NO_METADATA);
		}

		$this->element(parent: $meta, name: 'dc:title')->textContent = $facts->title;
		self::setOdfField(meta: $meta, name: 'identifier', value: $facts->identifier);
		foreach (self::publisherProperties(facts: $facts) as $name => $value) {
			self::setOdfField(meta: $meta, name: $name, value: $value);
		}
	}//end setOdfTitle()

	/**
	 * Set a user-defined field of an ODF meta element, adding it when absent.
	 *
	 * @param DOMElement $meta  The office:meta element.
	 * @param string     $name  The field name: `identifier`, `publisher` or `rsin`.
	 * @param string     $value Its value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	private static function setOdfField(DOMElement $meta, string $name, string $value): void {
		foreach ($meta->getElementsByTagNameNS(self::ODF_META, 'user-defined') as $field) {
			if ($field instanceof DOMElement && $field->getAttributeNS(self::ODF_META, 'name') === $name) {
				$field->textContent = $value;
				return;
			}
		}

		$field = $meta->ownerDocument->createElementNS(self::ODF_META, 'meta:user-defined');
		$field->setAttributeNS(self::ODF_META, 'meta:name', $name);
		$field->textContent = $value;
		$meta->appendChild($field);
	}//end setOdfField()

	/**
	 * The first child element with a name, made when there is none.
	 *
	 * @param DOMElement $parent The element to look in.
	 * @param string     $name   Its qualified name, in the Dublin Core namespace.
	 *
	 * @return DOMElement
	 */
	private function element(DOMElement $parent, string $name): DOMElement {
		$local = substr($name, (strpos($name, ':') + 1));
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === self::DC && $child->localName === $local) {
				return $child;
			}
		}

		$child = $parent->ownerDocument->createElementNS(self::DC, $name);
		$parent->appendChild($child);
		return $child;
	}//end element()
}//end class
