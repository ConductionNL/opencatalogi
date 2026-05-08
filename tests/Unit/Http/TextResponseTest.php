<?php

declare(strict_types=1);

namespace Unit\Http;

use OCA\OpenCatalogi\Http\TextResponse;
use PHPUnit\Framework\TestCase;

class TextResponseTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $response = new TextResponse();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('', $response->render());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testConstructorWithText(): void
    {
        $response = new TextResponse('Hello World');

        $this->assertSame('Hello World', $response->render());
    }

    public function testConstructorWithCustomStatus(): void
    {
        $response = new TextResponse('Not Found', 404);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Not Found', $response->render());
    }

    public function testConstructorWithCustomHeaders(): void
    {
        $response = new TextResponse('test', 200, ['X-Custom' => 'value']);

        $headers = $response->getHeaders();
        $this->assertSame('value', $headers['X-Custom']);
        $this->assertSame('text/plain; charset=utf-8', $headers['Content-Type']);
    }

    public function testRenderReturnsText(): void
    {
        $text = "Line 1\nLine 2\nLine 3";
        $response = new TextResponse($text);

        $this->assertSame($text, $response->render());
    }

    public function testRenderWithEmptyString(): void
    {
        $response = new TextResponse('');

        $this->assertSame('', $response->render());
    }

    public function testRenderWithSpecialCharacters(): void
    {
        $text = '<script>alert("xss")</script> & "quotes" \'single\'';
        $response = new TextResponse($text);

        $this->assertSame($text, $response->render());
    }

    public function testRenderWithUnicode(): void
    {
        $text = 'Ünïcödé tëxt with émojis 🎉';
        $response = new TextResponse($text);

        $this->assertSame($text, $response->render());
    }
}
