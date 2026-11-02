<?php

/**
 * OpenCatalogi Portal Assertion Verifier.
 *
 * Verifies portaliq's `X-Portal-Subject` assertion, the only identity a
 * portal-forwarded request to opencatalogi carries (portaliq contract v2, A6).
 * Structure and posture follow the fleet's reference verifier (petstore,
 * filinq): HS256 only, constant-time signature check, `use: assertion`,
 * `iss: portaliq`, a live `exp`, a plausible `iat` and a non-empty `sub`.
 *
 * The secret is portaliq's dedicated `jwt_signing_secret`, read from portaliq's
 * app config on this same instance. portaliq refuses to mint without one of at
 * least 16 characters and has no fallback, so this verifier has none either.
 * No portaliq class is imported: without portaliq nothing is ever verified.
 *
 * @category Portal
 * @package  OCA\OpenCatalogi\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Portal;

use OCA\OpenCatalogi\Contract\PortalAssertionVerifierInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies the `X-Portal-Subject` HS256 assertion, fail-closed.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */
class PortalAssertionVerifier implements PortalAssertionVerifierInterface {


	/**
	 * The only accepted JWS algorithm.
	 */
	private const ALG = 'HS256';

	/**
	 * The minting edge.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim of an assertion. A portal session token has none.
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * The minimum secret length portaliq mints with.
	 */
	private const MIN_SECRET_LENGTH = 16;

