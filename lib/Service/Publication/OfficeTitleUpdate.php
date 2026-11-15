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
 * Only that one part of the package changes.
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
	 * @param string $title      The official title.
	 * @param string $identifier The publication identifier.
	 *
	 * @return string The changed package.
	 *
	 * @throws TitleNotWritten When it is not an office package or its metadata part cannot be read.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function apply(string $bytes, string $title, string $identifier): string {
		$path = tempnam(sys_get_temp_dir(), 'oc-title-');
		if ($path === false || file_put_contents($path, $bytes) === false) {
			throw new RuntimeException('No temporary file for the office package');
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
			}

			$this->setTitle(zip: $zip, title: $title, identifier: $identifier);
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
	 * @param string     $title      The official title.
	 * @param string     $identifier The publication identifier.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When the package has neither part, or it is not readable XML.
	 */
	private function setTitle(ZipArchive $zip, string $title, string $identifier): void {
		$part = self::OOXML_CORE;
		$xml = $zip->getFromName($part);
		if ($xml === false) {
			$part = self::ODF_META_PART;
			$xml = $zip->getFromName($part);
		}

		if ($xml === false) {
			throw new TitleNotWritten(self::NO_METADATA);
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $doc->documentElement === null) {
			throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
		}

		if ($part === self::OOXML_CORE) {
			$this->element(parent: $doc->documentElement, namespace: self::DC, name: 'dc:title')->textContent = $title;
			$this->element(parent: $doc->documentElement, namespace: self::DC, name: 'dc:identifier')->textContent = $identifier;
		}

		if ($part === self::ODF_META_PART) {
			$this->setOdfTitle(doc: $doc, title: $title, identifier: $identifier);
		}

		$zip->addFromString($part, (string)$doc->saveXML());
	}//end setTitle()

	/**
	 * Set `dc:title` and the user-defined `identifier` inside `office:meta`.
	 *
	 * @param DOMDocument $doc        The parsed meta.xml.
	 * @param string      $title      The official title.
	 * @param string      $identifier The publication identifier.
	 *
	 * @return void
	 *
	 * @throws TitleNotWritten When meta.xml has no `office:meta`.
	 */
	private function setOdfTitle(DOMDocument $doc, string $title, string $identifier): void {
		$meta = $doc->getElementsByTagNameNS(self::ODF_OFFICE, 'meta')->item(0);
		if ($meta instanceof DOMElement === false) {
			throw new TitleNotWritten(self::NO_METADATA);
		}

		$this->element(parent: $meta, namespace: self::DC, name: 'dc:title')->textContent = $title;
		foreach ($meta->getElementsByTagNameNS(self::ODF_META, 'user-defined') as $field) {
			if ($field instanceof DOMElement && $field->getAttributeNS(self::ODF_META, 'name') === 'identifier') {
				$field->textContent = $identifier;
				return;
			}
		}

		$field = $doc->createElementNS(self::ODF_META, 'meta:user-defined');
		$field->setAttributeNS(self::ODF_META, 'meta:name', 'identifier');
		$field->textContent = $identifier;
		$meta->appendChild($field);
	}//end setOdfTitle()

	/**
	 * The first child element with a name, made when there is none.
	 *
	 * @param DOMElement $parent    The element to look in.
	 * @param string     $namespace The child's namespace.
	 * @param string     $name      Its qualified name.
	 *
	 * @return DOMElement
	 */
	private function element(DOMElement $parent, string $namespace, string $name): DOMElement {
		$local = substr($name, (strpos($name, ':') + 1));
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === $namespace && $child->localName === $local) {
				return $child;
			}
		}

		$child = $parent->ownerDocument->createElementNS($namespace, $name);
		$parent->appendChild($child);
		return $child;
	}//end element()
}//end class
