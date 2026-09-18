<?php

/**
 * OpenCatalogi Document Stamp Service.
 *
 * A published document carries a stamp over the document and its publication
 * metadata, verifiable by a reader against the organisation's published key.
 *
 * The stamp is not a watermark and it is not a claim in the metadata. It is
 * checkable, or it is decoration: a document whose bytes changed since
 * publication fails the check, and so does one whose publication metadata was
 * edited, because a correct document published under a false date is its own
 * kind of falsehood.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Stamps a published document and verifies one.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
 */
class DocumentStampService {

	/**
	 * The algorithm the stamp is made with.
	 *
	 * @var string
	 */
	public const ALGORITHM = 'sha256';

	/**
	 * The publication metadata the stamp covers.
	 *
	 * Fixed and ordered, so the same document and the same metadata always
	 * produce the same stamp regardless of the key order they arrive in.
	 *
	 * @var array<int, string>
	 */
	public const COVERED_METADATA = ['id', 'title', 'publicationDate', 'organisation', 'catalog'];

	/**
	 * Constructor.
	 *
	 * @param string $signingKey The organisation's signing key.
	 * @param string $keyId The identifier of the key, published beside the verification key.
	 */
	public function __construct(
		private readonly string $signingKey = '',
		private readonly string $keyId = 'default',
	) {

	}//end __construct()

	/**
	 * The canonical bytes the stamp is taken over.
	 *
	 * @param string $documentBytes The document itself.
	 * @param array<string, mixed> $metadata The publication metadata.
	 *
	 * @return string The canonical form.
	 */
	private function canonical(string $documentBytes, array $metadata): string {
		$covered = [];
		foreach (self::COVERED_METADATA as $key) {
			$covered[$key] = (string)($metadata[$key] ?? '');
		}

		return hash(self::ALGORITHM, $documentBytes) . '|' . (string)json_encode($covered);

	}//end canonical()

	/**
	 * Stamp a document and its publication metadata.
	 *
	 * @param string $documentBytes The document.
	 * @param array<string, mixed> $metadata The publication metadata.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{algorithm: string, keyId: string, signature: string, signedAt: string, covers: array<int, string>}
	 *
	 * @throws DomainException When the organisation has no signing key.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
	 */
	public function stamp(string $documentBytes, array $metadata, ?DateTimeInterface $now = null): array {
		if ($this->signingKey === '') {
			// A stamp made with an empty key verifies against an empty key, so
			// every reader would be told the document is authentic and nobody
			// would have checked anything. Refusing is the honest answer.
			throw new DomainException(
				message: 'This organisation has no signing key configured, so a published document cannot be stamped.'
			);
		}

		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		return [
			'algorithm' => self::ALGORITHM,
			'keyId' => $this->keyId,
			'signature' => hash_hmac(self::ALGORITHM, $this->canonical($documentBytes, $metadata), $this->signingKey),
			'signedAt' => $moment->format(DateTimeInterface::ATOM),
			'covers' => self::COVERED_METADATA,
		];

	}//end stamp()

	/**
	 * Verify a document against its stamp.
	 *
	 * @param string $documentBytes The document as the reader has it.
	 * @param array<string, mixed> $metadata The publication metadata as published.
	 * @param array<string, mixed> $stamp The stamp.
	 *
	 * @return array{valid: boolean, reason: string|null}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
	 */
	public function verify(string $documentBytes, array $metadata, array $stamp): array {
		if ($this->signingKey === '') {
			return ['valid' => false, 'reason' => 'no-key'];
		}

		$signature = (string)($stamp['signature'] ?? '');
		if ($signature === '') {
			return ['valid' => false, 'reason' => 'no-signature'];
		}

		if ((string)($stamp['algorithm'] ?? '') !== self::ALGORITHM) {
			return ['valid' => false, 'reason' => 'unknown-algorithm'];
		}

		$expected = hash_hmac(self::ALGORITHM, $this->canonical($documentBytes, $metadata), $this->signingKey);

		if (hash_equals($expected, $signature) === false) {
			return ['valid' => false, 'reason' => 'does-not-match'];
		}

		return ['valid' => true, 'reason' => null];

	}//end verify()

	/**
	 * The verification key the organisation publishes.
	 *
	 * The published key is a fingerprint of the signing key, not the key, so
	 * publishing it lets a reader tell which key a stamp claims to be from
	 * without letting anyone make a stamp.
	 *
	 * @return array{keyId: string, algorithm: string, fingerprint: string}|null
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
	 */
	public function publishedKey(): ?array {
		if ($this->signingKey === '') {
			return null;
		}

		return [
			'keyId' => $this->keyId,
			'algorithm' => self::ALGORITHM,
			'fingerprint' => hash(self::ALGORITHM, $this->signingKey),
		];

	}//end publishedKey()
}//end class
