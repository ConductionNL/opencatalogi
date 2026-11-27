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

use OCA\OpenCatalogi\Contract\PublisherFileStampInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the publisher's RSIN, name and TOOI identifier onto an attached file.
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 */
class PublisherFileStamp implements PublisherFileStampInterface {
	use ReadsObjectProperties;

	/**
	 * OpenRegister's file metadata change, named as a string so this class loads without OpenRegister.
	 *
	 * @var string
	 */
	private const METADATA_CHANGE = 'OCA\OpenRegister\Service\File\FileMetadataChange';

	/**
	 * The publication property that holds the publisher's RSIN.
	 *
	 * @var string
	 */
	public const PROPERTY = 'publisherRsin';

	/**
	 * The label prefix that carries a valid RSIN.
	 *
	 * @var string
	 */
	private const RSIN_LABEL = 'rsin:';

	/**
	 * Constructor.
	 *
	 * @param PublisherIdentity       $identity  Resolves the publisher of a publication.
	 * @param LoggerInterface|null    $logger    Says why the RSIN could not be stored on the publication.
	 * @param ContainerInterface|null $container Resolves OpenRegister's file and object services for an upload.
	 */
	public function __construct(
		private readonly PublisherIdentity $identity,
		private readonly ?LoggerInterface $logger=null,
		private readonly ?ContainerInterface $container=null,
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
	 * Stamp a file uploaded to a publication, the same way an attached batch
	 * document is: the labels on the file, the RSIN on the publication. The
	 * publication is read past RBAC, as the batch path writes it.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param int    $fileId        The uploaded file.
	 * @param string $register      The publication register.
	 * @param string $schema        The publication schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 */
	public function stampUploaded(string $publicationId, int $fileId, string $register, string $schema): void {
		try {
			$objects = $this->container?->get('OCA\OpenRegister\Service\ObjectService');
			$files = $this->container?->get('OCA\OpenRegister\Service\FileService');
			if (is_object($objects) === false || is_object($files) === false) {
				return;
			}

			$publication = $objects->find(
				id: $publicationId,
				options: new ObjectFindOptions(register: $register, schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
			$labels = $this->stamp(files: $files, publication: $publication, fileId: $fileId);
			if ($labels !== null) {
				$this->record(objects: $objects, publicationId: $publicationId, labels: $labels, register: $register, schema: $schema);
			}
		} catch (Throwable $e) {
			$this->logger?->warning(
				'[PublisherFileStamp] The publisher was not stamped on file {file} of publication {id}: {error}',
				['file' => $fileId, 'id' => $publicationId, 'error' => $e->getMessage()]
			);
		}
	}//end stampUploaded()

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

	/**
	 * The valid RSIN the labels carry, or null when they stamp none.
	 *
	 * @param array<int, string> $labels The labels `labels()` gave.
	 *
	 * @return string|null The RSIN.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2b
	 */
	public static function rsinOf(array $labels): ?string {
		return self::valueOf(labels: $labels, prefix: self::RSIN_LABEL);
	}//end rsinOf()

	/**
	 * The publisher's name the labels carry, only beside a valid RSIN.
	 *
	 * @param array<int, string> $labels The labels `labels()` gave.
	 *
	 * @return string|null The name.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public static function publisherOf(array $labels): ?string {
		if (self::rsinOf(labels: $labels) === null) {
			return null;
		}

		return self::valueOf(labels: $labels, prefix: 'publisherName:');
	}//end publisherOf()

	/**
	 * The value of the first label with a prefix, or null when there is none or it is empty.
	 *
	 * @param array<int, string> $labels The labels.
	 * @param string             $prefix The prefix, with its colon.
	 *
	 * @return string|null The value.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2b
	 */
	private static function valueOf(array $labels, string $prefix): ?string {
		foreach ($labels as $label) {
			if (str_starts_with($label, $prefix) === true) {
				$value = substr($label, strlen($prefix));
				if ($value === '') {
					return null;
				}

				return $value;
			}
		}

		return null;
	}//end valueOf()

	/**
	 * Store the stamped RSIN on the publication as `publisherRsin`.
	 *
	 * Reads the publication fresh, so the write carries its current state and
	 * never an earlier copy, and writes only when the value differs: the first
	 * attached document stores it, the next ones find it there. Labels without
	 * a valid RSIN store nothing, so an RSIN that fails the 11-proef never
	 * reaches the publication. A failing read or write is logged and answers
	 * null; the document is already attached and the DiWoo validator still
	 * lists a publication without its RSIN.
	 *
	 * @param object             $objects       OpenRegister's ObjectService.
	 * @param string             $publicationId The publication's uuid.
	 * @param array<int, string> $labels        The labels written onto the file.
	 * @param string             $register      The publication register.
	 * @param string             $schema        The publication schema.
	 *
	 * @return string|null The RSIN the publication holds after the call, or null when none was stored.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2b
	 */
	public function record(object $objects, string $publicationId, array $labels, string $register, string $schema): ?string {
		$rsin = self::rsinOf(labels: $labels);
		if ($rsin === null || $publicationId === '') {
			return null;
		}

		try {
			$properties = $this->asArray(
				object: $objects->find(
					id: $publicationId,
					options: new ObjectFindOptions(register: $register, schema: $schema),
					_rbac: false,
					_multitenancy: false
				)
			);
			if ($properties === []) {
				return null;
			}

			if (($properties[self::PROPERTY] ?? null) === $rsin) {
				return $rsin;
			}

			unset($properties['id'], $properties['@self']);
			$properties[self::PROPERTY] = $rsin;
			$objects->saveObject(
				object: $properties,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: $schema),
					uuid: $publicationId
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'[PublisherFileStamp] The RSIN was not stored on publication {id}: {error}',
				['id' => $publicationId, 'error' => $e->getMessage()]
			);

			return null;
		}//end try

		return $rsin;
	}//end record()
}//end class
