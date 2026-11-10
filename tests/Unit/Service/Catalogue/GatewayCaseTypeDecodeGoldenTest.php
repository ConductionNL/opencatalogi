<?php

/**
 * Golden test for how the gateway case type reader decodes the gateway's
 * answer: a JSON string, an array carrying a JSON `body`, a decoded array,
 * and anything it cannot read.
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
 */

declare(strict_types=1);

namespace Unit\Service\Catalogue;

use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every answer shape GatewayCaseTypeSourceReader::decode() is given.
 */
class GatewayCaseTypeDecodeGoldenTest extends TestCase {

	/**
	 * Answers and what each decodes to.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>|string}>
	 */
	public static function answers(): array {
		$refused = 'refused: The external case type catalogue answered something this app cannot read.';
		return [
			'json string' => ['{"title":"Vergunning"}', ['title' => 'Vergunning']],
			'json list' => ['[1,2]', [1, 2]],
			'json scalar' => ['"text"', $refused],
			'not json' => ['<html>', $refused],
			'empty string' => ['', $refused],
			'array with a json body' => [['body' => '{"a":1}', 'status' => 200], ['a' => 1]],
			'array with an unreadable body' => [['body' => 'nope', 'status' => 200], ['body' => 'nope', 'status' => 200]],
			'array with a scalar json body' => [['body' => '5'], ['body' => '5']],
			'array with a non-text body' => [['body' => ['a' => 1]], ['body' => ['a' => 1]]],
			'decoded array' => [['title' => 'Melding'], ['title' => 'Melding']],
			'null' => [null, $refused],
			'object' => [new \stdClass(), $refused],
		];
	}

	/**
	 * What one answer decodes to.
	 *
	 * @param mixed $answer The gateway's answer.
	 * @param array<string, mixed>|string $expected The decoded answer, or the refusal.
	 *
	 * @return void
	 */
	#[DataProvider('answers')]
	public function testDecode(mixed $answer, array|string $expected): void {
		$reader = new GatewayCaseTypeSourceReader($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));
		$method = new ReflectionMethod($reader, 'decode');
		$method->setAccessible(true);
		try {
			$actual = $method->invoke($reader, $answer);
		} catch (ExternalCatalogueUnreachableException $e) {
			$actual = 'refused: ' . $e->getMessage();
		}

		$this->assertSame($expected, $actual);
	}
}
