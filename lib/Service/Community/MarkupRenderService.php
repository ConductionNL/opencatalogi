<?php

/**
 * OpenCatalogi Markup Render Service.
 *
 * One endpoint, in and out, no side effect: a client posts this app's markup
 * dialect and gets back the HTML this app renders for it. A mobile client that
 * renders its own markdown shows something different from the website, and the
 * difference is a defect report nobody can reproduce.
 *
 * The dialect is deliberately small and is documented in
 * `docs/features/public-and-community-surface.md`. Every character of input is
 * escaped before any markup is applied, so nothing a caller sends can reach the
 * output as HTML. That matters more here than anywhere else in this app: this
 * endpoint takes text from anyone and hands back markup a client will render.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Community
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
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

/**
 * Renders this app's markup dialect to HTML, and stores nothing.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
 */
class MarkupRenderService {

	/**
	 * The largest body this endpoint renders.
	 *
	 * @var integer
	 */
	public const MAX_LENGTH = 64000;

	/**
	 * The schemes a link may use.
	 *
	 * Anything else, `javascript:` above all, renders as text rather than as a
	 * link. A renderer that emits whatever scheme it was handed turns every
	 * client of this endpoint into a way to run somebody else's code.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

	/**
	 * Render a body of markup.
	 *
	 * @param string $markup The body.
	 *
	 * @return array{html: string, truncated: boolean}
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
	 */
	public function render(string $markup): array {
		$truncated = false;
		if (strlen($markup) > self::MAX_LENGTH) {
			$markup = substr($markup, 0, self::MAX_LENGTH);
			$truncated = true;
		}

		$blocks = preg_split('/\R{2,}/', str_replace("\r\n", "\n", $markup));
		if (is_array($blocks) === false) {
			$blocks = [$markup];
		}

		$html = [];
		foreach ($blocks as $block) {
			$block = trim($block);
			if ($block === '') {
				continue;
			}

			$html[] = $this->renderBlock(block: $block);
		}

		return [
			'html' => implode("\n", $html),
			'truncated' => $truncated,
		];

	}//end render()

	/**
	 * Render one block.
	 *
	 * @param string $block The block.
	 *
	 * @return string The HTML.
	 */
	private function renderBlock(string $block): string {
		$lines = explode("\n", $block);

		if (str_starts_with($block, '```') === true) {
			$code = implode("\n", array_slice($lines, 1, (count($lines) - 1)));
			$code = preg_replace('/\n?```\s*$/', '', $code);

			return '<pre><code>' . $this->escape(text: (string)$code) . '</code></pre>';
		}

		if (preg_match('/^(#{1,6})\s+(.*)$/', $lines[0], $matches) === 1) {
			$level = strlen($matches[1]);

			return '<h' . $level . '>' . $this->inline(text: $matches[2]) . '</h' . $level . '>';
		}

		if ($this->everyLineStartsWith(lines: $lines, prefix: '> ') === true) {
			$quoted = array_map(static fn (string $line): string => substr($line, 2), $lines);

			return '<blockquote><p>' . $this->inline(text: implode(' ', $quoted)) . '</p></blockquote>';
		}

		if ($this->isList(lines: $lines, ordered: false) === true) {
			return $this->renderList(lines: $lines, tag: 'ul', pattern: '/^[-*]\s+/');
		}

		if ($this->isList(lines: $lines, ordered: true) === true) {
			return $this->renderList(lines: $lines, tag: 'ol', pattern: '/^\d+\.\s+/');
		}

		return '<p>' . $this->inline(text: implode(' ', array_map('trim', $lines))) . '</p>';

	}//end renderBlock()

	/**
	 * Whether every line of a block starts with a prefix.
	 *
	 * @param array<int, string> $lines The lines.
	 * @param string $prefix The prefix.
	 *
	 * @return boolean True when every line does.
	 */
	private function everyLineStartsWith(array $lines, string $prefix): bool {
		foreach ($lines as $line) {
			if (str_starts_with($line, $prefix) === false) {
				return false;
			}
		}

		return ($lines !== []);

	}//end everyLineStartsWith()

	/**
	 * Whether a block is a list.
	 *
	 * @param array<int, string> $lines The lines.
	 * @param boolean $ordered Whether to test for an ordered list.
	 *
	 * @return boolean True when every line is an item.
	 */
	private function isList(array $lines, bool $ordered): bool {
		$pattern = '/^[-*]\s+/';
		if ($ordered === true) {
			$pattern = '/^\d+\.\s+/';
		}

		foreach ($lines as $line) {
			if (preg_match($pattern, $line) !== 1) {
				return false;
			}
		}

		return ($lines !== []);

	}//end isList()

	/**
	 * Render a list.
	 *
	 * @param array<int, string> $lines The lines.
	 * @param string $tag The list tag.
	 * @param string $pattern The item prefix pattern.
	 *
	 * @return string The HTML.
	 */
	private function renderList(array $lines, string $tag, string $pattern): string {
		$items = [];
		foreach ($lines as $line) {
			$items[] = '<li>' . $this->inline(text: (string)preg_replace($pattern, '', $line)) . '</li>';
		}

		return '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';

	}//end renderList()

	/**
	 * Escape every character that means something in HTML.
	 *
	 * @param string $text The text.
	 *
	 * @return string The escaped text.
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), 'UTF-8');

	}//end escape()

	/**
	 * Render the inline constructs inside already-escaped text.
	 *
	 * The escaping happens first, so a caller's `<script>` is `&lt;script&gt;`
	 * before any markup rule sees it and cannot become a tag afterwards.
	 *
	 * @param string $text The text.
	 *
	 * @return string The HTML.
	 */
	private function inline(string $text): string {
		$escaped = $this->escape(text: $text);

		$escaped = (string)preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
		$escaped = (string)preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
		$escaped = (string)preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped);

		return (string)preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function (array $matches): string {
				$href = html_entity_decode($matches[2], (ENT_QUOTES | ENT_HTML5), 'UTF-8');
				$scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));

				if ($scheme !== '' && in_array($scheme, self::ALLOWED_SCHEMES, true) === false) {
					// Rendered as the text it is, never as a link. A scheme this
					// app does not allow is not a link it renders more
					// carefully; it is not a link.
					return $matches[1] . ' (' . $this->escape(text: $href) . ')';
				}

				return '<a href="' . $this->escape(text: $href) . '" rel="nofollow noopener">' . $matches[1] . '</a>';
			},
			$escaped
		);

	}//end inline()
}//end class
