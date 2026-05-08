<?php

declare(strict_types=1);

namespace Unit\Http;

use OCA\OpenCatalogi\Http\XMLResponse;
use PHPUnit\Framework\TestCase;

class XMLResponseTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $response = new XMLResponse();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/xml; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testConstructorWithArrayData(): void
    {
        $data = ['key' => 'value'];
        $response = new XMLResponse($data);

        $this->assertSame(200, $response->getStatus());
    }

    public function testConstructorWithStringData(): void
    {
        $response = new XMLResponse('plain text');

        $xml = $response->render();
        $this->assertStringContainsString('plain text', $xml);
    }

    public function testConstructorWithCustomStatus(): void
    {
        $response = new XMLResponse([], 404);

        $this->assertSame(404, $response->getStatus());
    }

    public function testConstructorWithCustomHeaders(): void
    {
        $response = new XMLResponse([], 200, ['X-Custom' => 'val']);

        $headers = $response->getHeaders();
        $this->assertSame('val', $headers['X-Custom']);
    }

    public function testConstructorWithXmlPathAddsContentDisposition(): void
    {
        $response = new XMLResponse([], 200, [], '/export/data.xml');

        $headers = $response->getHeaders();
        $this->assertSame('attachment; filename="export.xml"', $headers['Content-Disposition']);
    }

    public function testConstructorWithNonXmlPathNoContentDisposition(): void
    {
        $response = new XMLResponse([], 200, [], '/export/data.json');

        $headers = $response->getHeaders();
        $this->assertArrayNotHasKey('Content-Disposition', $headers);
    }

    public function testRenderWithRootKey(): void
    {
        $data = [
            '@root' => 'catalog',
            'name'  => 'Test Catalog',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('<catalog>', $xml);
        $this->assertStringContainsString('<name>Test Catalog</name>', $xml);
        $this->assertStringContainsString('</catalog>', $xml);
    }

    public function testRenderWithoutRootKeyUsesDefault(): void
    {
        $data = ['name' => 'Test'];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('<response>', $xml);
    }

    public function testRenderWithAttributes(): void
    {
        $data = [
            '@root'       => 'item',
            '@attributes' => ['xmlns' => 'http://example.com'],
            'name'        => 'Test',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('xmlns="http://example.com"', $xml);
    }

    public function testRenderWithTextContent(): void
    {
        $data = [
            '@root'       => 'element',
            '@attributes' => ['id' => '1'],
            '#text'       => 'Hello World',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('Hello World', $xml);
        $this->assertStringContainsString('id="1"', $xml);
    }

    public function testRenderWithNestedArrays(): void
    {
        $data = [
            '@root' => 'root',
            'items' => [
                ['name' => 'Item 1'],
                ['name' => 'Item 2'],
            ],
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('<items>', $xml);
        $this->assertStringContainsString('Item 1', $xml);
        $this->assertStringContainsString('Item 2', $xml);
    }

    public function testSetRenderCallback(): void
    {
        $response = new XMLResponse(['test' => 'data']);
        $result = $response->setRenderCallback(function ($data) {
            return '<custom>xml</custom>';
        });

        $this->assertSame($response, $result);
        $this->assertSame('<custom>xml</custom>', $response->render());
    }

    public function testArrayToXmlWithCustomRoot(): void
    {
        $response = new XMLResponse();
        $xml = $response->arrayToXml(['name' => 'Test'], 'myRoot');

        $this->assertStringContainsString('<myRoot>', $xml);
        $this->assertStringContainsString('<name>Test</name>', $xml);
    }

    public function testArrayToXmlWithAtRootKey(): void
    {
        $response = new XMLResponse();
        $xml = $response->arrayToXml(['@root' => 'custom', 'data' => 'val']);

        $this->assertStringContainsString('<custom>', $xml);
        $this->assertStringContainsString('<data>val</data>', $xml);
    }

    public function testRenderWithCarriageReturns(): void
    {
        $data = [
            '@root' => 'doc',
            'text'  => "line1\r\nline2",
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('&#xD;', $xml);
    }

    public function testRenderWithHtmlEntities(): void
    {
        $data = [
            '@root' => 'doc',
            'text'  => 'A &amp; B',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('A &amp; B', $xml);
    }

    public function testRenderWithEmptyData(): void
    {
        $response = new XMLResponse([]);
        $xml = $response->render();

        $this->assertStringContainsString('<?xml', $xml);
    }

    public function testRenderXmlDeclaration(): void
    {
        $response = new XMLResponse(['@root' => 'test']);
        $xml = $response->render();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    public function testRenderWithNumericKeys(): void
    {
        $data = [
            '@root' => 'root',
            '0'     => 'first',
            '1'     => 'second',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        // Numeric keys get prefixed with "item".
        $this->assertStringContainsString('<item0>first</item0>', $xml);
        $this->assertStringContainsString('<item1>second</item1>', $xml);
    }

    public function testRenderWithAtPrefixedKeys(): void
    {
        $data = [
            '@root'  => 'root',
            '@field' => 'value',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        // @ prefix is stripped from element names.
        $this->assertStringContainsString('<field>value</field>', $xml);
    }

    public function testRenderWithObjectWithToString(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'stringified';
            }
        };
        $data = [
            '@root' => 'root',
            'item'  => $obj,
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('<item>stringified</item>', $xml);
    }

    public function testRenderWithObjectWithoutToString(): void
    {
        $obj = new \stdClass();
        $data = [
            '@root' => 'root',
            'item'  => $obj,
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('[Object of class stdClass]', $xml);
    }

    public function testRenderWithAssociativeSubArray(): void
    {
        $data = [
            '@root'   => 'root',
            'details' => [
                'name' => 'Test',
                'age'  => '30',
            ],
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        $this->assertStringContainsString('<details>', $xml);
        $this->assertStringContainsString('<name>Test</name>', $xml);
        $this->assertStringContainsString('<age>30</age>', $xml);
    }

    public function testRenderWithNullRootTag(): void
    {
        $response = new XMLResponse();
        $xml = $response->arrayToXml(['name' => 'Test']);

        // Should use default 'root' when no rootTag or @root provided.
        $this->assertStringContainsString('<root>', $xml);
    }

    public function testConstructorWithExistingContentDisposition(): void
    {
        // If Content-Disposition is already set, it should not be overwritten.
        $response = new XMLResponse([], 200, ['Content-Disposition' => 'inline'], '/data.xml');

        $headers = $response->getHeaders();
        $this->assertSame('inline', $headers['Content-Disposition']);
    }

    public function testRenderWithEmptyTagSelfClosing(): void
    {
        $data = [
            '@root' => 'root',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        // Should contain XML declaration and root element.
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('root', $xml);
    }

    public function testRenderWithHtmlEntityDecoding(): void
    {
        $data = [
            '@root' => 'doc',
            'text'  => '&lt;tag&gt;',
        ];
        $response = new XMLResponse($data);
        $xml = $response->render();

        // html_entity_decode converts &lt; to < which then gets XML-encoded.
        $this->assertStringContainsString('&lt;tag&gt;', $xml);
    }
}
