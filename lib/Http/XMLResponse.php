<?php

/**
 * OpenCatalogi XML Response.
 *
 * A response class for rendering data as XML output.
 *
 * @category Http
 * @package  OCA\OpenCatalogi\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Http;

use DOMDocument;
use DOMElement;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A response for XML data.
 *
 * @psalm-suppress MissingTemplateParam
 * @psalm-suppress PropertyNotSetInConstructor
 */
class XMLResponse extends Response {

	/**
	 * The data to be returned.
	 *
	 * @var array<string, mixed> The data to be returned
	 */
	protected array $data;

	/**
	 * Custom render callback.
	 *
	 * @var callable|null Custom render callback
	 */
	protected $renderCallback = null;

	/**
	 * Constructor for XMLResponse.
	 *
	 * @param array<string, mixed>|string $data The data to convert to XML
	 * @param integer $status HTTP status code, defaults to 200
	 * @param array<string, string> $headers Custom headers to add to the response
	 * @param string|null $path The request path for download header
	 *
	 * @psalm-param array<string, mixed>|string $data
	 * @psalm-param int $status
	 * @psalm-param array<string, string> $headers
	 * @psalm-param string|null $path
	 */
	public function __construct($data = [], int $status = 200, array $headers = [], ?string $path = null) {
		// @phpstan-ignore argument.type
		parent::__construct(status: $status);

		// Set response data.
		$this->data = ['content' => $data];
		if (is_array($data) === true) {
			$this->data = $data;
		}

		// Set headers.
		foreach ($headers as $name => $value) {
			$this->addHeader(name: $name, value: $value);
		}

		// Set content type header.
		$this->addHeader(name: 'Content-Type', value: 'application/xml; charset=utf-8');

		// Only add Content-Disposition header if path ends with .xml.
		if ($path !== null
			&& str_ends_with($path, '.xml') === true
			&& isset($this->getHeaders()['Content-Disposition']) === false
		) {
			$this->addHeader(name: 'Content-Disposition', value: 'attachment; filename="export.xml"');
		}

	}//end __construct()

	/**
	 * Get the data for rendering.
	 *
	 * @return array<string, mixed> The data for rendering
	 */
	protected function getData(): array {
		return ['value' => $this->data];
	}//end getData()

	/**
	 * Set custom render callback.
	 *
	 * @param callable $callback Function that takes data array and returns XML string
	 *
	 * @return $this
	 *
	 * @psalm-param callable(array<string, mixed>): string $callback
	 */
	public function setRenderCallback(callable $callback): self {
		$this->renderCallback = $callback;
		return $this;
	}//end setRenderCallback()

	/**
	 * Returns the rendered XML.
	 *
	 * @return string The rendered XML
	 */
	public function render(): string {
		if ($this->renderCallback !== null) {
			return ($this->renderCallback)($this->getData());
		}

		$data = $this->getData()['value'];

		// Check if data contains an @root key and use it directly.
		if (isset($data['@root']) === true) {
			return $this->arrayToXml(data: $data);
		}

		// Use default root tag.
		return $this->arrayToXml(data: ['value' => $data], rootTag: 'response');
	}//end render()

	/**
	 * Convert an array to XML.
	 *
	 * @param array<string, mixed> $data The data to convert
	 * @param string|null $rootTag Optional root tag name (overrides @root in data)
	 *
	 * @return string The XML string or empty string on failure
	 *
	 * @psalm-param  array<string, mixed> $data
	 * @psalm-return string
	 */
	public function arrayToXml(array $data, ?string $rootTag = null): string {
		// Extract root tag from data or use provided root tag.
		$rootName = ($rootTag ?? ($data['@root'] ?? 'root'));

		// Remove @root if it exists in data since we have extracted it.
		if (isset($data['@root']) === true) {
			unset($data['@root']);
		}

		// Create new DOM document.
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		// Create root element.
		$root = $dom->createElement($rootName);
		if ($root === false) {
			// Failed to create root element.
			return '';
		}

		$dom->appendChild($root);

		// Build XML structure.
		$this->buildXmlElement(element: $root, data: $data);

		// Get XML output.
		$xmlOutput = $dom->saveXML();
		if ($xmlOutput === false) {
			$xmlOutput = '';
		}

		// Directly replace decimal CR entities with hexadecimal.
		$xmlOutput = str_replace('&#13;', '&#xD;', $xmlOutput);

		// Format empty tags to have a space before the closing bracket.
		$xmlOutput = preg_replace('/<([^>]*)\/>/', '<$1 />', $xmlOutput);

		return $xmlOutput;
	}//end arrayToXml()

