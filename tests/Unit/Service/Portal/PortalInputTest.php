<?php

/**
 * Tests for the resident input checks of the portal (PortalInput).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Service\Portal\PortalInput;
use PHPUnit\Framework\TestCase;

/**
 * PortalInput: text is trimmed within a length, a file id is digits only.
 *
 * @covers \OCA\OpenCatalogi\Service\Portal\PortalInput
 * @uses   \OCA\OpenCatalogi\Exception\PortalInputException
 */
class PortalInputTest extends TestCase {

	/**
	 * Expect a refusal with exactly this message.
	 *
	 * @param callable $call    The check.
	 * @param string   $message The message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $message): void {
		try {
			$call();
			$this->fail('Expected refusal: ' . $message);
		} catch (PortalInputException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}

	public function testNullTextIsEmptyAndTextIsTrimmed(): void {
		$input = new PortalInput();

		$this->assertSame('', $input->text(value: null, max: 5, field: 'title'));
		$this->assertSame('abcde', $input->text(value: "  abcde \n", max: 5, field: 'title'));
		$this->assertSame('ééééé', $input->text(value: 'ééééé', max: 5, field: 'title'));
	}

	public function testTextThatIsNotAStringOrTooLongIsRefusedByName(): void {
		$input = new PortalInput();

		$this->assertRefused(fn () => $input->text(value: 12, max: 5, field: 'title'), 'title must be text');
		$this->assertRefused(fn () => $input->text(value: ['x'], max: 5, field: 'note'), 'note must be text');
		$this->assertRefused(fn () => $input->text(value: 'abcdef', max: 5, field: 'title'), 'title is too long');
	}

	public function testAnAttachmentIsAFileIdOrNothing(): void {
		$input = new PortalInput();

		$this->assertNull($input->attachment(value: null));
		$this->assertNull($input->attachment(value: ''));
		$this->assertSame('4711', $input->attachment(value: '4711'));
		$this->assertSame('4711', $input->attachment(value: 4711));
		$this->assertSame(str_repeat('9', 20), $input->attachment(value: str_repeat('9', 20)));

		foreach (['../etc', '12a', str_repeat('9', 21), 4.5, ['1'], true] as $value) {
			$this->assertRefused(fn () => $input->attachment(value: $value), 'attachment must be a file id');
		}
	}
}
