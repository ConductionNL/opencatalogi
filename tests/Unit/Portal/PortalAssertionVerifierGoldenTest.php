<?php

/**
 * Golden test for PortalAssertionVerifier::verify().
 *
 * Every case verifies one assertion and compares the verdict (accepted with its
 * subject, or rejected) and the logged rejection reason to the recording in
 * fixtures/portal-assertion-verifier-golden.json.
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

namespace Unit\Portal;

use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for PortalAssertionVerifier::verify().
 *
 * @covers \OCA\OpenCatalogi\Portal\PortalAssertionVerifier
 */
class PortalAssertionVerifierGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/portal-assertion-verifier-golden.json';

	private const SECRET = 'a-dedicated-secret-of-enough-length';

	/**
	 * The recorded cases: claim overrides, header overrides, and how the token is built.
	 *
	 * Times are offsets from now, so `['exp' => 0]` means `exp = now`.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'valid' => [[]],
			'iat at the leeway edge' => [['claims' => ['iat' => 60, 'exp' => 120]]],
			'iat past the leeway' => [['claims' => ['iat' => 90, 'exp' => 120]]],
			'iat after exp' => [['claims' => ['iat' => 30, 'exp' => 20]]],
			'iat as text' => [['claims' => ['iat' => 'now']]],
			'iat missing' => [['claims' => ['iat' => null]]],
			'exp now' => [['claims' => ['exp' => 0]]],
			'exp as text' => [['claims' => ['exp' => '60']]],
			'exp missing, subject missing too' => [['claims' => ['exp' => null, 'sub' => null]]],
			'session token' => [['claims' => ['use' => 'session']]],
			'other issuer' => [['claims' => ['iss' => 'other']]],
			'no use claim' => [['claims' => ['use' => null]]],
			'empty subject' => [['claims' => ['sub' => '']]],
			'numeric subject' => [['claims' => ['sub' => 7]]],
			'alg none' => [['header' => ['alg' => 'none']]],
			'no alg' => [['header' => ['alg' => null]]],
			'header not json' => [['raw' => 'header']],
			'claims not json' => [['raw' => 'claims']],
			'claims a scalar' => [['raw' => 'scalar']],
			'other secret' => [['secret' => 'another-secret-of-enough-length']],
			'tampered signature' => [['raw' => 'signature']],
			'subject swapped after signing' => [['raw' => 'swapped']],
			'two parts' => [['raw' => 'two']],
			'four parts' => [['raw' => 'four']],
			'empty part' => [['raw' => 'empty']],
			'empty string' => [['raw' => 'nothing']],
			'verifier secret too short' => [['verifierSecret' => 'short']],
			'verifier secret empty' => [['verifierSecret' => '']],
		];

	}//end cases()

	/**
	 * Verify a case's assertion and compare the verdict to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerifyMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$reasons = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context = []) use (&$reasons): void {
				$reasons[] = [$message, $context];
			}
		);

		$verifier = new PortalAssertionVerifier(logger: $logger, secretOverride: ($case['verifierSecret'] ?? self::SECRET));
		$claims = $verifier->verify($this->token(case: $case));
		$actual = ['accepted' => ($claims !== null), 'sub' => ($claims['sub'] ?? null), 'log' => $reasons];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testVerifyMatchesTheRecording()

	/**
	 * The assertion of a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The token.
	 */
	private function token(array $case): string {
		$now = time();
		$claims = ['sub' => 'subject-1', 'use' => 'assertion', 'iat' => 0, 'exp' => 60, 'iss' => 'portaliq'];
		foreach (($case['claims'] ?? []) as $key => $value) {
			$claims[$key] = $value;
		}

		foreach (['iat', 'exp'] as $time) {
			if (is_int($claims[$time]) === true) {
				$claims[$time] += $now;
			}
		}

		$claims = array_filter($claims, static fn ($value): bool => $value !== null);
		$header = array_filter(array_merge(['alg' => 'HS256', 'typ' => 'JWT'], ($case['header'] ?? [])), static fn ($value): bool => $value !== null);
		$h = $this->enc(bytes: (string)json_encode($header));
		$c = $this->enc(bytes: (string)json_encode($claims));

		return match ($case['raw'] ?? null) {
			'header' => $this->signed(head: $this->enc(bytes: 'not json'), body: $c, secret: self::SECRET),
			'claims' => $this->signed(head: $h, body: $this->enc(bytes: '{broken'), secret: self::SECRET),
			'scalar' => $this->signed(head: $h, body: $this->enc(bytes: '42'), secret: self::SECRET),
			'signature' => $this->signed(head: $h, body: $c, secret: self::SECRET) . 'x',
			'swapped' => $h . '.' . $this->enc(bytes: (string)json_encode(['sub' => 'subject-2'] + $claims)) . '.' . explode('.', $this->signed(head: $h, body: $c, secret: self::SECRET))[2],
			'two' => $h . '.' . $c,
			'four' => $this->signed(head: $h, body: $c, secret: self::SECRET) . '.x',
			'empty' => $h . '..' . $c,
			'nothing' => '',
			default => $this->signed(head: $h, body: $c, secret: ($case['secret'] ?? self::SECRET)),
		};

	}//end token()

	/**
	 * A header and body with their HS256 signature appended.
	 *
	 * @param string $head The encoded header.
	 * @param string $body The encoded claims.
	 * @param string $secret The signing secret.
	 *
	 * @return string The token.
	 */
	private function signed(string $head, string $body, string $secret): string {
		return $head . '.' . $body . '.' . $this->enc(bytes: hash_hmac('sha256', $head . '.' . $body, $secret, true));
	}//end signed()

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string The encoding.
	 */
	private function enc(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end enc()
}//end class
