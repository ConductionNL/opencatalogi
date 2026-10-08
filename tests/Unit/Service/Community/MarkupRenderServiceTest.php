<?php

declare(strict_types=1);

namespace Unit\Service\Community;

use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MarkupRenderService.
 *
 * This endpoint takes text from anyone and hands back markup a client will
 * render, so most of these tests are about what cannot come out of it.
 *
 * @covers \OCA\OpenCatalogi\Service\Community\MarkupRenderService
 */
class MarkupRenderServiceTest extends TestCase {

	private MarkupRenderService $service;

	protected function setUp(): void {
		$this->service = new MarkupRenderService();

	}//end setUp()

	public function testTheDocumentedConstructsRender(): void {
		$rendered = $this->service->render(
			markup: "# Kop\n\nEen **vette** en een *schuine* tekst met `code`.\n\n- een\n- twee\n\n1. eerst\n2. dan\n\n> een citaat"
		);

		$html = $rendered['html'];

		$this->assertStringContainsString('<h1>Kop</h1>', $html);
		$this->assertStringContainsString('<strong>vette</strong>', $html);
		$this->assertStringContainsString('<em>schuine</em>', $html);
		$this->assertStringContainsString('<code>code</code>', $html);
		$this->assertStringContainsString('<ul><li>een</li><li>twee</li></ul>', $html);
		$this->assertStringContainsString('<ol><li>eerst</li><li>dan</li></ol>', $html);
		$this->assertStringContainsString('<blockquote><p>een citaat</p></blockquote>', $html);

	}//end testTheDocumentedConstructsRender()

	public function testHeadingLevelsAreRespected(): void {
		$this->assertStringContainsString('<h3>Derde</h3>', $this->service->render(markup: '### Derde')['html']);

	}//end testHeadingLevelsAreRespected()

	public function testALinkRendersWithItsHref(): void {
		$html = $this->service->render(markup: 'Zie [de site](https://example.org).')['html'];

		$this->assertStringContainsString('<a href="https://example.org" rel="nofollow noopener">de site</a>', $html);

	}//end testALinkRendersWithItsHref()

	/**
	 * The failure that matters on an endpoint anyone can post to: HTML in the
	 * input reaching the output as HTML.
	 */
	public function testMarkupInTheInputNeverBecomesMarkupInTheOutput(): void {
		$html = $this->service->render(markup: '<script>alert(1)</script>')['html'];

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);

	}//end testMarkupInTheInputNeverBecomesMarkupInTheOutput()

	public function testAnImageTagInTheInputIsText(): void {
		$html = $this->service->render(markup: '<img src=x onerror=alert(1)>')['html'];

		$this->assertStringNotContainsString('<img', $html);

	}//end testAnImageTagInTheInputIsText()

	/**
	 * A renderer that emits whatever scheme it was handed turns every client of
	 * this endpoint into a way to run somebody else's code.
	 */
	public function testAJavascriptLinkIsRenderedAsTextAndNotAsALink(): void {
		$html = $this->service->render(markup: '[klik](javascript:alert(1))')['html'];

		$this->assertStringNotContainsString('<a ', $html);
		$this->assertStringContainsString('klik', $html);

	}//end testAJavascriptLinkIsRenderedAsTextAndNotAsALink()

	public function testADataUrlLinkIsRenderedAsTextAndNotAsALink(): void {
		$html = $this->service->render(markup: '[klik](data:text/html;base64,PHNjcmlwdD4=)')['html'];

		$this->assertStringNotContainsString('<a ', $html);

	}//end testADataUrlLinkIsRenderedAsTextAndNotAsALink()

	public function testAMailtoLinkIsAllowed(): void {
		$html = $this->service->render(markup: '[mail](mailto:info@example.org)')['html'];

		$this->assertStringContainsString('<a href="mailto:info@example.org"', $html);

	}//end testAMailtoLinkIsAllowed()

	public function testARelativeLinkIsAllowed(): void {
		$html = $this->service->render(markup: '[hier](/apps/opencatalogi)')['html'];

		$this->assertStringContainsString('<a href="/apps/opencatalogi"', $html);

	}//end testARelativeLinkIsAllowed()

	public function testACodeBlockKeepsItsContentAsText(): void {
		$html = $this->service->render(markup: "```\n<b>vet</b>\n```")['html'];

		$this->assertStringContainsString('<pre><code>&lt;b&gt;vet&lt;/b&gt;</code></pre>', $html);

	}//end testACodeBlockKeepsItsContentAsText()

	public function testRenderingIsAPureFunctionOfItsInput(): void {
		$markup = "# Kop\n\nTekst met [een link](https://example.org).";

		$this->assertSame(
			$this->service->render(markup: $markup),
			$this->service->render(markup: $markup)
		);

	}//end testRenderingIsAPureFunctionOfItsInput()

	public function testAnOverlongBodyIsTruncatedAndSaysSo(): void {
		$rendered = $this->service->render(markup: str_repeat('a', (MarkupRenderService::MAX_LENGTH + 10)));

		$this->assertTrue($rendered['truncated']);
		$this->assertFalse($this->service->render(markup: 'kort')['truncated']);

	}//end testAnOverlongBodyIsTruncatedAndSaysSo()

	public function testAnEmptyBodyRendersNothingRatherThanAnEmptyParagraph(): void {
		$this->assertSame('', $this->service->render(markup: "   \n\n  ")['html']);

	}//end testAnEmptyBodyRendersNothingRatherThanAnEmptyParagraph()
}//end class