	/**
	 * Tolerated clock skew on `iat`, in seconds.
	 */
	private const IAT_LEEWAY = 60;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null      $appConfig      Where portaliq keeps its secret.
	 * @param LoggerInterface|null $logger         Rejection reasons, debug level only.
	 * @param string|null          $secretOverride A plain secret for tests.
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig=null,
		private readonly ?LoggerInterface $logger=null,
		private readonly ?string $secretOverride=null,
	) {

	}//end __construct()

	/**
	 * The verified claims of an assertion, or null.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null The claims when every check passes.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function verify(string $jwt): ?array {
		$secret = $this->secret();
		if ($secret === null) {
			return $this->reject(reason: 'no usable signing secret');
		}

		$claims = $this->signedClaims(jwt: $jwt, secret: $secret);
		if (is_string($claims) === true) {
			return $this->reject(reason: $claims);
		}

		$reason = $this->claimsRejection(claims: $claims);
		if ($reason !== null) {
			return $this->reject(reason: $reason);
		}

		return $claims;

	}//end verify()

	/**
	 * The claims of a well-formed HS256 assertion whose signature holds, or why not.
	 *
	 * @param string $jwt The raw header value.
	 * @param string $secret The signing secret.
	 *
	 * @return array<string, mixed>|string The decoded claims, or the rejection reason.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function signedClaims(string $jwt, string $secret): array|string {
		$parts = explode('.', $jwt);
		$reason = $this->structureRejection(parts: $parts);
		if ($reason !== null) {
			return $reason;
		}

		if ($this->signatureHolds(parts: $parts, secret: $secret) === false) {
			return 'signature mismatch';
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $parts[1]), true);
		if (is_array($claims) === false) {
			return 'malformed claims';
		}

		return $claims;

	}//end signedClaims()

	/**
	 * Why the three parts of an assertion are not a well-formed HS256 token, or null when they are.
	 *
	 * @param array<int, string> $parts The token split on dots.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function structureRejection(array $parts): ?string {
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return 'malformed structure';
		}

		return $this->algorithmRejection(headerPart: $parts[0]);

	}//end structureRejection()

	/**
	 * Why an encoded header does not name HS256, or null when it does.
	 *
	 * @param string $headerPart The encoded header.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function algorithmRejection(string $headerPart): ?string {
		$header = json_decode($this->b64UrlDecode(encoded: $headerPart), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== self::ALG) {
			return 'unexpected algorithm';
		}

		return null;

	}//end algorithmRejection()

	/**
	 * Whether the signature part is the HS256 signature of the header and claims parts.
	 *
	 * Compared in constant time.
	 *
	 * @param array<int, string> $parts The header, claims and signature parts.
	 * @param string $secret The signing secret.
	 *
	 * @return bool True when the signature holds.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function signatureHolds(array $parts, string $secret): bool {
		$expected = $this->b64UrlEncode(bytes: hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true));

		return hash_equals($expected, $parts[2]);

	}//end signatureHolds()

	/**
	 * Why verified claims are not a live portaliq assertion for a subject, or null when they are.
	 *
	 * The checks run in a fixed order, so the first failing one names the reason.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function claimsRejection(array $claims): ?string {
		return ($this->issuerRejection(claims: $claims)
			?? $this->lifetimeRejection(claims: $claims)
			?? $this->subjectRejection(claims: $claims));

	}//end claimsRejection()

	/**
	 * Why the claims are not a portaliq assertion, or null when they are.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function issuerRejection(array $claims): ?string {
		if (($claims['use'] ?? '') !== self::USE_ASSERTION || ($claims['iss'] ?? '') !== self::ISSUER) {
			return 'not a portaliq assertion';
		}

		return null;

	}//end issuerRejection()

	/**
	 * Why the `exp` claim does not describe a live assertion, or the `iat` check's verdict.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function lifetimeRejection(array $claims): ?string {
		$now = time();
		$exp = ($claims['exp'] ?? null);
		if (is_int($exp) === false || $exp <= $now) {
			return 'expired or missing exp';
		}

		return $this->issuedAtRejection(claims: $claims, now: $now);

	}//end lifetimeRejection()

	/**
	 * Why the `iat` claim is implausible, or null: in the future past the leeway, or after `exp`.
	 *
	 * @param array<string, mixed> $claims The decoded claims, with an integer `exp`.
	 * @param int $now The time the lifetime is checked at.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function issuedAtRejection(array $claims, int $now): ?string {
		$iat = ($claims['iat'] ?? null);
		if (is_int($iat) === false || $iat > ($now + self::IAT_LEEWAY) || $iat > $claims['exp']) {
			return 'implausible iat';
		}

		return null;

	}//end issuedAtRejection()

	/**
	 * Why the claims name no subject, or null when they do.
	 *
	 * @param array<string, mixed> $claims The decoded claims.
	 *
	 * @return string|null The rejection reason, or null.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	private function subjectRejection(array $claims): ?string {
		$sub = ($claims['sub'] ?? null);
		if (is_string($sub) === false || $sub === '') {
			return 'missing subject';
		}

		return null;

	}//end subjectRejection()

	/**
	 * The subject reference of a verified assertion, or null.
	 *
	 * @param string|null $header The raw `X-Portal-Subject` header value.
	 *
	 * @return string|null The `sub` claim.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function subjectOf(?string $header): ?string {
		if ($header === null || trim($header) === '') {
			return null;
		}

		$claims = $this->verify(jwt: trim($header));
		if ($claims === null) {
			return null;
		}

		return (string)$claims['sub'];

	}//end subjectOf()

	/**
	 * portaliq's dedicated signing secret, or null when it has none.
	 *
	 * @return string|null
	 */
	private function secret(): ?string {
		$secret = $this->secretOverride;
		if ($secret === null && $this->appConfig !== null) {
			$secret = $this->appConfig->getValueString('portaliq', 'jwt_signing_secret', '');
		}

		if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;

	}//end secret()

	/**
	 * Fail closed, and log why at debug level only.
	 *
	 * @param string $reason The reason.
	 *
	 * @return null
	 */
	private function reject(string $reason): null {
		$this->logger?->debug('OpenCatalogi: portal assertion rejected', ['reason' => $reason]);
		return null;

	}//end reject()

	/**
	 * Base64-url encode without padding, as portaliq encodes.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

	}//end b64UrlEncode()

	/**
	 * Base64-url decode, tolerating missing padding.
	 *
	 * @param string $encoded The encoded segment.
	 *
	 * @return string The raw bytes, or '' when it does not decode.
	 */
	private function b64UrlDecode(string $encoded): string {
		$padded = strtr($encoded, '-_', '+/');
		$padded .= str_repeat('=', ((4 - (strlen($padded) % 4)) % 4));
		$decoded = base64_decode($padded, true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;

	}//end b64UrlDecode()
}//end class
