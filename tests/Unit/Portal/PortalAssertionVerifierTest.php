<?php

/**
 * Tests for the X-Portal-Subject assertion verifier.
 *
 * Tokens are minted the way portaliq's PortalJwtService::createAssertion()
 * mints them: header {alg: HS256, typ: JWT}, claims sub, audience,
 * organisation, trust, jti, use, iat, exp, iss, HMAC-SHA256 over the
 * base64url segments.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace Unit\Portal;

use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAssertionVerifier.
 */
class PortalAssertionVerifierTest extends TestCase {

	private const SECRET = 'a-dedicated-secret-of-enough-length';

	/**
	 * Mint an assertion as portaliq does, with overrides.
	 *
	 * @param array<string, mixed> $claims Claims to override or add.
	 * @param array<string, mixed> $header Header to override.
	 * @param string               $secret The signing secret.
	 *
	 * @return string
	 */
	public static function mint(array $claims=[], array $header=[], string $secret=self::SECRET): string {
		$now = time();
		$claims = array_merge(
			[
				'sub' => 'subject-1',
				'audience' => 'citizen',
				'organisation' => '',
				'trust' => 'substantial',
				'jti' => 'jti-1',
				'use' => 'assertion',
				'iat' => $now,
				'exp' => ($now + 60),
				'iss' => 'portaliq',
			],
			$claims
		);
		$header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $header);
		$enc = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$h = $enc((string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$c = $enc((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		return $h.'.'.$c.'.'.$enc(hash_hmac('sha256', $h.'.'.$c, $secret, true));
	}

	private function verifier(): PortalAssertionVerifier {
		return new PortalAssertionVerifier(secretOverride: self::SECRET);
	}

	public function testAnAssertionMintedByPortaliqIsAccepted(): void {
		$claims = $this->verifier()->verify(self::mint());
		$this->assertNotNull($claims);
		$this->assertSame('subject-1', $claims['sub']);
		$this->assertSame('subject-1', $this->verifier()->subjectOf(self::mint()));
	}

	public function testTheSecretIsReadFromPortaliqsAppConfig(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('portaliq', 'jwt_signing_secret', '')->willReturn(self::SECRET);
		$this->assertSame('subject-1', (new PortalAssertionVerifier(appConfig: $config))->subjectOf(self::mint()));
	}

	public function testWithoutASecretNothingIsAccepted(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('short');
		$this->assertNull((new PortalAssertionVerifier(appConfig: $config))->subjectOf(self::mint(secret: 'short')));
	}

	public function testAnotherSecretIsRefused(): void {
		$this->assertNull($this->verifier()->verify(self::mint(secret: 'some-other-secret-long-enough')));
	}

	public function testAnExpiredAssertionIsRefused(): void {
		$this->assertNull($this->verifier()->verify(self::mint(['iat' => (time() - 120), 'exp' => (time() - 60)])));
	}

	public function testASessionTokenIsRefused(): void {
		$this->assertNull($this->verifier()->verify(self::mint(['use' => null])));
	}

	public function testAnotherIssuerIsRefused(): void {
		$this->assertNull($this->verifier()->verify(self::mint(['iss' => 'someone'])));
	}

	public function testAlgorithmNoneIsRefused(): void {
		$token = self::mint(header: ['alg' => 'none']);
		$this->assertNull($this->verifier()->verify($token));
		[$h, $c] = explode('.', $token);
		$this->assertNull($this->verifier()->verify($h.'.'.$c.'.'));
	}

	public function testAnEmptySubjectIsRefused(): void {
		$this->assertNull($this->verifier()->verify(self::mint(['sub' => ''])));
	}

	public function testAMissingHeaderIsNoSubject(): void {
		$this->assertNull($this->verifier()->subjectOf(null));
		$this->assertNull($this->verifier()->subjectOf(''));
		$this->assertNull($this->verifier()->subjectOf('not.a.token'));
	}
}