	/**
	 * Build an XML element with attributes and children in order.
	 *
	 * @param DOMElement $element The element to populate
	 * @param array<string, mixed> $data The data to convert
	 *
	 * @return void
	 *
	 * @psalm-param DOMElement $element
	 * @psalm-param array<string, mixed> $data
	 */
	private function buildXmlElement(DOMElement $element, array $data): void {
		$data = $this->applyAttributes(element: $element, data: $data);
		$data = $this->applyText(element: $element, data: $data);

		foreach ($data as $key => $value) {
			$name = $this->childName(key: $key);
			foreach ($this->childValues(value: $value) as $item) {
				$childElement = $element->ownerDocument->createElement($name);
				if ($childElement !== false) {
					$element->appendChild($childElement);
					$this->fillElement(element: $childElement, data: $item);
				}
			}
		}

	}//end buildXmlElement()

	/**
	 * Set the `@attributes` of the data on the element, in their order.
	 *
	 * @param DOMElement $element The element to populate
	 * @param array<string, mixed> $data The data to convert
	 *
	 * @return array<string, mixed> The data without `@attributes` once they are set.
	 */
	private function applyAttributes(DOMElement $element, array $data): array {
		if (isset($data['@attributes']) === false || is_array($data['@attributes']) === false) {
			return $data;
		}

		foreach ($data['@attributes'] as $attrKey => $attrValue) {
			$element->setAttribute($attrKey, (string)$attrValue);
		}

		unset($data['@attributes']);

		return $data;
	}//end applyAttributes()

	/**
	 * Append the `#text` of the data to the element.
	 *
	 * @param DOMElement $element The element to populate
	 * @param array<string, mixed> $data The data to convert
	 *
	 * @return array<string, mixed> The data without `#text` once it is appended.
	 */
	private function applyText(DOMElement $element, array $data): array {
		if (isset($data['#text']) === true) {
			$element->appendChild($this->createSafeTextNode(element: $element, text: (string)$data['#text']));
			unset($data['#text']);
		}

		return $data;
	}//end applyText()

	/**
	 * The element name for a key: without leading `@`, and `item<n>` for a numeric key.
	 *
	 * @param int|string $key The key.
	 *
	 * @return string The element name.
	 */
	private function childName(int|string $key): string {
		$key = ltrim((string)$key, '@');
		if (is_numeric($key) === true) {
			return "item$key";
		}

		return $key;
	}//end childName()

	/**
	 * The values a key becomes elements for: one per item of an indexed list
	 * of arrays (multiple elements with the same name), else the value itself.
	 *
	 * @param mixed $value The value of a key.
	 *
	 * @return array<mixed>
	 */
	private function childValues(mixed $value): array {
		if (is_array($value) === true && isset($value[0]) === true && is_array($value[0]) === true) {
			return $value;
		}

		return [$value];
	}//end childValues()

	/**
	 * Populate a child element with an array, an object or a scalar.
	 *
	 * @param DOMElement $element The child element
	 * @param mixed $data The data for the child element
	 *
	 * @return void
	 */
	private function fillElement(DOMElement $element, mixed $data): void {
		if (is_array($data) === true) {
			$this->buildXmlElement(element: $element, data: $data);
			return;
		}

		// Handle objects that might not be convertible to string directly.
		if (is_object($data) === true) {
			$data = $this->convertObjectToString(data: $data);
		}

		$element->appendChild($this->createSafeTextNode(element: $element, text: (string)$data));

	}//end fillElement()

	/**
	 * Convert an object to its string representation.
	 *
	 * @param object $data The object to convert
	 *
	 * @return string The string representation
	 */
	private function convertObjectToString(object $data): string {
		// For QueryBuilder objects or objects without __toString() create a placeholder.
		if ($data instanceof IQueryBuilder
			|| method_exists($data, '__toString') === false
		) {
			return '[Object of class ' . get_class($data) . ']';
		}

		return (string)$data;
	}//end convertObjectToString()

	/**
	 * Process text content safely.
	 *
	 * @param DOMElement $element The element the node is for
	 * @param string $text The text to create a node for
	 *
	 * @return \DOMNode The created node
	 *
	 * @psalm-param  DOMElement $element
	 * @psalm-param  string $text
	 * @psalm-return \DOMNode
	 */
	private function createSafeTextNode(DOMElement $element, string $text): \DOMNode {
		// Decode any HTML entities to prevent double encoding.
		// First decode things like &amp; into &.
		$decodedText = html_entity_decode($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
		// Then decode again to handle cases like &#039; into a single quote.
		$decodedText = html_entity_decode($decodedText, (ENT_QUOTES | ENT_HTML5), 'UTF-8');

		// Create a text node with the processed text.
		// Carriage returns will be encoded as decimal entities (&#13;) which are
		// later converted to hexadecimal (&#xD;) in the arrayToXml method.
		return $element->ownerDocument->createTextNode($decodedText);
	}//end createSafeTextNode()
}//end class
