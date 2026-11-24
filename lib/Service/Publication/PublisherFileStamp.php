<?php
/**
 * Publisher file stamp
 *
 * Writes the publisher's identity onto a document attached to a publication,
 * as labels in OpenRegister's file metadata: `rsin:<rsin>`,
 * `publisherName:<name>` and `publisherTooi:<tooi>` when the RSIN passes the
 * 11-proef, and otherwise `rsinStatus:missing` and `rsinReason:<reason>` with
 * nothing stamped. Labels are the one open field of OpenRegister's file
 * metadata (it holds description, category and labels), the same place a
 * withdrawal is recorded (`PublicationTransitions::recordFileWithdrawal()`).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Service\ReadsObjectProperties;

/**
 * Stamps the publisher's RSIN, name and TOOI identifier onto an attached file.
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 */
class PublisherFileStamp {
	use ReadsObjectProperties;

	/**
	 * OpenRegister's file metadata change, named as a string so this class loads without OpenRegister.
	 *
	 * @var string
	 */
	private const METADATA_CHANGE = 'OCA\OpenRegister\Service\File\FileMetadataChange';

	/**
	 * Constructor.
	 *
	 * @param PublisherIdentity $identity Resolves the publisher of a publication.
	 */
	public function __construct(
		private readonly PublisherIdentity $identity,
	) {

	}//end __construct()

	/**
	 * Write the publisher's labels onto a file attached to this publication.
	 *
	 * Writes nothing when OpenRegister's file service cannot change file
	 * metadata. The file is one just attached, so it has no labels to keep.
	 *
	 * @param object $files       OpenRegister's FileService.
	 * @param mixed  $publication The publication: its entity, or its properties.
	 * @param int    $fileId      The attached file.
	 *
	 * @return array<int, string>|null The labels written, or null when nothing could be written.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	public function stamp(object $files, mixed $publication, int $fileId): ?array {
		$change = self::METADATA_CHANGE;
		if (method_exists($files, 'changeFileMetadata') === false || class_exists($change) === false) {
			return null;
		}

		$labels = self::labels(identity: $this->identity->of(publication: $this->asArray(object: $publication)));
		$files->changeFileMetadata(new $change(fileId: $fileId, labels: $labels));

		return $labels;
	}//end stamp()

	/**
	 * The labels for a publisher's identity.
	 *
	 * A valid RSIN gives `rsin:`, and `publisherName:` and `publisherTooi:` for
	 * whichever of the two is known. Anything else stamps no RSIN and gives
	 * `rsinStatus:missing` with the reason.
	 *
	 * @param array{rsin: ?string, name: ?string, tooi: ?string, rsinStatus: string, reason: ?string} $identity The identity.
	 *
	 * @return array<int, string> The labels.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	public static function labels(array $identity): array {
		$rsin = ($identity['rsin'] ?? null);
		if ($identity['rsinStatus'] !== PublisherIdentity::STATUS_VALID || is_string($rsin) === false || $rsin === '') {
			return [
				'rsinStatus:' . PublisherIdentity::STATUS_MISSING,
				'rsinReason:' . (string) ($identity['reason'] ?? ''),
			];
		}

		$labels = ['rsin:' . $rsin];
		if (($identity['name'] ?? null) !== null) {
			$labels[] = 'publisherName:' . $identity['name'];
		}

		if (($identity['tooi'] ?? null) !== null) {
			$labels[] = 'publisherTooi:' . $identity['tooi'];
		}

		return $labels;
	}//end labels()
}//end class
